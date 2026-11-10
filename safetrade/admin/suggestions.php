<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/../includes/suggestions.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$adminId   = (int) $adminUser['id'];
$conn      = db();
sg_ensure($conn);

/* ---------- filters (kept across actions) ---------- */
$catFilter    = in_array($_GET['cat'] ?? '', SG_CATEGORIES, true) ? (string) $_GET['cat'] : '';
$statusFilter = in_array($_GET['status'] ?? '', SG_STATUSES, true) ? (string) $_GET['status'] : '';

function sg_admin_url(string $cat, string $status, string $hash = ''): string
{
    $q = [];
    if ($cat !== '')    $q['cat'] = $cat;
    if ($status !== '') $q['status'] = $status;
    return '/admin/suggestions.php' . ($q ? '?' . http_build_query($q) : '') . $hash;
}

/* =========================================================
   POST actions
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $sid    = (int) ($_POST['suggestion_id'] ?? 0);
    $hash   = $sid > 0 ? '#sg-' . $sid : '';

    try {
        // Load the suggestion once (author + title are needed for notifications).
        $sg = null;
        if ($sid > 0) {
            $st = $conn->prepare('SELECT id, user_id, title, status FROM suggestions WHERE id = ? LIMIT 1');
            $st->bind_param('i', $sid);
            $st->execute();
            $sg = $st->get_result()->fetch_assoc();
            $st->close();
        }

        if ($action === 'set_status' && $sg) {
            $new = in_array($_POST['status'] ?? '', SG_STATUSES, true) ? (string) $_POST['status'] : 'none';
            $u = $conn->prepare('UPDATE suggestions SET status = ? WHERE id = ?');
            $u->bind_param('si', $new, $sid);
            $u->execute();
            $u->close();
            audit_log($adminId, 'admin.suggestion.status', 'suggestion', $sid, 'status=' . $new);

            if ($new !== (string) $sg['status'] && $new !== 'none') {
                inbox_push((int) $sg['user_id'], 'account',
                    $new === 'approved' ? 'Your suggestion was approved 🎉' : 'Your suggestion was reviewed',
                    '“' . mb_substr((string) $sg['title'], 0, 80) . '” was marked ' . $new . ' by the SafeTrade team.',
                    '/dashboard.php?view=suggestions', $sid, 'suggestion_status');
            }
            flash_set('success', 'Marked as ' . (['none' => 'undecided', 'approved' => 'approved', 'denied' => 'denied'][$new]) . '.');

        } elseif ($action === 'admin_like' && $sg) {
            $st = $conn->prepare('SELECT id FROM suggestion_likes WHERE suggestion_id = ? AND user_id = ? LIMIT 1');
            $st->bind_param('ii', $sid, $adminId);
            $st->execute();
            $liked = $st->get_result()->fetch_assoc();
            $st->close();
            if ($liked) {
                $d = $conn->prepare('DELETE FROM suggestion_likes WHERE id = ?');
                $lid = (int) $liked['id'];
                $d->bind_param('i', $lid); $d->execute(); $d->close();
            } else {
                $i = $conn->prepare('INSERT IGNORE INTO suggestion_likes (suggestion_id, user_id) VALUES (?,?)');
                $i->bind_param('ii', $sid, $adminId); $i->execute(); $i->close();
            }

        } elseif ($action === 'admin_comment' && $sg) {
            $body = mb_substr(trim(clean_input($_POST['body'] ?? '')), 0, 600);
            if ($body !== '') {
                $i = $conn->prepare('INSERT INTO suggestion_comments (suggestion_id, user_id, body) VALUES (?,?,?)');
                $i->bind_param('iis', $sid, $adminId, $body);
                $i->execute();
                $i->close();
                audit_log($adminId, 'admin.suggestion.commented', 'suggestion', $sid);
                if ((int) $sg['user_id'] !== $adminId) {
                    inbox_push((int) $sg['user_id'], 'account', 'The team replied to your suggestion',
                        '“' . mb_substr((string) $sg['title'], 0, 80) . '” has a new comment from the SafeTrade team.',
                        '/dashboard.php?view=suggestions', $sid, 'suggestion_comment');
                }
                flash_set('success', 'Comment posted.');
            }

        } elseif ($action === 'delete_suggestion' && $sg) {
            foreach (['suggestion_comments', 'suggestion_likes'] as $t) {
                $d = $conn->prepare("DELETE FROM $t WHERE suggestion_id = ?");
                $d->bind_param('i', $sid); $d->execute(); $d->close();
            }
            $d = $conn->prepare('DELETE FROM suggestions WHERE id = ?');
            $d->bind_param('i', $sid); $d->execute(); $d->close();
            audit_log($adminId, 'admin.suggestion.deleted', 'suggestion', $sid, 'title=' . mb_substr((string) $sg['title'], 0, 80));
            inbox_push((int) $sg['user_id'], 'account', 'Your suggestion was removed',
                '“' . mb_substr((string) $sg['title'], 0, 80) . '” was removed by a moderator.', null, $sid, 'suggestion_removed');
            flash_set('success', 'Suggestion deleted.');
            $hash = '';

        } elseif ($action === 'delete_comment') {
            $cid = (int) ($_POST['comment_id'] ?? 0);
            $d = $conn->prepare('DELETE FROM suggestion_comments WHERE id = ?');
            $d->bind_param('i', $cid); $d->execute(); $d->close();
            audit_log($adminId, 'admin.suggestion.comment_deleted', 'suggestion_comment', $cid);
            flash_set('success', 'Comment deleted.');
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] admin suggestions action failed: ' . $e->getMessage());
        flash_set('error', 'Something went wrong: ' . $e->getMessage());
    }
    redirect(sg_admin_url($catFilter, $statusFilter, $hash));
}

/* =========================================================
   Load data
   ========================================================= */
$counts = ['all' => 0, 'none' => 0, 'approved' => 0, 'denied' => 0];
$cr = $conn->query('SELECT status, COUNT(*) c FROM suggestions GROUP BY status');
while ($r = $cr->fetch_assoc()) {
    $k = in_array($r['status'], SG_STATUSES, true) ? $r['status'] : 'none';
    $counts[$k] += (int) $r['c'];
    $counts['all'] += (int) $r['c'];
}

$where  = [];
$types  = 'i';
$params = [$adminId];
if ($catFilter !== '')    { $where[] = 's.category = ?'; $types .= 's'; $params[] = $catFilter; }
if ($statusFilter !== '') { $where[] = 's.status = ?';   $types .= 's'; $params[] = $statusFilter; }

$sql = 'SELECT s.*, u.first_name, u.last_name, u.email,
               (SELECT COUNT(*) FROM suggestion_likes l WHERE l.suggestion_id = s.id) AS like_count,
               (SELECT COUNT(*) FROM suggestion_likes l WHERE l.suggestion_id = s.id AND l.user_id = ?) AS liked_by_me
        FROM suggestions s LEFT JOIN users u ON u.id = s.user_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY s.created_at DESC, s.id DESC LIMIT 100';
$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute();
$items = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$comments = [];
if ($items) {
    $ids = implode(',', array_map(fn($r) => (int) $r['id'], $items));
    $res = $conn->query(
        "SELECT c.*, u.first_name, u.last_name, u.role FROM suggestion_comments c
         LEFT JOIN users u ON u.id = c.user_id WHERE c.suggestion_id IN ($ids) ORDER BY c.id ASC"
    );
    while ($r = $res->fetch_assoc()) $comments[(int) $r['suggestion_id']][] = $r;
}

$pageTitle = 'Suggestions · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<style>
.sgA-card{background:var(--paper);border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;margin-bottom:14px;scroll-margin-top:20px;}
.sgA-card.st-approved{border-left:5px solid #1F7A4D;}
.sgA-card.st-denied{border-left:5px solid #B3452F;}
.sgA-top{display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap;}
.sgA-top .who{flex:1;min-width:0;font-size:13px;}
.sgA-top .who span{display:block;font-size:11.5px;color:var(--ink-soft);}
.sgA-tag{font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;padding:3px 10px;border-radius:999px;background:var(--brand-light);color:var(--brand-dark);}
.sgA-tag.bug{background:var(--danger-bg);color:#8C331F;}
.sgA-tag.imp{background:var(--gold-light);color:#7A5308;}
.sgA-tag.oth{background:var(--line);color:var(--ink-soft);}
.sgA-badge{font-size:11.5px;font-weight:800;padding:4px 12px;border-radius:999px;}
.sgA-badge.approved{background:var(--brand-light);color:var(--brand-dark);}
.sgA-badge.denied{background:var(--danger-bg);color:#8C331F;}
.sgA-badge.none{background:var(--gold-light);color:#7A5308;}
.sgA-title{font-family:'Fraunces',serif;font-size:17px;font-weight:600;}
.sgA-body{font-size:14px;line-height:1.65;color:var(--ink-soft);margin-top:6px;white-space:pre-line;word-break:break-word;}
.sgA-review{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:14px;padding-top:12px;border-top:1px solid var(--line);}
.sgA-review form{margin:0;}
.sgA-review .lbl{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft);margin-right:2px;}
.sgA-pick{border:1.5px solid var(--line-strong);background:var(--cream);color:var(--ink);border-radius:999px;padding:7px 15px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;transition:all .15s ease;}
.sgA-pick:hover{border-color:var(--brand);color:var(--brand);}
.sgA-pick.approve.on{background:#1F7A4D;border-color:#1F7A4D;color:#fff;}
.sgA-pick.deny.on{background:#B3452F;border-color:#B3452F;color:#fff;}
.sgA-pick.none.on{background:var(--gold);border-color:var(--gold);color:#fff;}
.sgA-like.on{background:var(--brand-light);border-color:var(--brand);color:var(--brand-dark);}
.sgA-comments{margin-top:12px;padding-top:10px;border-top:1px solid var(--line);}
.sgA-comments summary{cursor:pointer;font-size:12.5px;font-weight:700;color:var(--brand);}
.sgA-comment{display:flex;gap:8px;align-items:flex-start;background:var(--cream);border-radius:11px;padding:9px 12px;margin-top:8px;font-size:13px;line-height:1.5;}
.sgA-comment.staff{background:var(--brand-light);}
.sgA-comment .txt{flex:1;min-width:0;word-break:break-word;white-space:pre-line;}
.sgA-team{font-size:9.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;background:var(--brand);color:#fff;padding:2px 7px;border-radius:999px;margin-left:6px;}
.sgA-cform{display:flex;gap:8px;margin-top:10px;}
.sgA-cform input[type=text]{flex:1;border:1.5px solid var(--line-strong);border-radius:999px;padding:10px 15px;background:#fff;font-family:inherit;font-size:13px;}
.sgA-cform input[type=text]:focus{outline:none;border-color:var(--brand);}
.sgA-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.sgA-chips .lbl{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-soft);align-self:center;margin-right:4px;min-width:62px;}
.sgA-chips a.btn-sm.on{background:var(--brand);color:#fff;border-color:var(--brand);}
</style>
<div class="admin-app">
  <?php render_admin_sidebar('suggestions', $adminUser); ?>
  <main class="admin-main">
    <div class="admin-topbar"><div><h1>Suggestions &amp; Feedback</h1><p class="sub">Review ideas and bug reports from members — comment, like, and mark each one approved or denied (<?= count($items) ?> shown).</p></div></div>
    <?php render_flashes(); ?>

    <div class="sgA-chips">
      <span class="lbl">Status</span>
      <?php
        $statusChips = ['' => ['All', $counts['all']], 'none' => ['Undecided', $counts['none']], 'approved' => ['Approved', $counts['approved']], 'denied' => ['Denied', $counts['denied']]];
        foreach ($statusChips as $val => [$label, $n]): ?>
        <a class="btn-sm<?= $statusFilter === $val ? ' on' : '' ?>" href="suggestions.php<?= ($q = array_filter(['cat' => $catFilter, 'status' => $val])) ? '?' . http_build_query($q) : '' ?>"><?= e($label) ?> (<?= (int) $n ?>)</a>
      <?php endforeach; ?>
    </div>
    <div class="sgA-chips" style="margin-bottom:20px;">
      <span class="lbl">Type</span>
      <a class="btn-sm<?= $catFilter === '' ? ' on' : '' ?>" href="suggestions.php<?= $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : '' ?>">All</a>
      <?php foreach (SG_CATEGORIES as $cat): ?>
        <a class="btn-sm<?= $catFilter === $cat ? ' on' : '' ?>" href="suggestions.php?<?= e(http_build_query(array_filter(['cat' => $cat, 'status' => $statusFilter]))) ?>"><?= e($cat) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$items): ?>
      <div class="empty-state" style="background:var(--paper);border-radius:var(--radius);padding:40px;">No suggestions match this view.</div>
    <?php endif; ?>

    <?php foreach ($items as $s):
      $sid   = (int) $s['id'];
      $cm    = $comments[$sid] ?? [];
      $stat  = in_array($s['status'] ?? 'none', SG_STATUSES, true) ? (string) $s['status'] : 'none';
      [$statLabel, $statClass] = sg_status_info($stat);
      $liked = (int) $s['liked_by_me'] > 0;
      $tagClass = $s['category'] === 'Bug report' ? 'bug' : ($s['category'] === 'Improvement' ? 'imp' : ($s['category'] === 'Other' ? 'oth' : '')); ?>
      <div class="sgA-card st-<?= e($statClass) ?>" id="sg-<?= $sid ?>">
        <div class="sgA-top">
          <div class="who">
            <strong><?= e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: 'Deleted user') ?></strong>
            <span><?= e($s['email'] ?? '') ?> · <?= e(date('M j, Y g:i A', strtotime((string) $s['created_at']))) ?> · ❤️ <?= (int) $s['like_count'] ?></span>
          </div>
          <span class="sgA-tag <?= $tagClass ?>"><?= e($s['category']) ?></span>
          <span class="sgA-badge <?= e($statClass) ?>"><?= e($statLabel) ?></span>
          <form method="post" onsubmit="return confirm('Delete this suggestion and all its comments?');" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_suggestion">
            <input type="hidden" name="suggestion_id" value="<?= $sid ?>">
            <button class="btn-sm danger" type="submit">Delete</button>
          </form>
        </div>
        <div class="sgA-title"><?= e($s['title']) ?></div>
        <div class="sgA-body"><?= e($s['body']) ?></div>

        <div class="sgA-review">
          <span class="lbl">Review</span>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status"><input type="hidden" name="suggestion_id" value="<?= $sid ?>">
            <button class="sgA-pick approve<?= $stat === 'approved' ? ' on' : '' ?>" name="status" value="approved" type="submit">✅ Approve</button>
          </form>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status"><input type="hidden" name="suggestion_id" value="<?= $sid ?>">
            <button class="sgA-pick deny<?= $stat === 'denied' ? ' on' : '' ?>" name="status" value="denied" type="submit">❌ Deny</button>
          </form>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="set_status"><input type="hidden" name="suggestion_id" value="<?= $sid ?>">
            <button class="sgA-pick none<?= $stat === 'none' ? ' on' : '' ?>" name="status" value="none" type="submit">⏳ None</button>
          </form>
          <span style="flex:1;"></span>
          <form method="post"><?= csrf_field() ?>
            <input type="hidden" name="action" value="admin_like"><input type="hidden" name="suggestion_id" value="<?= $sid ?>">
            <button class="sgA-pick sgA-like<?= $liked ? ' on' : '' ?>" type="submit"><?= $liked ? '❤️ Liked' : '🤍 Like' ?></button>
          </form>
        </div>

        <details class="sgA-comments" open>
          <summary>💬 <?= count($cm) ?> comment<?= count($cm) === 1 ? '' : 's' ?></summary>
          <?php foreach ($cm as $m):
            $isStaff = in_array((string) ($m['role'] ?? ''), ['admin', 'manager'], true); ?>
            <div class="sgA-comment<?= $isStaff ? ' staff' : '' ?>">
              <div class="txt"><strong><?= e(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: 'Deleted user') ?></strong><?php if ($isStaff): ?><span class="sgA-team">Team</span><?php endif; ?>
                <span style="font-size:11px;color:var(--ink-faint);margin-left:6px;"><?= e(time_ago((string) $m['created_at'])) ?></span><br><?= e($m['body']) ?></div>
              <form method="post" onsubmit="return confirm('Delete this comment?');" style="margin:0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_comment">
                <input type="hidden" name="comment_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="suggestion_id" value="<?= $sid ?>">
                <button class="btn-sm danger" type="submit">✕</button>
              </form>
            </div>
          <?php endforeach; ?>
          <form method="post" class="sgA-cform"><?= csrf_field() ?>
            <input type="hidden" name="action" value="admin_comment">
            <input type="hidden" name="suggestion_id" value="<?= $sid ?>">
            <input type="text" name="body" maxlength="600" placeholder="Reply as the SafeTrade team…" required autocomplete="off">
            <button class="btn-sm" type="submit" style="background:var(--brand);color:#fff;border-color:var(--brand);">Comment</button>
          </form>
        </details>
      </div>
    <?php endforeach; ?>
  </main>
</div>
</body>
</html>
