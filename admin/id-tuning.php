<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/../includes/id_tuning.php';

$adminUser = require_role('admin', 'manager');
$adminId   = (int) $adminUser['id'];
$isAdmin   = ($adminUser['role'] ?? '') === 'admin';   // managers can read, only admins can change thresholds
$conn      = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$isAdmin) {
        flash_set('error', 'Only an admin can change the thresholds.');
        redirect('/admin/id-tuning.php');
    }
    $key = (string) ($_POST['key'] ?? '');
    $err = ($_POST['action'] ?? '') === 'reset'
        ? id_tuning_reset($conn, $adminId, $key)
        : id_tuning_apply($conn, $adminId, $key, (string) ($_POST['value'] ?? ''));
    $err ? flash_set('error', $err) : flash_set('success', 'Saved. New ID submissions use this value straight away.');
    redirect('/admin/id-tuning.php?days=' . (int) ($_POST['days'] ?? 30));
}

$days   = max(1, min(365, (int) ($_GET['days'] ?? 30)));
$rep    = id_tuning_report($conn, $days);
$defs   = id_tuning_defs();
$saved  = id_settings_read($conn);
$cur    = id_tuning_current($conn);
$q      = $rep['quality'] ?? [];
$vol    = $rep['volume'] ?? [];
$fmt    = static fn(float $x): string => rtrim(rtrim(number_format($x, 2), '0'), '.');

$pageTitle = 'ID Tuning · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';

function tn_form(string $action, string $key, string $value, string $label, int $days, string $cls = 'btn-primary'): string
{
    return '<form method="post" style="display:inline;">' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="key" value="' . e($key) . '">'
        . '<input type="hidden" name="value" value="' . e($value) . '"><input type="hidden" name="days" value="' . $days . '">'
        . '<button type="submit" class="' . $cls . '" style="width:auto;padding:6px 12px;font-size:13px;">' . e($label) . '</button></form>';
}
?>
<div class="admin-app">
  <?php render_admin_sidebar('id-tuning', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar"><div><h1>ID screening — tuning</h1>
      <p class="sub">How often the automatic decisions agree with your admins, and what to change. Nothing changes until you press Apply.</p></div></div>
    <?php render_flashes(); ?>

    <?php if (!$rep['ready']): ?>
      <div class="panel"><div class="alert error">Run <code>sql/id_screening_phase4_migration.sql</code> first — the tuning tables do not exist yet.</div></div>
    <?php else: ?>

    <div class="panel">
      <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <label for="days"><strong>Volume window</strong></label>
        <select name="days" id="days" onchange="this.form.submit()">
          <?php foreach ([7, 30, 90, 365] as $d): ?><option value="<?= $d ?>" <?= $d === $days ? 'selected' : '' ?>>last <?= $d ?> days</option><?php endforeach; ?>
        </select>
        <span class="sub">Decisions below are learned from <strong><?= (int) $rep['labels'] ?></strong> admin verdicts (all time).</span>
      </form>
      <?php foreach ($rep['notes'] as $n): ?><div class="alert" style="margin-top:12px;"><?= e($n) ?></div><?php endforeach; ?>
    </div>

    <div class="panel">
      <h2>What the system did (last <?= $days ?> days)</h2>
      <table class="data-table"><tbody>
        <tr><td>Auto-approved</td><td><strong><?= (int) ($vol['pass'] ?? 0) ?></strong></td></tr>
        <tr><td>Sent to an admin</td><td><strong><?= (int) ($vol['admin'] ?? 0) ?></strong></td></tr>
        <tr><td>Auto-declined</td><td><strong><?= (int) ($vol['reject'] ?? 0) ?></strong></td></tr>
        <tr><td>Photo-quality retries (not counted)</td><td><strong><?= (int) ($vol['retry'] ?? 0) ?></strong></td></tr>
      </tbody></table>
    </div>

    <div class="panel">
      <h2>How right was it?</h2>
      <table class="data-table"><tbody>
        <tr><td>Auto-declined, then an admin approved anyway (wrong declines)</td><td><strong><?= (int) $q['reject_reversed'] ?></strong></td></tr>
        <tr><td>Auto-approved, then an admin revoked it (wrong approvals)</td><td><strong><?= (int) $q['pass_revoked'] ?></strong></td></tr>
        <tr><td>Random audits that admins confirmed / found wrong</td><td><strong><?= (int) $q['audit_ok'] ?></strong> / <strong><?= (int) $q['audit_bad'] ?></strong> <span class="sub">(<?= (int) $q['audit_pending'] ?> still waiting for an admin)</span></td></tr>
        <tr><td>Admin queue: approved / declined by admins</td><td><strong><?= (int) $q['queue_approved'] ?></strong> / <strong><?= (int) $q['queue_rejected'] ?></strong></td></tr>
      </tbody></table>
      <p class="sub" style="margin-top:8px;">Auto-declines nobody challenged are not counted as "right" — silence is not a verdict, so the numbers above can only show mistakes that were caught.</p>
    </div>

    <div class="panel">
      <h2>Suggestions</h2>
      <?php if (!$rep['suggestions']): ?>
        <p class="sub">No change suggested right now.</p>
      <?php else: foreach ($rep['suggestions'] as $s): $b = $s['before']; $a = $s['after']; ?>
        <div style="border:1px solid #E3DCCB;border-radius:12px;padding:14px;margin-bottom:12px;">
          <div><strong><?= e($defs[$s['key']][4]) ?></strong>: <?= $fmt($s['from']) ?> → <strong><?= $fmt($s['to']) ?></strong>
            <span class="sub">(<?= $s['direction'] === 'safer' ? 'safer' : 'more automation' ?>)</span></div>
          <div class="sub" style="margin:6px 0;"><?= e($s['why']) ?></div>
          <?php if ($b['n'] > 0): ?>
          <div class="sub">Replaying the <?= (int) $b['n'] ?> past cases that have a face distance — now: <?= (int) $b['false_reject'] ?> wrongly declined, <?= (int) $b['false_pass'] ?> wrongly approvable; with the change: <?= (int) $a['false_reject'] ?> wrongly declined, <?= (int) $a['false_pass'] ?> wrongly approvable, <?= (int) $a['pass_eligible'] ?> approvable.</div>
          <?php endif; ?>
          <?php if ($isAdmin): ?><div style="margin-top:8px;"><?= tn_form('apply', $s['key'], (string) $s['to'], 'Apply ' . $fmt($s['to']), $days) ?></div><?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="panel">
      <h2>Current thresholds</h2>
      <table class="data-table">
        <thead><tr><th>Setting</th><th>Value</th><th>Default</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($defs as $k => $d): ?>
          <tr>
            <td><strong><?= e($d[4]) ?></strong><div class="sub"><?= e($d[5]) ?> (allowed <?= $fmt((float) $d[1]) ?>–<?= $fmt((float) $d[2]) ?>)</div></td>
            <td>
              <?php if ($isAdmin): ?>
              <form method="post" style="display:flex;gap:6px;align-items:center;"><?= csrf_field() ?>
                <input type="hidden" name="action" value="apply"><input type="hidden" name="key" value="<?= e($k) ?>"><input type="hidden" name="days" value="<?= $days ?>">
                <input type="number" name="value" step="<?= $d[3] ?>" min="<?= $d[1] ?>" max="<?= $d[2] ?>" value="<?= e($fmt($cur[$k])) ?>" style="width:90px;">
                <button type="submit" class="btn-primary" style="width:auto;padding:6px 12px;font-size:13px;">Save</button>
              </form>
              <?php else: ?><?= $fmt($cur[$k]) ?><?php endif; ?>
            </td>
            <td><?= $fmt((float) $d[0]) ?></td>
            <td><?php if ($isAdmin && isset($saved[$k])) echo tn_form('reset', $k, '', 'Reset', $days, 'btn-secondary'); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="panel">
      <h2>Face distance of judged cases</h2>
      <?php if (!$rep['face_hist']): ?><p class="sub">No judged case has a face distance yet.</p><?php else: ?>
      <table class="data-table"><thead><tr><th>Distance</th><th>Approved by admin</th><th>Declined by admin</th></tr></thead><tbody>
        <?php foreach ($rep['face_hist'] as $h): ?>
          <tr><td><?= number_format($h['lo'], 2) ?>–<?= number_format($h['lo'] + 0.05, 2) ?></td><td><?= (int) $h['approved'] ?></td><td><?= (int) $h['rejected'] ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
      <p class="sub" style="margin-top:8px;">A good threshold sits where the approved column ends and the declined column begins.</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Authenticity score of judged cases</h2>
      <table class="data-table"><thead><tr><th>Score</th><th>Approved</th><th>Declined</th></tr></thead><tbody>
        <?php foreach ($rep['auth_bands'] as $b): ?><tr><td><?= e($b['range']) ?></td><td><?= (int) $b['approved'] ?></td><td><?= (int) $b['rejected'] ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>

    <div class="panel">
      <h2>Which checks actually predict the admin's decision?</h2>
      <p class="sub">A check that warns on most approved people is noise; one that fails mostly on declined people is useful.</p>
      <?php if (!$rep['checks']): ?><p class="sub">No data yet.</p><?php else: ?>
      <table class="data-table"><thead><tr><th>Check</th><th>Result</th><th>Approved</th><th>Declined</th></tr></thead><tbody>
        <?php foreach ($rep['checks'] as $name => $results): foreach ($results as $res => $v): ?>
          <tr><td><?= e($name) ?></td><td><?= e($res) ?></td><td><?= (int) ($v['approved'] ?? 0) ?></td><td><?= (int) ($v['rejected'] ?? 0) ?></td></tr>
        <?php endforeach; endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
