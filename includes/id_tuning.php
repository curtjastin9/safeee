<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * ID screening — Phase 4: learn from real admin decisions.
 *
 *  1. id_label_record()   called whenever an admin approves / revokes / declines someone: stores a
 *                         PII-free snapshot of what the system saw + what the admin decided.
 *  2. id_tuning_report()  compares system vs admin, builds histograms, per-check usefulness and
 *                         threshold SUGGESTIONS, with a what-if replay of past cases.
 *  3. id_settings_load()  numeric threshold overrides saved by an admin (bounded, whitelisted).
 *
 * Nothing here changes behaviour by itself. Suggestions are only ever applied when an admin clicks
 * Apply. Suggestions for AUTOMATIC REJECTION only ever move toward "safer" (fewer auto-rejects);
 * going the other way is a manual decision. Every change is written to the audit log.
 * ---------------------------------------------------------------------
 */

const ID_TUNING_MIN_LABELS = 30;   // below this many labelled cases with a reliable face, no "more automation" suggestion
const ID_TUNING_MIN_AUDITS = 50;   // audited cases needed before the audit rate may be lowered

/** Whitelist of tunable numbers: [default, min, max, step, label, help]. */
function id_tuning_defs(): array
{
    return [
        'ID_FACE_STRONG_MAX'    => [0.45, 0.30, 0.60, 0.01, 'Face: strong match up to', 'Distance at or below this (with a reliable face) counts as the same person and may be auto-approved. Lower = stricter.'],
        'ID_FACE_BORDER_MAX'    => [0.65, 0.50, 0.75, 0.01, 'Face: borderline up to', 'Between "strong" and this an admin compares the photos. Above it the pair looks like different people.'],
        'ID_FACE_REJECT_MIN'    => [0.80, 0.70, 1.10, 0.01, 'Face: auto-decline from', 'Distance at or above this (with a reliable face) is declined automatically. Higher = fewer automatic declines.'],
        'ID_FACE_MIN_SCORE'     => [0.60, 0.40, 0.90, 0.01, 'Face: min detector confidence', 'A face verdict may only auto-decline when the detector is at least this sure it saw a face.'],
        'ID_FACE_MIN_BOX'       => [70,   40,   200,  1,    'Face: min face width (px)', 'Same, for the size of the face on the ID photo.'],
        'ID_AUTH_SUSPICIOUS_MIN'=> [40,   20,   80,   1,    'Authenticity: warn from score', 'File-forensics score from which the ID is flagged for admin review.'],
        'ID_AUDIT_RATE'         => [0.10, 0.02, 1.00, 0.01, 'Random audit rate', 'Share of would-be approvals an admin double-checks anyway. Cannot go below 2%.'],
    ];
}

/** Are the values (key => number) consistent with each other and within bounds? Returns an error string or null. */
function id_tuning_check_set(array $v): ?string
{
    $defs = id_tuning_defs();
    foreach ($v as $k => $x) {
        if (!isset($defs[$k])) return "Unknown setting $k.";
        if (!is_numeric($x))   return "$k must be a number.";
        if ($x < $defs[$k][1] || $x > $defs[$k][2]) return $defs[$k][4] . ' must be between ' . $defs[$k][1] . ' and ' . $defs[$k][2] . '.';
    }
    $g = static fn(string $k) => (float) ($v[$k] ?? $defs[$k][0]);
    if ($g('ID_FACE_STRONG_MAX') + 0.04 - 1e-9 > $g('ID_FACE_BORDER_MAX')) return 'The strong-match limit must be at least 0.04 below the borderline limit.';
    if ($g('ID_FACE_BORDER_MAX') + 0.04 - 1e-9 > $g('ID_FACE_REJECT_MIN')) return 'The borderline limit must be at least 0.04 below the auto-decline limit.';
    return null;
}

/** The numbers currently in force (DB override > built-in default), as key => float. */
function id_tuning_current(?mysqli $conn = null): array
{
    $saved = $conn ? id_settings_read($conn) : [];
    $out = [];
    foreach (id_tuning_defs() as $k => $d) {
        $out[$k] = defined($k) ? (float) constant($k) : ($saved[$k] ?? (float) $d[0]);
    }
    return $out;
}

/** Saved overrides only: key => float. Empty when the table is missing. */
function id_settings_read(mysqli $conn): array
{
    $out = [];
    try {
        $r = $conn->query('SELECT k, v FROM id_settings');
        while ($r && ($row = $r->fetch_assoc())) {
            if (isset(id_tuning_defs()[$row['k']]) && is_numeric($row['v'])) $out[$row['k']] = (float) $row['v'];
        }
    } catch (Throwable $e) { /* Phase 4 migration not run: no overrides */ }
    return $out;
}

/**
 * Define the saved overrides as constants. Must run BEFORE id_face.php / id_screening.php define their
 * defaults (they use `if (!defined())`), and never replaces a constant already defined by code.
 * The whole set is ignored if it fails the consistency check (a hand-edited bad row can't break screening).
 */
function id_settings_load(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!function_exists('db')) return;
    try {
        $saved = id_settings_read(db());
    } catch (Throwable $e) { return; }
    if (!$saved) return;
    $merged = [];
    foreach (id_tuning_defs() as $k => $d) {
        $merged[$k] = defined($k) ? (float) constant($k) : ($saved[$k] ?? (float) $d[0]);
    }
    if (id_tuning_check_set($merged) !== null) {
        error_log('[SafeTrade] id_settings ignored: inconsistent values');
        return;
    }
    foreach ($saved as $k => $v) {
        if (!defined($k)) define($k, in_array($k, ['ID_FACE_MIN_BOX', 'ID_AUTH_SUSPICIOUS_MIN'], true) ? (int) round($v) : $v);
    }
}

/* ============================ labels ============================ */

/**
 * Remember what an admin decided about this user's latest automatic screening.
 * $verdict: approved | rejected.  $via: verify | revoke | reject_retry | reject_delete.
 * Call BEFORE the account / its screenings are deleted. Never throws.
 */
function id_label_record(mysqli $conn, int $userId, string $verdict, string $via, ?int $adminId): void
{
    if (!in_array($verdict, ['approved', 'rejected'], true)) return;
    try {
        $s = $conn->prepare("SELECT * FROM id_screenings WHERE user_id = ? AND source = 'auto' AND outcome IN ('pass','admin','reject') ORDER BY id DESC LIMIT 1");
        $s->bind_param('i', $userId);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $s->close();
        if (!$row) return;   // registered before screening existed / nothing to learn from

        $checks = json_decode((string) $row['checks_json'], true) ?: [];
        $pairs = [];
        $faceReliable = null;
        foreach ($checks as $c) {
            if (!isset($c['name'], $c['result'])) continue;
            $pairs[] = [(string) $c['name'], (string) $c['result']];
            if ($c['name'] === 'face_match' && in_array($c['result'], ['pass', 'warn', 'fail'], true) && $row['face_distance'] !== null) {
                $faceReliable = (stripos((string) ($c['detail'] ?? ''), 'unreliable') === false
                                 && stripos((string) ($c['detail'] ?? ''), 'poor photo') === false) ? 1 : 0;
            }
        }
        $audit  = (stripos((string) $row['reasons'], 'Random quality audit') !== false) ? 1 : 0;
        $json   = json_encode($pairs, JSON_UNESCAPED_UNICODE);
        $sid    = (int) $row['id'];
        $outc   = (string) $row['outcome'];
        $risk   = (string) $row['risk_level'];
        $face   = $row['face_distance'] !== null ? (float) $row['face_distance'] : null;
        $auth   = $row['authenticity_score'] !== null ? (int) $row['authenticity_score'] : null;
        $type   = $row['detected_id_type'] !== null ? (string) $row['detected_id_type'] : null;

        $st = $conn->prepare(
            'INSERT INTO id_tuning_labels (screening_id, system_outcome, risk_level, was_audit, face_distance, face_reliable, authenticity_score, id_type, checks_json, verdict, verdict_via, decided_by, decided_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), verdict_via = VALUES(verdict_via), decided_by = VALUES(decided_by), decided_at = NOW()'
        );
        $st->bind_param('issidiissssi', $sid, $outc, $risk, $audit, $face, $faceReliable, $auth, $type, $json, $verdict, $via, $adminId);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
        error_log('[SafeTrade] id_label_record failed: ' . $e->getMessage());   // Phase 4 migration not run, or similar
    }
}

/* ============================ analysis ============================ */

/**
 * Replay labelled cases with the FACE rules only, under the given thresholds.
 * @return array{n:int, auto_reject:int, false_reject:int, pass_eligible:int, false_pass:int, to_admin:int}
 */
function id_tuning_simulate(array $labels, array $t): array
{
    $r = ['n' => 0, 'auto_reject' => 0, 'false_reject' => 0, 'pass_eligible' => 0, 'false_pass' => 0, 'to_admin' => 0];
    foreach ($labels as $l) {
        if ($l['face_distance'] === null) continue;
        $d = (float) $l['face_distance'];
        $reliable = $l['face_reliable'] === null || (int) $l['face_reliable'] === 1;
        $r['n']++;
        if ($reliable && $d >= $t['ID_FACE_REJECT_MIN']) {
            $r['auto_reject']++;
            if ($l['verdict'] === 'approved') $r['false_reject']++;
        } elseif ($reliable && $d <= $t['ID_FACE_STRONG_MAX'] && $d >= (defined('ID_FACE_TOO_PERFECT') ? ID_FACE_TOO_PERFECT : 0.08)) {
            $r['pass_eligible']++;
            if ($l['verdict'] === 'rejected') $r['false_pass']++;
        } else {
            $r['to_admin']++;
        }
    }
    return $r;
}

function id_tuning_report(mysqli $conn, int $days = 30): array
{
    $days = max(1, min(365, $days));
    $rep = ['days' => $days, 'ready' => true, 'labels' => 0, 'volume' => [], 'quality' => [], 'face_hist' => [], 'auth_bands' => [], 'checks' => [], 'suggestions' => [], 'notes' => []];
    try {
        $labels = [];
        $q = $conn->query('SELECT * FROM id_tuning_labels');
        while ($q && ($row = $q->fetch_assoc())) $labels[] = $row;
    } catch (Throwable $e) {
        $rep['ready'] = false;
        return $rep;
    }
    $rep['labels'] = count($labels);

    // ---- volume of automatic decisions in the window (from id_screenings) ----
    $vol = ['pass' => 0, 'admin' => 0, 'reject' => 0, 'retry' => 0];
    try {
        $st = $conn->prepare("SELECT outcome, COUNT(*) c FROM id_screenings WHERE source = 'auto' AND created_at >= (NOW() - INTERVAL ? DAY) GROUP BY outcome");
        $st->bind_param('i', $days);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) $vol[$row['outcome']] = (int) $row['c'];
        $st->close();
    } catch (Throwable $e) {}
    $rep['volume'] = $vol;

    // ---- how right was each kind of automatic decision? ----
    $cnt = static function (string $sys, string $verdict, ?int $audit = null) use ($labels): int {
        $n = 0;
        foreach ($labels as $l) {
            if ($l['system_outcome'] === $sys && $l['verdict'] === $verdict && ($audit === null || (int) $l['was_audit'] === $audit)) $n++;
        }
        return $n;
    };
    $rep['quality'] = [
        'reject_reversed'   => $cnt('reject', 'approved'),     // system declined, admin approved (appeal / approve anyway)
        'pass_revoked'      => $cnt('pass', 'rejected'),       // system approved, admin revoked and declined
        'audit_ok'          => $cnt('admin', 'approved', 1),
        'audit_bad'         => $cnt('admin', 'rejected', 1),   // would have been auto-approved, but was wrong
        'queue_approved'    => $cnt('admin', 'approved', 0),
        'queue_rejected'    => $cnt('admin', 'rejected', 0),
    ];
    $rep['quality']['audit_pending'] = 0;
    try {
        $r = $conn->query("SELECT COUNT(*) c FROM id_screenings s WHERE s.source='auto' AND s.outcome='admin' AND s.reasons LIKE 'Random quality audit%' AND NOT EXISTS (SELECT 1 FROM id_tuning_labels t WHERE t.screening_id = s.id)");
        $rep['quality']['audit_pending'] = (int) ($r->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) {}

    // ---- face distance histogram (0.05 buckets) ----
    $hist = [];
    for ($b = 0; $b < 24; $b++) $hist[$b] = ['lo' => $b * 0.05, 'approved' => 0, 'rejected' => 0];
    foreach ($labels as $l) {
        if ($l['face_distance'] === null) continue;
        $b = min(23, (int) floor((float) $l['face_distance'] / 0.05 + 1e-9));
        $hist[$b][$l['verdict']]++;
    }
    $rep['face_hist'] = array_values(array_filter($hist, static fn($h) => $h['approved'] + $h['rejected'] > 0));

    // ---- authenticity bands ----
    $bands = [[0, 19], [20, 39], [40, 59], [60, 79], [80, 94], [95, 100]];
    foreach ($bands as [$lo, $hi]) {
        $a = $rj = 0;
        foreach ($labels as $l) {
            if ($l['authenticity_score'] === null) continue;
            $s = (int) $l['authenticity_score'];
            if ($s >= $lo && $s <= $hi) { $l['verdict'] === 'approved' ? $a++ : $rj++; }
        }
        $rep['auth_bands'][] = ['range' => $lo . '–' . $hi, 'approved' => $a, 'rejected' => $rj];
    }

    // ---- per-check usefulness ----
    $by = [];
    foreach ($labels as $l) {
        foreach ((json_decode((string) $l['checks_json'], true) ?: []) as $p) {
            [$name, $res] = $p;
            $by[$name][$res][$l['verdict']] = ($by[$name][$res][$l['verdict']] ?? 0) + 1;
        }
    }
    ksort($by);
    $rep['checks'] = $by;

    // ---- suggestions ----
    $cur = id_tuning_current($conn);
    $defs = id_tuning_defs();
    $faceRows = array_values(array_filter($labels, static fn($l) => $l['face_distance'] !== null && ($l['face_reliable'] === null || (int) $l['face_reliable'] === 1)));
    $appr = array_map(static fn($l) => (float) $l['face_distance'], array_filter($faceRows, static fn($l) => $l['verdict'] === 'approved'));
    $rejd = array_map(static fn($l) => (float) $l['face_distance'], array_filter($faceRows, static fn($l) => $l['verdict'] === 'rejected'));
    $sug = [];
    $add = static function (string $key, float $to, string $why, string $dir) use (&$sug, $cur, $defs, $labels): void {
        $to = round($to, 2);
        if (abs($to - $cur[$key]) < 0.0099) return;
        $to = max((float) $defs[$key][1], min((float) $defs[$key][2], $to));
        $try = $cur;
        $try[$key] = $to;
        if (id_tuning_check_set($try) !== null) return;
        $sug[] = ['key' => $key, 'from' => $cur[$key], 'to' => $to, 'why' => $why, 'direction' => $dir,
                  'before' => id_tuning_simulate($labels, $cur), 'after' => id_tuning_simulate($labels, $try)];
    };

    // (a) a person the admin DECLINED sits at or under the auto-approve limit -> tighten it (no minimum sample: safety first)
    if ($rejd) {
        $bad = array_filter($rejd, static fn($d) => $d <= $cur['ID_FACE_STRONG_MAX']);
        if ($bad) $add('ID_FACE_STRONG_MAX', floor((min($bad) - 0.02) * 100) / 100,
            count($bad) . ' declined/revoked case(s) had a face distance at or below the auto-approve limit (lowest ' . number_format(min($bad), 3) . ').', 'safer');
    }
    // (b) an admin APPROVED someone the system would auto-decline -> raise the decline line
    if ($appr) {
        $wrong = array_filter($appr, static fn($d) => $d >= $cur['ID_FACE_REJECT_MIN']);
        if ($wrong) $add('ID_FACE_REJECT_MIN', ceil((max($wrong) + 0.05) * 100) / 100,
            count($wrong) . ' person(s) the admin approved were at or above the auto-decline line (highest ' . number_format(max($wrong), 3) . ').', 'safer');
    }
    // (c) enough clean data -> allow MORE automatic approvals, one small step at a time
    if (count($appr) >= ID_TUNING_MIN_LABELS && count($rejd) >= 5 && empty($bad)) {
        $minRej = min($rejd);
        $cand = floor(($minRej - 0.05) * 100) / 100;
        $cand = min($cand, $cur['ID_FACE_STRONG_MAX'] + 0.05);
        $inside = count(array_filter($appr, static fn($d) => $d > $cur['ID_FACE_STRONG_MAX'] && $d <= $cand));
        if ($cand > $cur['ID_FACE_STRONG_MAX'] && $inside >= 5) $add('ID_FACE_STRONG_MAX', $cand,
            "$inside more approved people fall between the current limit and " . number_format($cand, 2) . ', and no declined person is closer than ' . number_format($minRej, 3) . '.', 'more automation');
    }

    // (d) audit rate
    $q = $rep['quality'];
    $audited = $q['audit_ok'] + $q['audit_bad'];
    if ($q['audit_bad'] > 0 && $cur['ID_AUDIT_RATE'] < 0.25) {
        $add('ID_AUDIT_RATE', 0.25, $q['audit_bad'] . ' of ' . $audited . ' audited would-be approvals were wrong — audit more until the checks improve.', 'safer');
    } elseif ($audited >= ID_TUNING_MIN_AUDITS && $q['audit_bad'] === 0 && $cur['ID_AUDIT_RATE'] > 0.05) {
        $add('ID_AUDIT_RATE', 0.05, "All $audited audited approvals were correct — the sample can shrink (never below 2%).", 'more automation');
    }
    $rep['suggestions'] = $sug;
    if ($rep['labels'] < ID_TUNING_MIN_LABELS) {
        $rep['notes'][] = 'Only ' . $rep['labels'] . ' admin decisions recorded so far. The system only suggests making things stricter until there are at least ' . ID_TUNING_MIN_LABELS . ' (and 5 declines) to learn from.';
    }
    $rep['current'] = $cur;
    return $rep;
}

/* ============================ applying ============================ */

/** Save one override (admin clicked Apply / Save). Returns an error message or null. Audit-logged. */
function id_tuning_apply(mysqli $conn, int $adminId, string $key, string $value): ?string
{
    $defs = id_tuning_defs();
    if (!isset($defs[$key])) return 'Unknown setting.';
    if (!is_numeric($value)) return 'Enter a number.';
    $step = $defs[$key][3];
    $v = $step >= 1 ? (float) round((float) $value) : round((float) $value, 2);
    $cur = id_tuning_current($conn);
    $try = $cur;
    $try[$key] = $v;
    if (($err = id_tuning_check_set($try)) !== null) return $err;
    try {
        $s = (string) $v;
        $st = $conn->prepare('INSERT INTO id_settings (k, v, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)');
        $st->bind_param('ssi', $key, $s, $adminId);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
        return 'Could not save (run sql/id_screening_phase4_migration.sql): ' . $e->getMessage();
    }
    audit_log($adminId, 'admin.id_tuning.apply', 'id_settings', null, $key . ': ' . $cur[$key] . ' -> ' . $v);
    return null;
}

/** Remove an override: back to the built-in default. */
function id_tuning_reset(mysqli $conn, int $adminId, string $key): ?string
{
    if (!isset(id_tuning_defs()[$key])) return 'Unknown setting.';
    try {
        $st = $conn->prepare('DELETE FROM id_settings WHERE k = ?');
        $st->bind_param('s', $key);
        $st->execute();
        $st->close();
    } catch (Throwable $e) {
        return 'Could not reset: ' . $e->getMessage();
    }
    audit_log($adminId, 'admin.id_tuning.reset', 'id_settings', null, $key . ' -> default');
    return null;
}
