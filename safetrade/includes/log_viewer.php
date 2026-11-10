<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * SafeTrade Log Viewer — shared engine behind admin/admin-logs.php and
 * admin/user-logs.php.
 *
 * Two views per page:
 *   view=all     BROAD  — every event from the start of tracking until now,
 *                         with stats, filters, date ranges, CSV export.
 *   view=person  SPECIFIC — pick one person and see only what they did
 *                         (plus their trades / listings for members).
 *
 * Data source: audit_logs (+ transactions / products for the per-member
 * panels). The audit_logs column names differ between installs
 * (target_type/target_id/metadata vs entity_type/entity_id/details), so
 * they are detected at runtime instead of assumed.
 * ---------------------------------------------------------------------
 */

const LV_PER_PAGE = 50;

/** key => [label, [action LIKE patterns], badge tone] — first match wins. */
const LV_CATS = [
    'verification' => ['Verification', ['user.face.%', 'user.id_verification.%', 'admin.verification.%', 'admin.user.verify_user', 'admin.user.reject_verification'], 'info'],
    'trade'        => ['Trade & chat', ['user.trade.%', 'user.chat.%'], 'good'],
    'listing'      => ['Listings',     ['user.product.%', 'user.vehicle.%', 'admin.product.%', 'admin.listing.%'], 'warn'],
    'group'        => ['Groups',       ['user.group.%', 'admin.group.%'], 'info'],
    'account'      => ['Account',      ['user.login%', 'user.logout%', 'user.register%', 'user.password%'], ''],
    'suggestion'   => ['Suggestions',  ['user.suggestion.%', 'admin.suggestion.%'], 'warn'],
    'moderation'   => ['Moderation',   ['admin.user.%', 'admin.report.%', 'admin.admin_account.%', 'admin.announcement.%', 'admin.maintenance.%', 'admin.password_changed'], 'bad'],
];

/* ===================== small helpers ===================== */

function lv_get(string $k): string
{
    $v = $_GET[$k] ?? '';
    return is_string($v) ? trim(str_replace("\0", '', $v)) : '';
}

/** audit_logs column names for this install. */
function lv_cols(mysqli $c): array
{
    static $m = null;
    if ($m !== null) return $m;
    $have = [];
    try {
        $r = $c->query('SHOW COLUMNS FROM audit_logs');
        while ($x = $r->fetch_assoc()) $have[$x['Field']] = true;
    } catch (Throwable $e) {
        error_log('[SafeTrade] lv_cols failed: ' . $e->getMessage());
    }
    return $m = [
        'type' => isset($have['target_type']) ? 'target_type' : 'entity_type',
        'id'   => isset($have['target_id'])   ? 'target_id'   : 'entity_id',
        'meta' => isset($have['metadata'])    ? 'metadata'    : 'details',
    ];
}

function lv_all(mysqli $c, string $sql, string $types = '', array $params = []): array
{
    $st = $c->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function lv_one(mysqli $c, string $sql, string $types = '', array $params = []): array
{
    return lv_all($c, $sql, $types, $params)[0] ?? [];
}

/** [key, label, tone] for an action code. */
function lv_category(string $action): array
{
    foreach (LV_CATS as $key => [$label, $patterns, $tone]) {
        foreach ($patterns as $p) {
            if (fnmatch(str_replace('%', '*', $p), $action)) return [$key, $label, $tone];
        }
    }
    if (str_starts_with($action, 'admin.')) return ['moderation', 'Moderation', 'bad'];
    return ['other', 'Other', ''];
}

/** Human wording for an action code. */
function lv_label(string $action): string
{
    static $map = [
        'user.login'                       => 'Logged in',
        'user.login.face'                  => 'Logged in with face',
        'user.logout'                      => 'Logged out',
        'user.register'                    => 'Registered an account',
        'user.register.email_verified'     => 'Verified email (registration)',
        'user.password_reset_otp_requested'=> 'Requested a password reset code',
        'user.password_reset_otp_verified' => 'Entered the correct reset code',
        'user.password_reset_completed'    => 'Reset their password',
        'user.face.registered'             => 'Registered face login',
        'user.face.registered_step'        => 'Passed the face step (registration)',
        'user.id_verification.submitted'   => 'Submitted government ID',
        'user.trade.interest_after_login'  => 'Started a chat after logging in',
        'user.trade.quantity_updated'      => 'Changed quantity to buy',
        'user.trade.meetup_proposed'       => 'Proposed a meet-up',
        'user.trade.meetup_confirmed'      => 'Confirmed a meet-up',
        'user.trade.meetup_rejected'       => 'Declined a meet-up',
        'user.trade.buyer_confirmed'       => 'Confirmed item received',
        'user.trade.seller_confirmed'      => 'Confirmed the sale',
        'user.chat.blocked_word'           => 'Message blocked (language filter)',
        'user.product.posted'              => 'Posted a listing',
        'user.product.photos_added'        => 'Added listing photos',
        'user.vehicle.posted'              => 'Posted a vehicle',
        'user.vehicle.draft_saved'         => 'Saved a vehicle draft',
        'user.group.created'               => 'Created a group',
        'user.group.joined_via_code'       => 'Joined a group with a code',
        'user.group.left'                  => 'Left a group',
        'user.group.kicked'                => 'Removed a member from a group',
        'user.group.deleted'               => 'Deleted a group',
        'user.group.post'                  => 'Posted in a group',
        'user.suggestion.posted'           => 'Posted a suggestion',
        'admin.user.verify_user'           => 'Approved a verification',
        'admin.verification.approve'       => 'Approved a verification',
        'admin.user.reject_verification'   => 'Rejected a verification (account removed)',
        'admin.verification.reject'        => 'Rejected a verification',
        'admin.verification.under_review'  => 'Marked verification for review',
        'admin.user.ban_user'              => 'Banned a user',
        'admin.user.reactivate_user'       => 'Reactivated a user',
        'admin.user.delete_user'           => 'Deleted a user',
        'admin.user.deleted'               => 'Deleted a user account',
        'admin.user.suspend'               => 'Suspended a user',
        'admin.user.ban'                   => 'Banned a user',
        'admin.user.reactivate'            => 'Reactivated a user',
        'admin.user.role_changed'          => 'Changed a user role',
        'admin.product.delete_product'     => 'Removed a listing',
        'admin.product.restore_product'    => 'Restored a listing',
        'admin.report.reviewing'           => 'Started reviewing a report',
        'admin.report.resolve'             => 'Resolved a report',
        'admin.report.dismiss'             => 'Dismissed a report',
        'admin.report.notified_user'       => 'Notified a reported user',
        'admin.group.deleted'              => 'Deleted a group',
        'admin.suggestion.status'          => 'Reviewed a suggestion',
        'admin.suggestion.commented'       => 'Replied to a suggestion',
        'admin.suggestion.deleted'         => 'Deleted a suggestion',
        'admin.suggestion.comment_deleted' => 'Deleted a suggestion comment',
        'admin.announcement.sent'          => 'Sent an announcement',
        'admin.announcement.deleted'       => 'Deleted an announcement',
        'admin.maintenance.done'           => 'Ended maintenance',
        'admin.password_changed'           => 'Changed their own password',
        'admin.admin_account.created'      => 'Created an admin/manager account',
        'admin.admin_account.password_changed' => "Changed an admin's password",
        'admin.admin_account.role_changed' => "Changed an admin's role",
        'admin.admin_account.deactivate_admin' => 'Deactivated an admin account',
        'admin.admin_account.reactivate_admin' => 'Reactivated an admin account',
        'admin.admin_account.deleted'      => 'Deleted an admin account',
    ];
    if (isset($map[$action])) return $map[$action];
    $parts = explode('.', $action);
    array_shift($parts);
    return ucfirst(trim(str_replace('_', ' ', implode(' ', $parts)))) ?: $action;
}

/** Spreadsheet-formula-safe CSV cell. */
function lv_csv(mixed $v): string
{
    $s = (string) ($v ?? '');
    return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
}

/* ===================== filters & SQL ===================== */

function lv_filters(): array
{
    $dateOk = static fn(string $s): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : '';
    $from  = $dateOk(lv_get('from'));
    $to    = $dateOk(lv_get('to'));
    $range = lv_get('range');
    if (!in_array($range, ['today', '7d', '30d', 'all'], true)) $range = 'all';
    if ($from !== '' || $to !== '') $range = 'custom';
    $cat = lv_get('cat');
    if (!isset(LV_CATS[$cat])) $cat = '';
    return [
        'range' => $range, 'from' => $from, 'to' => $to,
        'q'     => mb_substr(lv_get('q'), 0, 80),
        'cat'   => $cat,
        'page'  => max(1, (int) lv_get('page')),
    ];
}

/** Which audit rows belong to this log: admin/manager activity vs member activity. */
function lv_scope_sql(string $kind): string
{
    $adm = "(COALESCE(u.role,'') IN ('admin','manager') OR a.action LIKE 'admin.%')";
    return $kind === 'admin' ? $adm : "NOT $adm";
}

/** @return array{0:string,1:string,2:array} where-sql, bind types, bind params */
function lv_where(string $kind, array $f, array $cols, ?int $uid): array
{
    $w = []; $t = ''; $p = [];

    if ($uid !== null) {
        if ($kind === 'admin') {
            $w[] = 'a.actor_id = ?'; $t .= 'i'; $p[] = $uid;
        } else {
            // a member's own actions + anything an admin did TO that member (ban, verify...)
            $w[] = "(a.actor_id = ? OR (a.`{$cols['type']}` = 'user' AND a.`{$cols['id']}` = ?))";
            $t .= 'is'; $p[] = $uid; $p[] = (string) $uid;
        }
    } else {
        $w[] = lv_scope_sql($kind);
    }

    switch ($f['range']) {
        case 'today': $w[] = 'a.created_at >= CURDATE()'; break;
        case '7d':    $w[] = 'a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'; break;
        case '30d':   $w[] = 'a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'; break;
        case 'custom':
            if ($f['from'] !== '') { $w[] = 'a.created_at >= ?'; $t .= 's'; $p[] = $f['from'] . ' 00:00:00'; }
            if ($f['to']   !== '') { $w[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $t .= 's'; $p[] = $f['to'] . ' 00:00:00'; }
            break;
    }

    if ($f['q'] !== '') {
        $like = '%' . $f['q'] . '%';
        $w[] = "(a.action LIKE ? OR CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) LIKE ? OR u.email LIKE ? OR a.`{$cols['meta']}` LIKE ? OR a.ip_address LIKE ?)";
        $t .= 'sssss';
        array_push($p, $like, $like, $like, $like, $like);
    }

    if ($f['cat'] !== '') {
        $ors = [];
        foreach (LV_CATS[$f['cat']][1] as $pat) { $ors[] = 'a.action LIKE ?'; $t .= 's'; $p[] = $pat; }
        $w[] = '(' . implode(' OR ', $ors) . ')';
    }

    return [implode(' AND ', $w), $t, $p];
}

function lv_select(array $cols): string
{
    return "SELECT a.id, a.action, a.`{$cols['type']}` AS ttype, a.`{$cols['id']}` AS tid, a.`{$cols['meta']}` AS meta,
                   a.ip_address, a.created_at, a.actor_id, u.first_name, u.last_name, u.role, u.email
            FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id";
}

/* ===================== CSV export ===================== */

/** Call BEFORE any HTML is output. Streams a CSV when ?export=1. */
function lv_handle_export(mysqli $c, string $kind): void
{
    if (lv_get('export') !== '1') return;
    $cols = lv_cols($c);
    $f    = lv_filters();
    $uid  = (lv_get('view') === 'person' && (int) lv_get('uid') > 0) ? (int) lv_get('uid') : null;
    [$ws, $t, $p] = lv_where($kind, $f, $cols, $uid);
    $rows = lv_all($c, lv_select($cols) . " WHERE $ws ORDER BY a.created_at DESC, a.id DESC LIMIT 10000", $t, $p);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="safetrade-' . $kind . '-logs-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['When', 'Who', 'Email', 'Role', 'Category', 'Action', 'Action code', 'Target', 'Details', 'IP'], ',', '"', '');
    foreach ($rows as $r) {
        $who = $r['actor_id'] === null ? 'System' : trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        fputcsv($out, array_map('lv_csv', [
            $r['created_at'], $who, $r['email'] ?? '', $r['role'] ?? '',
            lv_category((string) $r['action'])[1], lv_label((string) $r['action']), $r['action'],
            trim(($r['ttype'] ?? '') . ' #' . ($r['tid'] ?? ''), ' #'), $r['meta'] ?? '', $r['ip_address'] ?? '',
        ]), ',', '"', '');
    }
    fclose($out);
    exit;
}

/* ===================== rendering pieces ===================== */

function lv_initials(?string $f, ?string $l): string
{
    return strtoupper(mb_substr((string) $f, 0, 1) . mb_substr((string) $l, 0, 1)) ?: '?';
}

function lv_status_tone(string $s): string
{
    return match ($s) {
        'active' => 'good', 'banned', 'suspended' => 'bad',
        'pending_verification', 'pending_manual_review' => 'warn', default => '',
    };
}

function lv_pager(int $total, int $page, callable $url): void
{
    $pages = max(1, (int) ceil($total / LV_PER_PAGE));
    if ($pages <= 1) return;
    $show = array_unique(array_filter(array_merge([1, $pages], range(max(1, $page - 2), min($pages, $page + 2))), fn($n) => $n >= 1 && $n <= $pages));
    sort($show);
    echo '<div class="ax-pager">';
    if ($page > 1) echo '<a href="' . e($url($page - 1)) . '">‹ Prev</a>';
    $prev = 0;
    foreach ($show as $n) {
        if ($n - $prev > 1) echo '<span class="ax-muted">…</span>';
        echo '<a class="' . ($n === $page ? 'on' : '') . '" href="' . e($url($n)) . '">' . $n . '</a>';
        $prev = $n;
    }
    if ($page < $pages) echo '<a href="' . e($url($page + 1)) . '">Next ›</a>';
    echo '</div>';
}

function lv_timeline(array $rows, bool $showActor): void
{
    echo '<div class="ax-tw"><table class="ax-table"><thead><tr><th>When</th>'
       . ($showActor ? '<th>Who</th>' : '')
       . '<th>Type</th><th>What happened</th><th>Target</th><th>Details</th><th>IP</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="' . ($showActor ? 7 : 6) . '"><div class="ax-empty">' . ax_svg('file', 34)
           . '<b>No activity found</b>Try a wider date range or clear the filters.</div></td></tr>';
    }
    foreach ($rows as $r) {
        [, $catLabel, $tone] = lv_category((string) $r['action']);
        $ts = strtotime((string) $r['created_at']) ?: 0;

        echo '<tr><td style="white-space:nowrap"><b>' . e(date('M j, Y', $ts)) . '</b><div class="ax-muted">'
           . e(date('g:i:s A', $ts)) . ' · ' . e(time_ago((string) $r['created_at'])) . '</div></td>';

        if ($showActor) {
            if ($r['actor_id'] === null) {
                echo '<td><span class="ax-muted">System</span></td>';
            } else {
                $name  = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                $isAdm = in_array((string) ($r['role'] ?? ''), ['admin', 'manager'], true);
                $href  = ($isAdm ? 'admin-logs.php' : 'user-logs.php') . '?view=person&uid=' . (int) $r['actor_id'];
                echo '<td><div class="ax-person"><span class="ax-pav">' . e(lv_initials($r['first_name'] ?? '', $r['last_name'] ?? '')) . '</span><div>'
                   . '<b><a href="' . e($href) . '">' . e($name !== '' ? $name : 'Deleted user #' . (int) $r['actor_id']) . '</a></b>'
                   . '<small>' . e($r['role'] ? ucfirst((string) $r['role']) : '—') . '</small></div></div></td>';
            }
        }

        echo '<td><span class="ax-b ' . $tone . '">' . e($catLabel) . '</span></td>';
        echo '<td><b>' . e(lv_label((string) $r['action'])) . '</b><div class="ax-muted"><span class="ax-code">' . e((string) $r['action']) . '</span></div></td>';

        $tt = (string) ($r['ttype'] ?? ''); $ti = (string) ($r['tid'] ?? '');
        $target = ($tt === '' && ($ti === '' || $ti === '0')) ? '' : trim(ucfirst($tt) . ($ti !== '' && $ti !== '0' ? ' #' . $ti : ''));
        if ($tt === 'user' && ctype_digit($ti) && (int) $ti > 0) {
            echo '<td><a href="user-detail.php?id=' . (int) $ti . '">' . e($target) . '</a></td>';
        } else {
            echo '<td>' . ($target !== '' ? e($target) : '<span class="ax-muted">—</span>') . '</td>';
        }

        $meta = trim((string) ($r['meta'] ?? ''));
        echo '<td style="max-width:260px"><span class="ax-muted" title="' . e($meta) . '">'
           . ($meta !== '' ? e(mb_strlen($meta) > 90 ? mb_substr($meta, 0, 90) . '…' : $meta) : '—') . '</span></td>';
        echo '<td class="ax-muted" style="white-space:nowrap">' . e((string) ($r['ip_address'] ?: '—')) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

/* ===================== the page ===================== */

function lv_render(mysqli $c, string $kind): void
{
    $isAdmin = $kind === 'admin';
    $self    = $isAdmin ? 'admin-logs.php' : 'user-logs.php';
    $who     = $isAdmin ? 'admin' : 'user';
    $title   = $isAdmin ? 'Admin Logs' : 'User Logs';
    $sub     = $isAdmin
        ? 'Everything admins and managers do — approvals, bans, removals, announcements and more.'
        : 'Everything members do — logins, listings, trades, groups and more.';

    $view = lv_get('view') === 'person' ? 'person' : 'all';
    $f    = lv_filters();
    $cols = lv_cols($c);
    $uid  = $view === 'person' && (int) lv_get('uid') > 0 ? (int) lv_get('uid') : null;

    $base = [
        'view'  => $view === 'person' ? 'person' : 'all',
        'uid'   => $uid,
        'range' => in_array($f['range'], ['today', '7d', '30d'], true) ? $f['range'] : '',
        'from'  => $f['from'], 'to' => $f['to'], 'q' => $f['q'], 'cat' => $f['cat'],
    ];
    $url = static function (array $o) use ($self, $base): string {
        $q = array_filter(array_merge($base, $o), static fn($v) => $v !== '' && $v !== null);
        return $self . '?' . http_build_query($q);
    };

    echo '<style>
      .lv-person{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
      .lv-av{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:750;font-size:20px;color:var(--ax-brand);background:var(--ax-brand-l)}
      .lv-pick{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;padding:18px}
      .lv-pick a{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid var(--ax-line);border-radius:14px;background:#fff;transition:all .15s}
      .lv-pick a:hover{border-color:var(--ax-brand);box-shadow:var(--ax-sh-lg);transform:translateY(-2px)}
      .lv-row{display:flex;align-items:center;gap:12px;padding:11px 22px;border-bottom:1px solid var(--ax-line)}
      .lv-row:last-child{border-bottom:0}
      .lv-row .t{flex:1;min-width:0;font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .lv-row .n{font-weight:800;font-size:13px}
      .lv-dates{display:flex;align-items:center;gap:6px}
      .lv-dates .ax-input{width:auto}
    </style>';

    /* ---------- header ---------- */
    $exportUrl = $url(['export' => '1']);
    echo '<header class="ax-head"><div><h1>' . e($title) . '</h1><p>' . e($sub) . '</p></div>'
       . '<div class="ax-actions"><a class="ax-btn" href="' . e($exportUrl) . '">' . ax_svg('file', 16) . ' Export CSV</a></div></header>';

    /* ---------- two mini categories ---------- */
    echo '<div class="ax-chips" style="margin-bottom:20px">'
       . '<a class="ax-chip ' . ($view === 'all' ? 'on' : '') . '" href="' . e($self) . '">' . ax_svg('list', 15) . ' All activity <em>broad</em></a>'
       . '<a class="ax-chip ' . ($view === 'person' ? 'on' : '') . '" href="' . e($self . '?view=person') . '">' . ax_svg('user', 15) . ' Per ' . $who . ' <em>specific</em></a>'
       . '</div>';

    /* ======================================================================
       PERSON VIEW — picker (no uid) or one person's log
       ====================================================================== */
    if ($view === 'person' && $uid === null) {
        $pq      = mb_substr(lv_get('pq'), 0, 60);
        $roleSql = $isAdmin ? "u.role IN ('admin','manager')" : "u.role = 'user'";
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status,
                       (SELECT COUNT(*) FROM audit_logs x WHERE x.actor_id = u.id) AS n,
                       (SELECT MAX(x.created_at) FROM audit_logs x WHERE x.actor_id = u.id) AS last_at
                FROM users u WHERE $roleSql"
             . ($pq !== '' ? " AND (CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.email LIKE ?)" : '')
             . ' ORDER BY (last_at IS NULL) ASC, last_at DESC, u.id DESC LIMIT 40';
        $people = $pq !== '' ? lv_all($c, $sql, 'ss', ['%' . $pq . '%', '%' . $pq . '%']) : lv_all($c, $sql);

        echo '<form class="ax-toolbar" method="get" action="' . e($self) . '"><input type="hidden" name="view" value="person">'
           . '<div class="ax-search">' . ax_svg('search', 16) . '<input class="ax-input" type="search" name="pq" value="' . e($pq) . '" placeholder="Search a ' . $who . ' by name or email…" autofocus></div>'
           . '<button class="ax-btn primary" type="submit">Search</button></form>';

        echo '<div class="ax-card"><div class="ax-card-h"><h3>Choose a ' . $who . ' to see their full log</h3><span class="ax-muted">Most recently active first</span></div>';
        if (!$people) {
            echo '<div class="ax-empty">' . ax_svg('users', 34) . '<b>No ' . $who . 's found</b>Try a different name or email.</div>';
        } else {
            echo '<div class="lv-pick">';
            foreach ($people as $p) {
                echo '<a href="' . e($self . '?view=person&uid=' . (int) $p['id']) . '"><span class="ax-pav">' . e(lv_initials($p['first_name'], $p['last_name'])) . '</span>'
                   . '<div style="min-width:0;flex:1"><b style="display:block">' . e(trim($p['first_name'] . ' ' . $p['last_name'])) . '</b>'
                   . '<span class="ax-muted" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . e($p['email']) . '</span>'
                   . '<span class="ax-muted">' . (int) $p['n'] . ' events · ' . ($p['last_at'] ? e(time_ago((string) $p['last_at'])) : 'no activity yet') . '</span></div>'
                   . '<span class="ax-b ' . lv_status_tone((string) $p['status']) . '">' . e(ucwords(str_replace('_', ' ', (string) $p['status']))) . '</span></a>';
            }
            echo '</div>';
        }
        echo '</div>';
        return;
    }

    $person = null;
    if ($uid !== null) {
        $roleSql = $isAdmin ? "role IN ('admin','manager')" : "role = 'user'";
        $person  = lv_one($c, "SELECT id, first_name, last_name, email, role, status, created_at FROM users WHERE id = ? AND $roleSql LIMIT 1", 'i', [$uid]);
        if (!$person) {
            echo '<div class="ax-alert error">That ' . $who . ' was not found. <a href="' . e($self . '?view=person') . '">Pick another</a>.</div>';
            return;
        }
    }

    /* ---------- shared: filtered query ---------- */
    [$ws, $wt, $wp] = lv_where($kind, $f, $cols, $uid);
    $from = 'FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id';

    $rangeLabels = ['all' => 'All time', 'today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];
    $scopeUid = $uid !== null ? lv_where($kind, ['range' => 'all', 'from' => '', 'to' => '', 'q' => '', 'cat' => '', 'page' => 1], $cols, $uid)
                              : lv_where($kind, ['range' => 'all', 'from' => '', 'to' => '', 'q' => '', 'cat' => '', 'page' => 1], $cols, null);
    [$sw, $st, $sp] = $scopeUid; // same scope, no range/search/category

    $total = (int) (lv_one($c, "SELECT COUNT(*) n $from WHERE $ws", $wt, $wp)['n'] ?? 0);

    /* ---------- person header + KPIs ---------- */
    if ($person) {
        $span = lv_one($c, "SELECT MIN(a.created_at) first_at, MAX(a.created_at) last_at $from WHERE $sw", $st, $sp);
        $lastIp = lv_one($c, 'SELECT ip_address FROM audit_logs WHERE actor_id = ? AND ip_address IS NOT NULL ORDER BY id DESC LIMIT 1', 'i', [$uid])['ip_address'] ?? '—';
        $logins = (int) (lv_one($c, "SELECT COUNT(*) n FROM audit_logs WHERE actor_id = ? AND action IN ('user.login','user.login.face')", 'i', [$uid])['n'] ?? 0);

        echo '<div class="ax-card"><div class="ax-card-b lv-person"><div class="lv-av">' . e(lv_initials($person['first_name'], $person['last_name'])) . '</div>'
           . '<div style="flex:1;min-width:220px"><h2 style="font-size:22px;font-weight:750">' . e(trim($person['first_name'] . ' ' . $person['last_name'])) . '</h2>'
           . '<div class="ax-muted" style="margin-top:4px">' . e($person['email']) . ' · joined ' . e(date('M j, Y', strtotime((string) $person['created_at']))) . '</div>'
           . '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><span class="ax-b ' . lv_status_tone((string) $person['status']) . '">' . e(ucwords(str_replace('_', ' ', (string) $person['status']))) . '</span>'
           . '<span class="ax-b info">' . e(ucfirst((string) $person['role'])) . '</span>'
           . '<span class="ax-muted">Last active: <b>' . (!empty($span['last_at']) ? e(time_ago((string) $span['last_at'])) : 'never') . '</b> · Last IP: <b>' . e((string) $lastIp) . '</b></span></div></div>'
           . '<div class="ax-actions"><a class="ax-btn" href="' . e($self . '?view=person') . '">← All ' . $who . 's</a>'
           . '<a class="ax-btn" href="user-detail.php?id=' . (int) $uid . '">Open profile</a></div></div></div>';

        $k3l = $k3v = $k4l = $k4v = '';
        if ($isAdmin) {
            $k3l = 'Approvals & restores'; $k4l = 'Removals & bans';
            $k3v = (int) (lv_one($c, "SELECT COUNT(*) n FROM audit_logs a WHERE a.actor_id = ? AND a.action REGEXP 'verify_user|approve|reactivate|restore'", 'i', [$uid])['n'] ?? 0);
            $k4v = (int) (lv_one($c, "SELECT COUNT(*) n FROM audit_logs a WHERE a.actor_id = ? AND a.action REGEXP 'reject|ban_user|delete'", 'i', [$uid])['n'] ?? 0);
        } else {
            $k3l = 'Trades'; $k4l = 'Listings';
            try { $k3v = (int) (lv_one($c, 'SELECT COUNT(*) n FROM transactions WHERE buyer_id = ? OR seller_id = ?', 'ii', [$uid, $uid])['n'] ?? 0); } catch (Throwable $e) { $k3v = 0; }
            try { $k4v = (int) (lv_one($c, 'SELECT COUNT(*) n FROM products WHERE seller_id = ? AND is_deleted = 0', 'i', [$uid])['n'] ?? 0); } catch (Throwable $e) { $k4v = 0; }
        }
        echo '<section class="ax-kpis">'
           . '<div class="ax-kpi"><div><div class="l">Events (' . e($f['range'] === 'custom' ? 'custom range' : strtolower($rangeLabels[$f['range']])) . ')</div><div class="v">' . number_format($total) . '</div><div class="s">since ' . (!empty($span['first_at']) ? e(date('M j, Y', strtotime((string) $span['first_at']))) : '—') . '</div></div><div class="ax-ico">' . ax_svg('pulse', 20) . '</div></div>'
           . '<div class="ax-kpi"><div><div class="l">Logins</div><div class="v">' . number_format($logins) . '</div><div class="s">all time</div></div><div class="ax-ico blue">' . ax_svg('user', 20) . '</div></div>'
           . '<div class="ax-kpi"><div><div class="l">' . e($k3l) . '</div><div class="v">' . number_format((int) $k3v) . '</div><div class="s">all time</div></div><div class="ax-ico gold">' . ax_svg($isAdmin ? 'check' : 'swap', 20) . '</div></div>'
           . '<div class="ax-kpi"><div><div class="l">' . e($k4l) . '</div><div class="v">' . number_format((int) $k4v) . '</div><div class="s">all time</div></div><div class="ax-ico red">' . ax_svg($isAdmin ? 'trash' : 'cube', 20) . '</div></div>'
           . '</section>';
    } else {
        /* ---------- broad KPIs + charts ---------- */
        $people = (int) (lv_one($c, "SELECT COUNT(DISTINCT a.actor_id) n $from WHERE $ws", $wt, $wp)['n'] ?? 0);
        $today  = (int) (lv_one($c, "SELECT COUNT(*) n $from WHERE $sw AND a.created_at >= CURDATE()", $st, $sp)['n'] ?? 0);
        $first  = lv_one($c, "SELECT MIN(a.created_at) d $from WHERE $sw", $st, $sp)['d'] ?? null;

        echo '<section class="ax-kpis">'
           . '<div class="ax-kpi"><div><div class="l">Total events</div><div class="v">' . number_format($total) . '</div><div class="s">' . e($f['range'] === 'custom' ? 'custom range' : strtolower($rangeLabels[$f['range']])) . '</div></div><div class="ax-ico">' . ax_svg('pulse', 20) . '</div></div>'
           . '<div class="ax-kpi"><div><div class="l">Active ' . $who . 's</div><div class="v">' . number_format($people) . '</div><div class="s">in this range</div></div><div class="ax-ico blue">' . ax_svg('users', 20) . '</div></div>'
           . '<div class="ax-kpi"><div><div class="l">Events today</div><div class="v">' . number_format($today) . '</div><div class="s">since midnight</div></div><div class="ax-ico gold">' . ax_svg('clock', 20) . '</div></div>'
           . '<div class="ax-kpi"><div><div class="l">Tracking since</div><div class="v" style="font-size:22px">' . ($first ? e(date('M j, Y', strtotime((string) $first))) : '—') . '</div><div class="s">first recorded event</div></div><div class="ax-ico">' . ax_svg('audit', 20) . '</div></div>'
           . '</section>';

        $daily = [];
        foreach (lv_all($c, "SELECT DATE(a.created_at) d, COUNT(*) n $from WHERE $sw AND a.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY d", $st, $sp) as $r) $daily[$r['d']] = (int) $r['n'];
        $days = [];
        for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $days[$d] = $daily[$d] ?? 0; }
        $maxD = max(1, max($days));

        $topActions = lv_all($c, "SELECT a.action, COUNT(*) n $from WHERE $ws GROUP BY a.action ORDER BY n DESC LIMIT 8", $wt, $wp);
        $topPeople  = lv_all($c, "SELECT a.actor_id, u.first_name, u.last_name, COUNT(*) n $from WHERE $ws AND a.actor_id IS NOT NULL GROUP BY a.actor_id, u.first_name, u.last_name ORDER BY n DESC LIMIT 6", $wt, $wp);
        $maxA = max(1, (int) ($topActions[0]['n'] ?? 1));

        echo '<div class="ax-grid main-side"><div class="ax-card"><div class="ax-card-h"><h3>Activity · last 14 days</h3></div><div class="ax-card-b"><div class="ax-bars">';
        foreach ($days as $d => $n) {
            echo '<div class="ax-bar" title="' . e(date('M j', strtotime($d))) . ': ' . $n . ' event' . ($n === 1 ? '' : 's') . '"><b>' . ($n ?: '') . '</b><i style="height:' . ($n ? max(6, (int) round($n / $maxD * 100)) : 3) . '%"></i><span>' . e(date('j', strtotime($d))) . '</span></div>';
        }
        echo '</div></div></div><div class="ax-card"><div class="ax-card-h"><h3>Top ' . $who . 's</h3></div>';
        if (!$topPeople) echo '<div class="ax-empty">No activity in this range.</div>';
        foreach ($topPeople as $tp) {
            $nm = trim(($tp['first_name'] ?? '') . ' ' . ($tp['last_name'] ?? '')) ?: 'Deleted user #' . (int) $tp['actor_id'];
            echo '<div class="lv-row"><span class="ax-pav">' . e(lv_initials($tp['first_name'] ?? '', $tp['last_name'] ?? '')) . '</span>'
               . '<a class="t" href="' . e($self . '?view=person&uid=' . (int) $tp['actor_id']) . '">' . e($nm) . '</a><span class="n">' . number_format((int) $tp['n']) . '</span></div>';
        }
        echo '</div></div>';

        echo '<div class="ax-card"><div class="ax-card-h"><h3>Most common actions</h3></div>';
        if (!$topActions) echo '<div class="ax-empty">No activity in this range.</div>';
        foreach ($topActions as $ta) {
            echo '<div class="lv-row"><span class="t" style="flex:0 0 280px" title="' . e((string) $ta['action']) . '">' . e(lv_label((string) $ta['action'])) . '</span>'
               . '<div class="ax-meter" style="flex:1"><i style="width:' . max(3, (int) round((int) $ta['n'] / $maxA * 100)) . '%"></i></div><span class="n">' . number_format((int) $ta['n']) . '</span></div>';
        }
        echo '</div>';
    }

    /* ---------- range chips + filter form ---------- */
    echo '<div class="ax-chips" style="margin-bottom:12px">';
    foreach ($rangeLabels as $k => $label) {
        $on = $f['range'] === $k;
        echo '<a class="ax-chip ' . ($on ? 'on' : '') . '" href="' . e($url(['range' => $k === 'all' ? '' : $k, 'from' => '', 'to' => '', 'page' => ''])) . '">'
           . e($k === 'all' ? 'All time (since deployment)' : $label) . '</a>';
    }
    echo '</div>';

    $keepRange = in_array($f['range'], ['today', '7d', '30d'], true) ? $f['range'] : '';
    echo '<form class="ax-toolbar" method="get" action="' . e($self) . '"><input type="hidden" name="view" value="' . e($view) . '">'
       . ($uid !== null ? '<input type="hidden" name="uid" value="' . (int) $uid . '">' : '')
       . ($keepRange !== '' ? '<input type="hidden" name="range" value="' . e($keepRange) . '">' : '')
       . '<div class="ax-search">' . ax_svg('search', 16) . '<input class="ax-input" type="search" name="q" value="' . e($f['q']) . '" placeholder="Search action, name, email, IP, details…"></div>'
       . '<select class="ax-select" name="cat"><option value="">All types</option>';
    foreach (LV_CATS as $k => [$label]) echo '<option value="' . e($k) . '"' . ($f['cat'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    echo '</select><div class="lv-dates"><input class="ax-input" type="date" name="from" value="' . e($f['from']) . '" title="From"><span class="ax-muted">to</span><input class="ax-input" type="date" name="to" value="' . e($f['to']) . '" title="To"></div>'
       . '<button class="ax-btn primary" type="submit">Apply</button>'
       . '<a class="ax-btn" href="' . e($self . ($uid !== null ? '?view=person&uid=' . (int) $uid : '')) . '">Reset</a></form>';

    /* ---------- timeline ---------- */
    $page = min($f['page'], max(1, (int) ceil($total / LV_PER_PAGE)));
    $rows = lv_all($c, lv_select($cols) . " WHERE $ws ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?", $wt . 'ii', array_merge($wp, [LV_PER_PAGE, ($page - 1) * LV_PER_PAGE]));

    echo '<div class="ax-card"><div class="ax-card-h"><h3>' . ($person ? 'Activity timeline' : 'All ' . $who . ' activity') . '</h3>'
       . '<span class="ax-muted">' . number_format($total) . ' event' . ($total === 1 ? '' : 's') . ' · page ' . $page . '</span></div>';
    lv_timeline($rows, !$person);
    lv_pager($total, $page, static fn(int $n): string => $url(['page' => $n > 1 ? (string) $n : '']));
    echo '</div>';

    /* ---------- member extras: trades + listings ---------- */
    if ($person && !$isAdmin) {
        try {
            $trades = lv_all($c,
                "SELECT t.id, t.status, t.buyer_id, t.seller_id, t.created_at, t.completed_at, p.title, p.price,
                        CONCAT(bu.first_name,' ',bu.last_name) AS buyer, CONCAT(se.first_name,' ',se.last_name) AS seller
                 FROM transactions t JOIN products p ON p.id = t.product_id
                 JOIN users bu ON bu.id = t.buyer_id JOIN users se ON se.id = t.seller_id
                 WHERE t.buyer_id = ? OR t.seller_id = ? ORDER BY t.created_at DESC LIMIT 100", 'ii', [$uid, $uid]);
        } catch (Throwable $e) { $trades = []; }

        echo '<div class="ax-card"><div class="ax-card-h"><h3>Transactions</h3><span class="ax-muted">' . count($trades) . ' shown (latest 100)</span></div><div class="ax-tw"><table class="ax-table"><thead><tr>'
           . '<th>Item</th><th>Role</th><th>With</th><th>Price</th><th>Status</th><th>Started</th><th>Completed</th></tr></thead><tbody>';
        if (!$trades) echo '<tr><td colspan="7"><div class="ax-empty">No transactions yet.</div></td></tr>';
        foreach ($trades as $t) {
            $buying = (int) $t['buyer_id'] === $uid;
            $tone   = $t['status'] === 'completed' ? 'good' : (in_array($t['status'], ['cancelled', 'disputed'], true) ? 'bad' : 'warn');
            echo '<tr><td><b>' . e((string) $t['title']) . '</b></td><td>' . ($buying ? 'Buyer' : 'Seller') . '</td>'
               . '<td>' . e(trim((string) ($buying ? $t['seller'] : $t['buyer']))) . '</td><td>₱' . number_format((float) $t['price'], 2) . '</td>'
               . '<td><span class="ax-b ' . $tone . '">' . e(ucwords(str_replace('_', ' ', (string) $t['status']))) . '</span></td>'
               . '<td class="ax-muted">' . e(date('M j, Y g:i A', strtotime((string) $t['created_at']))) . '</td>'
               . '<td class="ax-muted">' . ($t['completed_at'] ? e(date('M j, Y g:i A', strtotime((string) $t['completed_at']))) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div></div>';

        try {
            $listings = lv_all($c, 'SELECT id, title, price, is_active, is_deleted, created_at FROM products WHERE seller_id = ? ORDER BY created_at DESC LIMIT 100', 'i', [$uid]);
        } catch (Throwable $e) { $listings = []; }

        echo '<div class="ax-card"><div class="ax-card-h"><h3>Listings</h3><span class="ax-muted">' . count($listings) . ' shown (latest 100)</span></div><div class="ax-tw"><table class="ax-table"><thead><tr>'
           . '<th>Item</th><th>Price</th><th>Status</th><th>Posted</th></tr></thead><tbody>';
        if (!$listings) echo '<tr><td colspan="4"><div class="ax-empty">No listings posted.</div></td></tr>';
        foreach ($listings as $l) {
            [$lbl, $tone] = (int) $l['is_deleted'] ? ['Removed', 'bad'] : ((int) $l['is_active'] ? ['Selling', 'good'] : ['Sold / inactive', 'info']);
            echo '<tr><td><b>' . e((string) $l['title']) . '</b></td><td>₱' . number_format((float) $l['price'], 2) . '</td>'
               . '<td><span class="ax-b ' . $tone . '">' . $lbl . '</span></td><td class="ax-muted">' . e(date('M j, Y g:i A', strtotime((string) $l['created_at']))) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
