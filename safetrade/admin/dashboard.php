<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$adminId   = $adminUser['id'];
$conn      = db();

/* =========================================================
   POST actions (PRG pattern)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $back   = $_POST['back'] ?? 'overview';

    switch ($action) {

        case 'send_announcement': {
            $aTitle = trim(clean_input($_POST['title'] ?? ''));
            $aBody  = trim(clean_input($_POST['body'] ?? ''));
            $aCat   = in_array($_POST['category'] ?? '', ['announcement', 'update', 'maintenance'], true) ? $_POST['category'] : 'announcement';
            $aAud   = in_array($_POST['audience'] ?? '', ['users', 'staff', 'all'], true) ? $_POST['audience'] : 'users';
            if ($aTitle === '' || $aBody === '') {
                flash_set('error', 'Title and message are required.');
                break;
            }
            $aStart = inbox_parse_dt($_POST['starts_at'] ?? null);
            $aEnd   = inbox_parse_dt($_POST['ends_at'] ?? null);
            $sent = inbox_broadcast((int) $adminId, $aCat, $aTitle, $aBody, $aAud, $aStart, $aEnd, !empty($_POST['pin']));
            audit_log($adminId, 'admin.announcement.sent', 'announcement', null, $aCat . ' to ' . $aAud . ' (' . $sent . ' recipients): ' . $aTitle);
            flash_set('success', 'Sent to ' . $sent . ' inbox' . ($sent === 1 ? '' : 'es') . '.');
            break;
        }

        case 'delete_announcement': {
            inbox_delete_announcement((int) ($_POST['id'] ?? 0));
            audit_log($adminId, 'admin.announcement.deleted', 'announcement', (int) ($_POST['id'] ?? 0));
            flash_set('success', 'Announcement removed from everyone\'s inbox.');
            break;
        }

        case 'verify_user': {
            $targetId = (int) ($_POST['user_id'] ?? 0);

            $uStmt = $conn->prepare('SELECT email, first_name FROM users WHERE id = ? LIMIT 1');
            $uStmt->bind_param('i', $targetId);
            $uStmt->execute();
            $verifiedUser = $uStmt->get_result()->fetch_assoc();
            $uStmt->close();

            $stmt = $conn->prepare("UPDATE users SET verification_status='approved', status='active' WHERE id=?");
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.user.verify_user', 'user', $targetId);

            if ($verifiedUser) {
                $emailed = send_branded_email(
                    $verifiedUser['email'],
                    $verifiedUser['first_name'],
                    "You're verified on SafeTrade!",
                    "You're verified!",
                    'Hi ' . e($verifiedUser['first_name']) . ',<br><br>'
                        . 'Good news — your ID and face verification were approved. Your account is now active '
                        . 'and you can start buying and selling on SafeTrade.',
                    app_url('auth/login.php'),
                    'Log in to SafeTrade'
                );
                flash_set(
                    $emailed ? 'success' : 'error',
                    $emailed
                        ? 'Verification updated — notification email sent to ' . $verifiedUser['email'] . '.'
                        : 'Verification updated, but the notification email FAILED: ' . smtp_last_error()
                );
            } else {
                flash_set('success', 'Verification updated.');
            }
            break;
        }

        case 'reject_verification': {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $rejectReason = trim(clean_input($_POST['reason'] ?? ''));

            $uStmt = $conn->prepare('SELECT email, first_name FROM users WHERE id = ? LIMIT 1');
            $uStmt->bind_param('i', $targetId);
            $uStmt->execute();
            $rejectedUser  = $uStmt->get_result()->fetch_assoc();
            $rejectedEmail = $rejectedUser['email'] ?? '';
            $uStmt->close();

            $conn->begin_transaction();
            try {
                try {
                    $anon = $conn->prepare('UPDATE audit_logs SET actor_id = NULL WHERE actor_id = ?');
                    $anon->bind_param('i', $targetId);
                    $anon->execute();
                    $anon->close();
                } catch (Throwable $e) {
                }

                foreach (['user_sessions', 'password_resets', 'notifications'] as $table) {
                    try {
                        $del = $conn->prepare("DELETE FROM $table WHERE user_id = ?");
                        $del->bind_param('i', $targetId);
                        $del->execute();
                        $del->close();
                    } catch (Throwable $e) {
                    }
                }

                // FIX: this loop used to run unguarded, unlike the
                // user_sessions/password_resets/notifications loop right
                // above it. identity_verifications doesn't exist in this
                // schema (see the verif_detail handler below, which
                // already switched to reading users.verification_status
                // directly for the same reason) — so prepare() threw
                // every time this ran, the exception bubbled up to the
                // outer catch, and the whole transaction rolled back with
                // "Could not remove this account: Table
                // 'safetrade.identity_verifications' doesn't exist",
                // leaving the account undeleted. Wrapping each delete the
                // same way the loop above it already is lets the reject
                // action skip tables that don't exist in this install
                // instead of aborting the entire cleanup.
                foreach (['government_ids', 'face_verifications', 'identity_verifications'] as $table) {
                    try {
                        $del = $conn->prepare("DELETE FROM $table WHERE user_id = ?");
                        $del->bind_param('i', $targetId);
                        $del->execute();
                        $del->close();
                    } catch (Throwable $e) {
                        // table doesn't exist in this schema — nothing to clean up here.
                    }
                }
                $del = $conn->prepare('DELETE FROM users WHERE id = ?');
                $del->bind_param('i', $targetId);
                $del->execute();
                $del->close();

                $conn->commit();
                audit_log(
                    $adminId,
                    'admin.user.reject_verification',
                    'user',
                    $targetId,
                    'deleted account, email=' . $rejectedEmail . ', reason=' . $rejectReason
                );

                $emailed = false;
                if ($rejectedEmail !== '') {
                    $emailed = send_branded_email(
                        $rejectedEmail,
                        $rejectedUser['first_name'] ?? '',
                        'Your SafeTrade registration',
                        "We couldn't verify your registration",
                        'Hi ' . e($rejectedUser['first_name'] ?? '') . ',<br><br>'
                            . "Unfortunately we weren't able to approve your ID/face verification, so your "
                            . "SafeTrade registration has been removed."
                            . ($rejectReason !== '' ? '<br><br><strong>Reason given:</strong> ' . nl2br(e($rejectReason)) : '')
                            . '<br><br>You\'re welcome to sign up again with a clearer photo of your government ID.',
                        app_url('auth/register.php'),
                        'Register again'
                    );
                }
                flash_set(
                    $emailed ? 'success' : 'error',
                    $emailed
                        ? 'Registration rejected and removed — notification email sent to ' . $rejectedEmail . '.'
                        : 'Registration rejected and removed, but the notification email FAILED: ' . smtp_last_error()
                );
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[SafeTrade] reject_verification delete failed: ' . $e->getMessage());
                flash_set('error', 'Could not remove this account: ' . $e->getMessage());
            }
            break;
        }

        case 'ban_user':
        case 'reactivate_user': {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            if ($targetId === $adminId) {
                flash_set('error', "You can't act on your own account.");
                break;
            }

            $banReason = trim(clean_input($_POST['reason'] ?? ''));

            $uStmt = $conn->prepare('SELECT email, first_name FROM users WHERE id = ? LIMIT 1');
            $uStmt->bind_param('i', $targetId);
            $uStmt->execute();
            $affectedUser = $uStmt->get_result()->fetch_assoc();
            $uStmt->close();

            if ($action === 'reactivate_user') {
                $stmt = $conn->prepare("UPDATE users SET status='active', verification_status='approved' WHERE id=?");
                $stmt->bind_param('i', $targetId);
            } else {
                $stmt = $conn->prepare("UPDATE users SET status='banned' WHERE id=?");
                $stmt->bind_param('i', $targetId);
            }
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.user.' . $action, 'user', $targetId, $banReason !== '' ? 'reason=' . $banReason : null);

            $emailed = false;
            if ($affectedUser) {
                if ($action === 'ban_user') {
                    $emailed = send_branded_email(
                        $affectedUser['email'],
                        $affectedUser['first_name'],
                        'Your SafeTrade account has been banned',
                        'Account banned',
                        'Hi ' . e($affectedUser['first_name']) . ',<br><br>'
                            . 'Your SafeTrade account has been banned for violating our community guidelines.'
                            . ($banReason !== '' ? '<br><br><strong>Reason given:</strong> ' . nl2br(e($banReason)) : '')
                            . '<br><br>This action is final unless you successfully appeal with support.'
                    );
                } else {
                    $emailed = send_branded_email(
                        $affectedUser['email'],
                        $affectedUser['first_name'],
                        'Your SafeTrade account is active again',
                        'Welcome back!',
                        'Hi ' . e($affectedUser['first_name']) . ',<br><br>'
                            . 'Your SafeTrade account has been reactivated and you can log in again right away.',
                        app_url('auth/login.php'),
                        'Log in to SafeTrade'
                    );
                }
            }
            flash_set(
                $emailed ? 'success' : 'error',
                $emailed
                    ? 'User status updated — notification email sent' . ($affectedUser ? ' to ' . $affectedUser['email'] : '') . '.'
                    : 'User status updated, but the notification email FAILED: ' . smtp_last_error()
            );
            break;
        }

        case 'delete_user': {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            if ($targetId === $adminId) {
                flash_set('error', "You can't delete your own account.");
                break;
            }
            $deleteReason = trim(clean_input($_POST['reason'] ?? ''));

            $uStmt = $conn->prepare('SELECT email, first_name FROM users WHERE id = ? LIMIT 1');
            $uStmt->bind_param('i', $targetId);
            $uStmt->execute();
            $deletedUser  = $uStmt->get_result()->fetch_assoc();
            $deletedEmail = $deletedUser['email'] ?? '';
            $uStmt->close();

            $conn->begin_transaction();
            try {
                try {
                    $anon = $conn->prepare('UPDATE audit_logs SET actor_id = NULL WHERE actor_id = ?');
                    $anon->bind_param('i', $targetId);
                    $anon->execute();
                    $anon->close();
                } catch (Throwable $e) {
                }

                foreach (['user_sessions', 'password_resets', 'notifications', 'login_attempts'] as $table) {
                    try {
                        $del = $conn->prepare("DELETE FROM $table WHERE user_id = ?");
                        $del->bind_param('i', $targetId);
                        $del->execute();
                        $del->close();
                    } catch (Throwable $e) {
                    }
                }

                $txStmt = $conn->prepare('SELECT id FROM transactions WHERE buyer_id=? OR seller_id=?');
                $txStmt->bind_param('ii', $targetId, $targetId);
                $txStmt->execute();
                $txRes = $txStmt->get_result();
                while ($txRow = $txRes->fetch_assoc()) {
                    $delMsg = $conn->prepare('DELETE FROM messages WHERE transaction_id=?');
                    $delMsg->bind_param('i', $txRow['id']);
                    $delMsg->execute();
                    $delMsg->close();
                }
                $txStmt->close();

                // FIX: PHP array literals can't have duplicate keys. The old
                // code had 'feedback' => ... and 'transactions' => ... each
                // listed twice, so the second occurrence silently overwrote
                // the first — feedback.from_user_id and transactions.buyer_id
                // rows never got deleted, which is exactly what threw the
                // "Cannot delete or update a parent row" FK error seen in the
                // admin panel. A plain list of [table, column] pairs allows
                // the same table to appear more than once.
                $multiColumnDeletes = [
                    ['feedback', 'from_user_id'],
                    ['feedback', 'to_user_id'],
                    ['transactions', 'buyer_id'],
                    ['transactions', 'seller_id'],
                    ['messages', 'sender_id'],
                    ['products', 'seller_id'],
                    ['group_members', 'user_id'],
                    ['government_ids', 'user_id'],
                    ['face_verifications', 'user_id'],
                    ['identity_verifications', 'user_id'],
                    ['verifications', 'user_id'],
                    ['reports', 'reporter_id'],
                    ['reports', 'reported_user_id'],
                ];
                foreach ($multiColumnDeletes as [$table, $col]) {
                    try {
                        $del = $conn->prepare("DELETE FROM $table WHERE $col = ?");
                        $del->bind_param('i', $targetId);
                        $del->execute();
                        $del->close();
                    } catch (Throwable $e) {
                    }
                }

                $del = $conn->prepare('DELETE FROM users WHERE id = ?');
                $del->bind_param('i', $targetId);
                $del->execute();
                $del->close();

                $conn->commit();
                audit_log($adminId, 'admin.user.deleted', 'user', $targetId, 'email=' . $deletedEmail . ($deleteReason !== '' ? ', reason=' . $deleteReason : ''));

                $emailed = false;
                if ($deletedEmail !== '') {
                    $emailed = send_branded_email(
                        $deletedEmail,
                        $deletedUser['first_name'] ?? '',
                        'Your SafeTrade account has been deleted',
                        'Account deleted',
                        'Hi ' . e($deletedUser['first_name'] ?? '') . ',<br><br>'
                            . 'Your SafeTrade account and all associated data have been permanently deleted by a moderator.'
                            . ($deleteReason !== '' ? '<br><br><strong>Reason given:</strong> ' . nl2br(e($deleteReason)) : '')
                    );
                }
                flash_set(
                    $emailed ? 'success' : 'error',
                    $emailed
                        ? 'Account permanently deleted — notification email sent to ' . $deletedEmail . '.'
                        : 'Account permanently deleted, but the notification email FAILED: ' . smtp_last_error()
                );
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('[SafeTrade] delete_user failed: ' . $e->getMessage());
                flash_set('error', 'Could not delete this account: ' . $e->getMessage());
            }
            break;
        }

        case 'delete_product':
        case 'restore_product': {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $isDelete  = $action === 'delete_product';
            $reason    = trim(clean_input($_POST['reason'] ?? ''));
            if ($isDelete && mb_strlen($reason) < 3) {
                flash_set('error', 'Please write a reason before removing a listing — the seller will see it.');
                break;
            }
            $isDeleted = $isDelete ? 1 : 0;
            $isActive  = $isDelete ? 0 : 1;
            $stmt = $conn->prepare('UPDATE products SET is_deleted=?, is_active=? WHERE id=?');
            $stmt->bind_param('iii', $isDeleted, $isActive, $productId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.product.' . $action, 'product', $productId, $isDelete ? ('Reason: ' . $reason) : null);

            if ($isDelete) {
                // Tell the seller exactly what was removed and why.
                $notified = false;
                try {
                    $ps = $conn->prepare('SELECT p.seller_id, p.title, u.email, u.first_name FROM products p JOIN users u ON u.id = p.seller_id WHERE p.id = ? LIMIT 1');
                    $ps->bind_param('i', $productId);
                    $ps->execute();
                    $prod = $ps->get_result()->fetch_assoc();
                    $ps->close();
                    if ($prod) {
                        // Inbox notification to the seller
                        inbox_push(
                            (int) $prod['seller_id'], 'account',
                            'Removed your “' . $prod['title'] . '”',
                            "Reason\n" . $reason,
                            '/dashboard.php?view=my-listings', $productId, 'listing_removed'
                        );
                        $notified = true;
                    }
                } catch (Throwable $e) {
                    error_log('[SafeTrade] product removal notice failed: ' . $e->getMessage());
                }
                flash_set($notified ? 'success' : 'error', $notified
                    ? 'Listing removed. The seller was notified in their inbox with your reason.'
                    : 'Listing removed, but the seller could not be notified — check the PHP error log.');
            } else {
                inbox_product_moderated((int) $productId, $action);
                flash_set('success', 'Listing restored.');
            }
            break;
        }

        case 'report_action': {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $sub = $_POST['sub_action'] ?? '';
            $newStatus = match ($sub) {
                'reviewing' => 'reviewing',
                'resolve'   => 'resolved',
                'dismiss'   => 'dismissed',
                default     => null,
            };
            if ($newStatus) {
                $stmt = $conn->prepare(
                    "UPDATE reports SET status=?, resolved_at=IF(? IN ('resolved','dismissed'), NOW(), resolved_at), handled_by_admin_id=? WHERE id=?"
                );
                $stmt->bind_param('ssii', $newStatus, $newStatus, $adminId, $reportId);
                $stmt->execute();
                $stmt->close();
                audit_log($adminId, 'admin.report.' . $sub, 'report', $reportId);
                flash_set('success', 'Report updated.');
            }
            break;
        }

        case 'notify_reported': {
            $userIdTarget = (int) ($_POST['reported_user_id'] ?? 0);
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $title = 'You were reported';
            $body  = 'A report was filed against your account. A moderator is reviewing it. Please review SafeTrade\'s community guidelines.';
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, title, body) VALUES (?, 'report', ?, ?)");
            $stmt->bind_param('iss', $userIdTarget, $title, $body);
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.report.notified_user', 'user', $userIdTarget, 'report#' . $reportId);
            flash_set('success', 'User notified.');
            break;
        }

        case 'delete_group': {
            $groupId = (int) ($_POST['group_id'] ?? 0);
            $stmt = $conn->prepare('DELETE FROM groups WHERE id=?');
            $stmt->bind_param('i', $groupId);
            $stmt->execute();
            $stmt->close();
            audit_log($adminId, 'admin.group.deleted', 'group', $groupId);
            flash_set('success', 'Group deleted.');
            $back = 'groups';
            break;
        }
    }
    redirect('/admin/dashboard.php?tab=' . $back);
}

/* =========================================================
   Tab data
   ========================================================= */
function count_q(mysqli $conn, string $sql): int
{
    $res = $conn->query($sql);
    return (int) ($res->fetch_assoc()['c'] ?? 0);
}

$tab = $_GET['tab'] ?? 'overview';

$totalUsers    = count_q($conn, "SELECT COUNT(*) c FROM users WHERE role='user'");
$activeUsers   = count_q($conn, "SELECT COUNT(*) c FROM users WHERE role='user' AND status='active'");
$pendingVerifs = count_q($conn, "SELECT COUNT(*) c FROM users WHERE verification_status='pending'");
$openReports   = count_q($conn, "SELECT COUNT(*) c FROM reports WHERE status='open'");
$activeItems   = count_q($conn, "SELECT COUNT(*) c FROM products WHERE is_active=1 AND is_deleted=0");
$totalGroups   = count_q($conn, "SELECT COUNT(*) c FROM groups");
$completedTx   = count_q($conn, "SELECT COUNT(*) c FROM transactions WHERE status='completed'");

$data = [];

if ($tab === 'inbox' || $tab === 'announcements') {
    inbox_ensure();
}

if ($tab === 'overview') {
    $data['recentUsers'] = $conn->query("SELECT id, first_name, last_name, email, status, created_at FROM users WHERE role='user' ORDER BY created_at DESC LIMIT 6");
    $data['recentAudit'] = $conn->query(
        "SELECT a.action, a.target_type, a.target_id, a.created_at, u.first_name, u.last_name
         FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.created_at DESC LIMIT 10"
    );
}

if ($tab === 'users') {
    $q = clean_input($_GET['q'] ?? '');
    $sql = "SELECT id, first_name, last_name, email, status, verification_status, trust_score, created_at FROM users WHERE role='user'";
    if ($q !== '') {
        $like = '%' . $conn->real_escape_string($q) . '%';
        $sql .= " AND (first_name LIKE '$like' OR last_name LIKE '$like' OR email LIKE '$like')";
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 100';
    $data['users'] = $conn->query($sql);
    $data['q'] = $q;
}

if ($tab === 'verifications') {
    $data['pending'] = $conn->query("SELECT id, first_name, last_name, email, phone, status, created_at FROM users WHERE verification_status='pending' ORDER BY created_at ASC");
}

if (isset($_GET['verif_detail'])) {
    header('Content-Type: application/json');
    $uid = (int) $_GET['verif_detail'];
    $out = [];

    // FIX: "Submitted", "IV Status" and "ID Type" always rendered as
    // "—" because this used to query a table called
    // identity_verifications, which doesn't exist anywhere in this
    // installation's schema — the query threw every time, was caught,
    // and silently returned null. There is no separate verification
    // "request" record in this app; the account's own users.status /
    // users.verification_status IS the verification record, so that's
    // what the panel reads now — the exact same source of truth already
    // shown as the "Status" / "Verification" pills elsewhere on this
    // page.
    try {
        $us = $conn->prepare('SELECT verification_status, status, created_at FROM users WHERE id = ? LIMIT 1');
        $us->bind_param('i', $uid); $us->execute();
        $out['user'] = $us->get_result()->fetch_assoc();
        $us->close();
    } catch (Throwable $e) { $out['user'] = null; }

    try {
        $gs = $conn->prepare('SELECT id, id_type, front_mime, back_mime, created_at FROM government_ids WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
        $gs->bind_param('i', $uid); $gs->execute();
        $out['gov_id'] = $gs->get_result()->fetch_assoc();
        $gs->close();
    } catch (Throwable $e) { $out['gov_id'] = null; }

    try {
        $fs = $conn->prepare(
            'SELECT id, selfie_mime, created_at,
                    (embedding IS NOT NULL) AS has_embedding
             FROM face_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $fs->bind_param('i', $uid); $fs->execute();
        $out['face'] = $fs->get_result()->fetch_assoc();
        $fs->close();
    } catch (Throwable $e) { $out['face'] = null; }

    // "Submitted" = when the ID was actually uploaded (that's what
    // sends an account into this review queue). Falls back to the face
    // scan date, then the account's own created_at, so it's never
    // blank even on older/partial rows.
    $out['submitted_at'] = $out['gov_id']['created_at']
        ?? $out['face']['created_at']
        ?? $out['user']['created_at']
        ?? null;

    echo json_encode($out);
    exit;
}

if ($tab === 'products') {
    $statusFilter = in_array($_GET['status'] ?? '', ['all', 'active', 'sold', 'draft', 'deleted'], true) ? $_GET['status'] : 'all';
    $imagesSubProd = "(SELECT GROUP_CONCAT(pi.id ORDER BY pi.id ASC SEPARATOR '|') FROM product_images pi WHERE pi.product_id = p.id) AS images_concat";
    $where = [
        'all'     => 'p.is_deleted=0',
        'active'  => 'p.is_deleted=0 AND p.is_draft=0 AND p.is_active=1',
        'sold'    => 'p.is_deleted=0 AND p.is_draft=0 AND p.is_active=0',
        'draft'   => 'p.is_deleted=0 AND p.is_draft=1',
        'deleted' => 'p.is_deleted=1',
    ][$statusFilter];
    $data['products'] = $conn->query(
        "SELECT p.*, u.first_name, u.last_name,
                COALESCE(pc.name, c.name, '') AS category_name,
                $imagesSubProd
         FROM products p
         JOIN users u ON u.id = p.seller_id
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN categories pc ON pc.id = c.parent_id
         WHERE $where ORDER BY p.created_at DESC LIMIT 200"
    );
    $data['statusFilter'] = $statusFilter;
    $data['prodCounts'] = [
        'all'     => count_q($conn, 'SELECT COUNT(*) c FROM products p WHERE p.is_deleted=0'),
        'active'  => count_q($conn, 'SELECT COUNT(*) c FROM products p WHERE p.is_deleted=0 AND p.is_draft=0 AND p.is_active=1'),
        'sold'    => count_q($conn, 'SELECT COUNT(*) c FROM products p WHERE p.is_deleted=0 AND p.is_draft=0 AND p.is_active=0'),
        'draft'   => count_q($conn, 'SELECT COUNT(*) c FROM products p WHERE p.is_deleted=0 AND p.is_draft=1'),
        'deleted' => count_q($conn, 'SELECT COUNT(*) c FROM products p WHERE p.is_deleted=1'),
    ];
    $data['prodCats'] = [];
    if ($cr = $conn->query('SELECT name FROM categories WHERE parent_id IS NULL ORDER BY name')) {
        while ($c = $cr->fetch_assoc()) $data['prodCats'][] = $c['name'];
    }
}

if ($tab === 'reports') {
    $statusFilter = in_array($_GET['status'] ?? '', ['open', 'reviewing', 'resolved', 'dismissed'], true) ? $_GET['status'] : 'open';
    $stmt = $conn->prepare(
        "SELECT r.*, ru.first_name rfn, ru.last_name rln, tu.id tuid, tu.first_name tfn, tu.last_name tln
         FROM reports r JOIN users ru ON ru.id=r.reporter_id LEFT JOIN users tu ON tu.id=r.reported_user_id
         WHERE r.status=? ORDER BY r.created_at DESC LIMIT 100"
    );
    $stmt->bind_param('s', $statusFilter);
    $stmt->execute();
    $data['reports'] = $stmt->get_result();
    $stmt->close();
    $data['statusFilter'] = $statusFilter;
}

if ($tab === 'groups') {
    $data['groups'] = $conn->query(
        "SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id=g.id AND m.status='approved') member_count,
                u.first_name owner_fn, u.last_name owner_ln
         FROM groups g JOIN users u ON u.id=g.owner_id ORDER BY g.created_at DESC LIMIT 100"
    );
}

if ($tab === 'analytics') {
    // ---- Monthly leaderboard (top sellers + top items sold) ----
    if (!function_exists('mk_month_rows')) {
        function mk_month_rows(mysqli $conn, string $kind, string $ym, int $limit): array {
            $lim = max(1, $limit);
            foreach (['COALESCE(t.quantity,1)', '1'] as $qty) {
                try {
                    if ($kind === 'sellers') {
                        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, COUNT(t.id) AS sales, SUM($qty) AS units, SUM(p.price*$qty) AS revenue
                                FROM transactions t JOIN users u ON u.id=t.seller_id JOIN products p ON p.id=t.product_id
                                WHERE t.status='completed' AND DATE_FORMAT(t.completed_at,'%Y-%m')=?
                                GROUP BY t.seller_id ORDER BY sales DESC, revenue DESC LIMIT $lim";
                    } elseif ($kind === 'buyers') {
                        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, COUNT(t.id) AS purchases, SUM($qty) AS units, SUM(p.price*$qty) AS spent
                                FROM transactions t JOIN users u ON u.id=t.buyer_id JOIN products p ON p.id=t.product_id
                                WHERE t.status='completed' AND DATE_FORMAT(t.completed_at,'%Y-%m')=?
                                GROUP BY t.buyer_id ORDER BY purchases DESC, spent DESC LIMIT $lim";
                    } else {
                        $sql = "SELECT p.id, p.title, p.icon, p.price, u.first_name, u.last_name, SUM($qty) AS units, COUNT(t.id) AS sales, SUM(p.price*$qty) AS revenue
                                FROM transactions t JOIN products p ON p.id=t.product_id JOIN users u ON u.id=p.seller_id
                                WHERE t.status='completed' AND DATE_FORMAT(t.completed_at,'%Y-%m')=?
                                GROUP BY p.id ORDER BY units DESC, revenue DESC LIMIT $lim";
                    }
                    $st = $conn->prepare($sql);
                    $st->bind_param('s', $ym);
                    $st->execute();
                    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                    $st->close();
                    return $rows;
                } catch (Throwable $e) { /* quantity column missing — retry without it */ }
            }
            return [];
        }
    }
    $selYear  = max(2025, min(2028, (int) ($_GET['year'] ?? date('Y'))));
    $selMon   = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
    $selMonth = sprintf('%04d-%02d', $selYear, $selMon);
    $data['mk_year']   = $selYear;
    $data['mk_buyers'] = mk_month_rows($conn, 'buyers', $selMonth, 5);
    $data['mk_month']   = $selMonth;
    $data['mk_sellers'] = mk_month_rows($conn, 'sellers', $selMonth, 5);
    $data['mk_items']   = mk_month_rows($conn, 'items', $selMonth, 5);
    $data['mk_history'] = [];
    for ($m = 1; $m <= 12; $m++) {
        $ym = sprintf('%04d-%02d', $selYear, $m);
        $top  = mk_month_rows($conn, 'sellers', $ym, 1);
        $item = mk_month_rows($conn, 'items', $ym, 1);
        $data['mk_history'][] = ['ym' => $ym, 'seller' => $top[0] ?? null, 'item' => $item[0] ?? null];
    }
    $data['an_total_users']    = count_q($conn, "SELECT COUNT(*) c FROM users WHERE role='user'");
    $data['an_active_users']   = count_q($conn, "SELECT COUNT(*) c FROM users WHERE role='user' AND status='active'");
    $data['an_pending_users']  = count_q($conn, "SELECT COUNT(*) c FROM users WHERE verification_status='pending'");
    $data['an_banned_users']   = count_q($conn, "SELECT COUNT(*) c FROM users WHERE status='banned'");
    $data['an_total_tx']       = count_q($conn, "SELECT COUNT(*) c FROM transactions");
    $data['an_completed_tx']   = count_q($conn, "SELECT COUNT(*) c FROM transactions WHERE status='completed'");
    $data['an_active_tx']      = count_q($conn, "SELECT COUNT(*) c FROM transactions WHERE status NOT IN ('completed','cancelled')");
    $data['an_total_listings'] = count_q($conn, "SELECT COUNT(*) c FROM products WHERE is_deleted=0");
    $data['an_active_listings']= count_q($conn, "SELECT COUNT(*) c FROM products WHERE is_active=1 AND is_deleted=0");
    $data['an_sold_listings']  = count_q($conn, "SELECT COUNT(*) c FROM products WHERE is_active=0 AND is_deleted=0");
    $data['an_total_groups']   = count_q($conn, "SELECT COUNT(*) c FROM groups");
    $data['an_total_members']  = count_q($conn, "SELECT COUNT(*) c FROM group_members WHERE status='approved'");
    $data['an_top_sellers'] = $conn->query(
        "SELECT u.first_name, u.last_name, u.email, COUNT(t.id) AS sales
         FROM transactions t JOIN users u ON u.id=t.seller_id
         WHERE t.status='completed'
         GROUP BY t.seller_id ORDER BY sales DESC LIMIT 5"
    );
    $data['an_top_buyers'] = $conn->query(
        "SELECT u.first_name, u.last_name, u.email, COUNT(t.id) AS purchases
         FROM transactions t JOIN users u ON u.id=t.buyer_id
         WHERE t.status='completed'
         GROUP BY t.buyer_id ORDER BY purchases DESC LIMIT 5"
    );
    $data['an_categories'] = $conn->query(
        "SELECT c.name, c.icon, COUNT(p.id) AS total
         FROM categories c LEFT JOIN products p ON p.category_id=c.id AND p.is_deleted=0
         WHERE c.parent_id IS NULL GROUP BY c.id ORDER BY total DESC"
    );
}

if ($tab === 'monitor') {
    $data['mon_transactions'] = $conn->query(
        "SELECT t.*, p.title AS product_title, p.icon AS product_icon,
                bu.first_name AS buyer_fn, bu.last_name AS buyer_ln,
                se.first_name AS seller_fn, se.last_name AS seller_ln
         FROM transactions t
         JOIN products p ON p.id=t.product_id
         JOIN users bu ON bu.id=t.buyer_id
         JOIN users se ON se.id=t.seller_id
         WHERE t.status NOT IN ('completed','cancelled')
         ORDER BY t.created_at DESC LIMIT 100"
    );
    $data['mon_recent_activity'] = $conn->query(
        "SELECT a.action, a.target_type, a.target_id, a.metadata, a.ip_address, a.created_at,
                u.first_name, u.last_name
         FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id
         ORDER BY a.created_at DESC LIMIT 50"
    );
}

if ($tab === 'gen_reports') {
    $data['gr_users_by_status'] = $conn->query(
        "SELECT status, COUNT(*) c FROM users WHERE role='user' GROUP BY status"
    );
    $data['gr_tx_by_status'] = $conn->query(
        "SELECT status, COUNT(*) c FROM transactions GROUP BY status"
    );
    $data['gr_revenue'] = $conn->query(
        "SELECT DATE_FORMAT(t.completed_at,'%Y-%m') AS month,
                SUM(p.price) AS total, COUNT(*) AS sales
         FROM transactions t JOIN products p ON p.id=t.product_id
         WHERE t.status='completed' AND t.completed_at IS NOT NULL
         GROUP BY month ORDER BY month DESC LIMIT 12"
    );
    $data['gr_feedback'] = $conn->query(
        "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total,
                SUM(rating=5) AS five, SUM(rating=4) AS four,
                SUM(rating=3) AS three, SUM(rating=2) AS two, SUM(rating=1) AS one
         FROM feedback"
    );
}

if ($tab === 'audit') {
    $auditPage   = max(1, (int)($_GET['apage'] ?? 1));
    $auditPerPage = 50;
    $auditOffset  = ($auditPage - 1) * $auditPerPage;
    $auditTotal   = count_q($conn, 'SELECT COUNT(*) c FROM audit_logs');
    $data['audit_total'] = $auditTotal;
    $data['audit_pages'] = (int)ceil($auditTotal / $auditPerPage);
    $data['audit_page']  = $auditPage;
    $stmt = $conn->prepare(
        "SELECT a.action, a.target_type, a.target_id, a.metadata, a.ip_address, a.created_at,
                u.first_name, u.last_name
         FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id
         ORDER BY a.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('ii', $auditPerPage, $auditOffset);
    $stmt->execute();
    $data['audit_logs'] = $stmt->get_result();
    $stmt->close();
}

if ($tab === 'sys_analytics') {
    $data['sys_php']        = phpversion();
    $data['sys_os']         = php_uname('s') . ' ' . php_uname('r');
    $data['sys_memory']     = ini_get('memory_limit');
    $data['sys_upload']     = ini_get('upload_max_filesize');
    $data['sys_extensions'] = implode(', ', array_filter(['gd','mysqli','mbstring','json','curl'], 'extension_loaded'));
    $data['sys_db_size'] = $conn->query(
        "SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS size_mb
         FROM information_schema.tables WHERE table_schema=DATABASE()"
    )->fetch_assoc()['size_mb'] ?? '—';
    $data['sys_row_counts'] = $conn->query(
        "SELECT table_name, table_rows
         FROM information_schema.tables
         WHERE table_schema=DATABASE()
         ORDER BY table_rows DESC"
    );
}

function tab_link(string $key, string $label, string $current, string $iconPath, int $badge = 0): string
{
    $active = $key === $current ? ' active' : '';
    $b = $badge > 0 ? ' <span class="count">' . $badge . '</span>' : '';
    return '<a class="admin-link' . $active . '" href="?tab=' . $key . '">'
         . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . $iconPath . '</svg>'
         . '<span>' . e($label) . '</span>' . $b . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin · SafeTrade</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/app.css">
<link rel="stylesheet" href="../assets/css/admin-ui.css">
<style>
/* ---- Reason modals (Ban / Delete / Reject) ---- */
.reason-modal-backdrop{display:none;position:fixed;inset:0;z-index:9999;background:rgba(22,31,22,0.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:20px;}
.reason-modal-backdrop.show{display:flex;}
.reason-modal{background:#FBF7EF;border-radius:22px;padding:32px 28px;max-width:440px;width:100%;box-shadow:0 24px 60px rgba(9,44,33,.25);}
.reason-modal h3{font-family:'Fraunces',serif;font-size:19px;margin:0 0 8px;color:#161F16;}
.reason-modal p{font-size:13px;color:#5A6252;margin:0 0 16px;line-height:1.6;}
.reason-modal textarea{width:100%;min-height:90px;border-radius:12px;border:1.5px solid rgba(22,31,22,.16);background:#F4EEE1;padding:12px 14px;font-family:inherit;font-size:13.5px;color:#161F16;resize:vertical;box-sizing:border-box;}
.reason-modal textarea:focus{outline:none;border-color:#155943;box-shadow:0 0 0 3px rgba(21,89,67,.15);}
.reason-modal-actions{display:flex;gap:10px;margin-top:18px;}
.reason-modal-actions button{flex:1;padding:12px;border-radius:999px;font-weight:700;font-size:13.5px;border:none;cursor:pointer;}
.reason-modal-cancel{background:transparent;border:1.5px solid rgba(22,31,22,.16) !important;color:#5A6252;}
.reason-modal-confirm{background:#B3452F;color:#fff;}
.reason-modal-confirm.approve{background:#155943;}
.reason-modal-error{color:#B3452F;font-size:12px;margin-top:8px;display:none;}
</style>
</head>
<body class="admin-body">
<div class="admin-app" id="admin-app">
<?php render_admin_sidebar($tab, $adminUser); ?>

  <main class="admin-main">
    <?php foreach (flash_get_all() as $f): ?>
      <div class="ax-alert <?= $f['type'] === 'error' ? 'error' : 'success' ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <?= inbox_maintenance_banner('admin') ?>

    <?php if ($tab === 'inbox'): ?>
      <?php inbox_render_panel('admin'); ?>

    <?php elseif ($tab === 'announcements'): ?>
      <?php inbox_render_announcements_admin(); ?>

    <?php elseif ($tab === 'overview'):
      $ovSignups = [];
      $rs = $conn->query("SELECT DATE(created_at) d, COUNT(*) c FROM users WHERE role='user' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY d");
      if ($rs) while ($r = $rs->fetch_assoc()) $ovSignups[$r['d']] = (int) $r['c'];
      $ovDays = [];
      for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $ovDays[$d] = $ovSignups[$d] ?? 0; }
      $ovMax = max(1, max($ovDays));
      $ovPendingListings = 0;
      $pendSug = count_pending_suggestions();
    ?>
      <header class="ax-head">
        <div><h1>Overview</h1><p>What's happening across SafeTrade right now.</p></div>
        <div class="ax-actions"><a class="ax-btn" href="<?= e(rtrim(BASE_URL, '/')) ?>/dashboard.php">View site</a></div>
      </header>

      <section class="ax-kpis">
        <div class="ax-kpi"><div><div class="l">Total users</div><div class="v"><?= number_format($totalUsers) ?></div><div class="s"><b><?= number_format($activeUsers) ?></b> active</div></div><div class="ax-ico"><?= ax_svg('users', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Active listings</div><div class="v"><?= number_format($activeItems) ?></div><div class="s">live on the marketplace</div></div><div class="ax-ico blue"><?= ax_svg('cube', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Pending verifications</div><div class="v"><?= number_format($pendingVerifs) ?></div><div class="s">awaiting review</div></div><div class="ax-ico gold"><?= ax_svg('verify', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Open reports</div><div class="v"><?= number_format($openReports) ?></div><div class="s">need a decision</div></div><div class="ax-ico red"><?= ax_svg('alert', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Completed trades</div><div class="v"><?= number_format($completedTx) ?></div></div><div class="ax-ico"><?= ax_svg('swap', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Groups</div><div class="v"><?= number_format($totalGroups) ?></div></div><div class="ax-ico blue"><?= ax_svg('group', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Suggestions</div><div class="v"><?= number_format($pendingSug) ?></div><div class="s">pending</div></div><div class="ax-ico gold"><?= ax_svg('bulb', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Environment</div><div class="v" style="font-size:22px;text-transform:capitalize"><?= e(APP_ENV) ?></div></div><div class="ax-ico"><?= ax_svg('server', 20) ?></div></div>
      </section>

      <div class="ax-grid main-side">
        <div class="ax-card">
          <div class="ax-card-h"><h3>New sign-ups · last 14 days</h3></div>
          <div class="ax-card-b">
            <div class="ax-bars">
              <?php foreach ($ovDays as $d => $c): ?>
                <div class="ax-bar" title="<?= e(date('M j', strtotime($d))) ?>: <?= $c ?> sign-up<?= $c === 1 ? '' : 's' ?>"><b><?= $c ?: '' ?></b><i style="height:<?= $c ? max(6, (int) round($c / $ovMax * 100)) : 3 ?>%"></i><span><?= e(date('j', strtotime($d))) ?></span></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="ax-card">
          <div class="ax-card-h"><h3>Needs your attention</h3></div>
          <div style="padding:6px 0">
            <?php foreach ([
              ['?tab=verifications', 'Verifications to review', 'Identity submissions', $pendingVerifs],
              ['?tab=reports', 'Open reports', 'Flagged users and items', $openReports],
              ['suggestions.php', 'New suggestions', 'From members', $pendSug],
              ['?tab=products', 'Moderate listings', 'Review the marketplace', $activeItems],
            ] as $t): ?>
              <a class="ax-todo" href="<?= e($t[0]) ?>"><div class="t"><?= e($t[1]) ?><span><?= e($t[2]) ?></span></div><div class="n<?= $t[3] ? '' : ' zero' ?>"><?= (int) $t[3] ?></div></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="ax-grid c2">
        <div class="ax-card">
          <div class="ax-card-h"><h3>Recently registered users</h3><a class="ax-btn sm" href="?tab=users">View all</a></div>
          <div class="ax-tw"><table class="ax-table"><tbody>
            <?php if ($data['recentUsers']->num_rows === 0): ?><tr><td><div class="ax-empty">No users yet.</div></td></tr><?php endif; ?>
            <?php while ($u = $data['recentUsers']->fetch_assoc()):
              $uName = trim($u['first_name'] . ' ' . $u['last_name']);
              $uCls = ['active' => 'good', 'suspended' => 'bad', 'banned' => 'bad', 'pending_verification' => 'warn', 'pending_manual_review' => 'warn'][$u['status']] ?? 'dark'; ?>
              <tr>
                <td><div class="ax-person"><span class="ax-pav"><?= e(strtoupper(substr($u['first_name'], 0, 1) . substr($u['last_name'], 0, 1))) ?></span><div><b><?= e($uName) ?></b><small><?= e($u['email']) ?></small></div></div></td>
                <td><span class="ax-b <?= $uCls ?>"><?= e(ucwords(str_replace('_', ' ', $u['status']))) ?></span></td>
                <td class="ax-muted"><?= e(time_ago($u['created_at'])) ?></td>
              </tr>
            <?php endwhile; ?>
          </tbody></table></div>
        </div>
        <div class="ax-card">
          <div class="ax-card-h"><h3>Recent activity</h3><a class="ax-btn sm" href="?tab=audit">Audit log</a></div>
          <ul class="ax-feed">
            <?php if ($data['recentAudit']->num_rows === 0): ?><li><div class="ax-empty" style="width:100%">No activity yet.</div></li><?php endif; ?>
            <?php while ($a = $data['recentAudit']->fetch_assoc()): ?>
              <li><span class="dot"></span><div><b><?= e($a['action']) ?></b>
                <span><?= $a['first_name'] ? e($a['first_name'] . ' ' . $a['last_name']) : 'System' ?><?= $a['target_type'] ? ' · ' . e(trim($a['target_type'] . ' #' . $a['target_id'])) : '' ?> · <?= e(time_ago($a['created_at'])) ?></span></div></li>
            <?php endwhile; ?>
          </ul>
        </div>
      </div>


    <?php elseif ($tab === 'users'): ?>
      <div class="admin-topbar"><div><h1>Users</h1><p class="sub">Manage member accounts.</p></div></div>
      <form class="search-row" method="get">
        <input type="hidden" name="tab" value="users">
        <input type="text" name="q" placeholder="Search name or email…" value="<?= e($data['q']) ?>">
        <button type="submit" class="btn-sm">Search</button>
      </form>
      <style>
        .verif-expand-btn{background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:6px;color:var(--ink-soft);font-size:16px;line-height:1;transition:transform .2s ease,color .15s ease;}
        .verif-expand-btn:hover{color:var(--brand);}
        .verif-expand-btn.open{transform:rotate(180deg);color:var(--brand);}
        .verif-detail-row td{padding:0!important;border-bottom:2px solid var(--brand-light);}
        .verif-detail-panel{padding:20px 22px;background:var(--brand-light);border-radius:0 0 12px 12px;display:none;}
        .verif-detail-panel.open{display:block;}
        .verif-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:14px;}
        .verif-doc-box{background:var(--paper);border-radius:12px;padding:14px;}
        .verif-doc-box h4{margin:0 0 10px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);}
        .verif-doc-img{width:100%;height:170px;object-fit:contain;border-radius:8px;background:#fff;border:1px solid var(--line);cursor:pointer;display:block;}
        .verif-score-row{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0;border-bottom:1px solid var(--line);}
        .verif-score-row:last-child{border-bottom:none;}
        .verif-info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px;}
        .verif-info-chip{background:var(--paper);border-radius:10px;padding:10px 13px;}
        .verif-info-chip .lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);}
        .verif-info-chip .val{font-size:13.5px;font-weight:600;margin-top:3px;}
        .verif-loading{padding:20px;text-align:center;color:var(--ink-soft);font-size:13px;}
        #verif-lightbox-users{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;justify-content:center;align-items:center;}
        #verif-lightbox-users.on{display:flex;}
        #verif-lightbox-users img{max-width:90vw;max-height:88vh;border-radius:10px;object-fit:contain;}
        #verif-lightbox-users-close{position:absolute;top:16px;right:20px;font-size:30px;color:#fff;background:none;border:none;cursor:pointer;line-height:1;}
        @media(max-width:700px){.verif-detail-grid,.verif-info-grid{grid-template-columns:1fr;}}
      </style>

      <div class="panel">
        <table class="data-table">
          <thead><tr><th></th><th>User</th><th>Status</th><th>Verification</th><th>Trust</th><th></th></tr></thead>
          <tbody>
          <?php if ($data['users']->num_rows === 0): ?><tr><td colspan="6"><div class="empty-state">No users match.</div></td></tr><?php endif; ?>
          <?php while ($u = $data['users']->fetch_assoc()): ?>
            <tr class="verif-main-row" data-uid="<?= (int)$u['id'] ?>">
              <td style="width:36px;padding-right:0;">
                <button class="verif-expand-btn" title="Show verification details" data-uid="<?= (int)$u['id'] ?>">▼</button>
              </td>
              <td><?= e($u['first_name'] . ' ' . $u['last_name']) ?><br><span style="color:var(--ink-soft);font-size:11.5px;"><?= e($u['email']) ?></span></td>
              <td><span class="pill <?= e($u['status']) ?>"><?= e(ucwords(str_replace('_',' ',$u['status']))) ?></span></td>
              <td><span class="pill <?= e($u['verification_status']) ?>"><?= e(ucfirst($u['verification_status'])) ?></span></td>
              <td><?= e((string)$u['trust_score']) ?></td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <?php if ($u['status'] !== 'active'): ?>
                    <form method="post" style="display:inline;"><?= csrf_field() ?>
                      <input type="hidden" name="back" value="users">
                      <input type="hidden" name="action" value="reactivate_user">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <button class="btn-sm" type="submit">Reactivate</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($u['status'] !== 'banned' && (int)$u['id'] !== $adminId): ?>
                    <button type="button" class="btn-sm danger"
                      onclick="openReasonModal('ban-modal-<?= (int)$u['id'] ?>')">Ban</button>
                  <?php endif; ?>
                  <?php if ((int)$u['id'] !== $adminId): ?>
                  <button class="btn-sm" onclick="openReasonModal('delete-modal-<?= (int)$u['id'] ?>')"
                    style="color:#8C331F;border-color:#C97B52;background:transparent;">
                    🗑 Delete Account
                  </button>
                  <?php endif; ?>
                </div>

                <!-- Ban reason modal -->
                <div class="reason-modal-backdrop" id="ban-modal-<?= (int)$u['id'] ?>">
                  <div class="reason-modal">
                    <h3>Ban <?= e($u['first_name'] . ' ' . $u['last_name']) ?>?</h3>
                    <p>This account will be immediately blocked from logging in. Please give a reason — it will be emailed to the user and recorded in the audit log.</p>
                    <form method="post" onsubmit="return validateReasonForm(this);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="back" value="users">
                      <input type="hidden" name="action" value="ban_user">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <textarea name="reason" placeholder="e.g. Repeated scam reports from other members" required></textarea>
                      <div class="reason-modal-error">Please enter a reason.</div>
                      <div class="reason-modal-actions">
                        <button type="button" class="reason-modal-cancel" onclick="closeReasonModal('ban-modal-<?= (int)$u['id'] ?>')">Cancel</button>
                        <button type="submit" class="reason-modal-confirm">Ban account</button>
                      </div>
                    </form>
                  </div>
                </div>

                <!-- Delete reason modal -->
                <div class="reason-modal-backdrop" id="delete-modal-<?= (int)$u['id'] ?>">
                  <div class="reason-modal">
                    <h3>Delete <?= e($u['first_name'] . ' ' . $u['last_name']) ?>?</h3>
                    <p><strong>This cannot be undone.</strong> All listings, transactions, messages, and verification documents for this account will be permanently removed. Please give a reason — it will be emailed to the user and recorded in the audit log.</p>
                    <form method="post" onsubmit="return validateReasonForm(this);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="back" value="users">
                      <input type="hidden" name="action" value="delete_user">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <textarea name="reason" placeholder="e.g. Confirmed fraudulent listings" required></textarea>
                      <div class="reason-modal-error">Please enter a reason.</div>
                      <div class="reason-modal-actions">
                        <button type="button" class="reason-modal-cancel" onclick="closeReasonModal('delete-modal-<?= (int)$u['id'] ?>')">Cancel</button>
                        <button type="submit" class="reason-modal-confirm">Delete permanently</button>
                      </div>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
            <tr class="verif-detail-row" id="verif-detail-users-<?= (int)$u['id'] ?>">
              <td colspan="6">
                <div class="verif-detail-panel" id="verif-panel-users-<?= (int)$u['id'] ?>">
                  <div class="verif-loading">Loading…</div>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div id="verif-lightbox-users">
        <button id="verif-lightbox-users-close" onclick="document.getElementById('verif-lightbox-users').classList.remove('on')">✕</button>
        <img id="verif-lightbox-users-img" src="" alt="">
      </div>

      <script>
      function openReasonModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('show');
      }
      function closeReasonModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('show');
      }
      function validateReasonForm(form) {
        var ta = form.querySelector('textarea[name="reason"]');
        var err = form.querySelector('.reason-modal-error');
        if (!ta.value.trim()) {
          err.style.display = 'block';
          ta.focus();
          return false;
        }
        return true;
      }
      document.querySelectorAll('.reason-modal-backdrop').forEach(function (backdrop) {
        backdrop.addEventListener('click', function (e) {
          if (e.target === backdrop) backdrop.classList.remove('show');
        });
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          document.querySelectorAll('.reason-modal-backdrop.show').forEach(function (b) { b.classList.remove('show'); });
        }
      });
      </script>

      <script>
      (function(){
        var loaded = {};
        var base = '<?= e(BASE_URL) ?>';

        function openLightbox(src){ document.getElementById('verif-lightbox-users-img').src = src; document.getElementById('verif-lightbox-users').classList.add('on'); }
        document.getElementById('verif-lightbox-users').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('on'); });

        function score_color(s){ return s==null?'#999':(s>=80?'#22c55e':(s>=60?'#eab308':'#ef4444')); }
        function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

        function renderPanel(uid, data){
          var panel = document.getElementById('verif-panel-users-'+uid);
          var html = '';
          // "IV Status" chip removed per request — this panel now only
          // shows Submitted + ID Type. "Submitted" reads the real ID
          // upload date (falling back to the face-scan or account
          // creation date); "ID Type" reads whatever was actually typed
          // on the government-ID form.
          var submittedLabel = data.submitted_at
            ? new Date(data.submitted_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
            : '—';
          var idTypeLabel = (data.gov_id && data.gov_id.id_type) ? esc(data.gov_id.id_type) : 'Not specified';
          html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:8px;">Registration Details</div>';
          html += '<div class="verif-info-grid">';
          html += '<div class="verif-info-chip"><div class="lbl">Submitted</div><div class="val">'+submittedLabel+'</div></div>';
          html += '<div class="verif-info-chip"><div class="lbl">ID Type</div><div class="val">'+idTypeLabel+'</div></div>';
          html += '</div>';
          html += '<div class="verif-detail-grid">';
          html += '<div class="verif-doc-box">';
          html += '<h4>Government ID</h4>';
          if(data.gov_id && data.gov_id.front_mime){
            var frontSrc = base+'/image.php?type=id_front&id='+data.gov_id.id;
            html += '<div style="margin-bottom:8px;font-size:11.5px;color:var(--ink-soft);">Front</div>';
            html += '<img src="'+esc(frontSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(frontSrc)+'\');" alt="ID Front">';
            if(data.gov_id.back_mime){
              var backSrc = base+'/image.php?type=id_back&id='+data.gov_id.id;
              html += '<div style="margin:10px 0 6px;font-size:11.5px;color:var(--ink-soft);">Back</div>';
              html += '<img src="'+esc(backSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(backSrc)+'\');" alt="ID Back">';
            }
          } else {
            html += '<div style="padding:30px;text-align:center;color:var(--ink-soft);font-size:13px;background:#f5f5f5;border-radius:8px;">No ID document uploaded yet.</div>';
          }
          html += '</div>';
          html += '<div class="verif-doc-box">';
          html += '<h4>Face Verification</h4>';
          if(data.face && data.face.selfie_mime){
            // Similarity Score / Match Distance intentionally removed —
            // this app never actually stores those for the face-api.js
            // flow, so the row always showed "—" here too.
            var selfieSrc = base+'/image.php?type=selfie&id='+data.face.id;
            html += '<img src="'+esc(selfieSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(selfieSrc)+'\');" alt="Selfie">';
          } else if (data.face && (data.face.has_embedding == 1 || data.face.has_embedding === true)) {
            var regDate = data.face.created_at
              ? new Date(data.face.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
              : 'an unknown date';
            html += '<div style="padding:16px;background:var(--brand-light);border-radius:8px;color:var(--brand);font-size:13px;line-height:1.5;">'
                  + '✅ <strong>Face verified</strong> — registered ' + esc(regDate) + '.<br>'
                  + 'This app stores a mathematical face descriptor for on-device matching, not a photo, so there\'s no selfie image to display here.'
                  + '</div>';
          } else {
            html += '<div style="padding:30px;text-align:center;color:var(--ink-soft);font-size:13px;background:#f5f5f5;border-radius:8px;">No face verification submitted (optional step).</div>';
          }
          html += '</div>';
          html += '</div>';
          panel.innerHTML = html;
        }

        document.querySelectorAll('.verif-expand-btn').forEach(function(btn){
          btn.addEventListener('click', function(){
            var uid = btn.getAttribute('data-uid');
            var panel = document.getElementById('verif-panel-users-'+uid);
            var isOpen = panel.classList.contains('open');
            document.querySelectorAll('.verif-detail-panel.open').forEach(function(p){ p.classList.remove('open'); });
            document.querySelectorAll('.verif-expand-btn.open').forEach(function(b){ b.classList.remove('open'); });
            if(isOpen) return;
            panel.classList.add('open');
            btn.classList.add('open');
            if(loaded[uid]) return;
            loaded[uid] = true;
            fetch('?tab=users&verif_detail='+encodeURIComponent(uid), {credentials:'same-origin'})
              .then(function(r){ return r.json(); })
              .then(function(data){ renderPanel(uid, data); })
              .catch(function(){ document.getElementById('verif-panel-users-'+uid).innerHTML='<div class="verif-loading">Failed to load details.</div>'; });
          });
        });
      })();
      </script>

    <?php elseif ($tab === 'verifications'): ?>
      <div class="admin-topbar"><div><h1>Identity Verifications</h1><p class="sub">Accounts waiting on manual face/ID review.</p></div></div>

      <div style="margin-bottom:20px;position:relative;max-width:520px;">
        <span style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#8B927F;font-size:15px;pointer-events:none;">🔍</span>
        <input type="text" id="verif-search-input" placeholder="Search by name or email…" autocomplete="off" style="
          width:100%;padding:13px 16px 13px 42px;border-radius:999px;
          border:1.5px solid rgba(22,31,22,0.16);background:#FBF7EF;
          font-family:inherit;font-size:14px;color:#161F16;outline:none;
          transition:border-color .15s ease,box-shadow .15s ease;
        " onfocus="this.style.borderColor='#155943';this.style.boxShadow='0 0 0 3px rgba(21,89,67,0.15)'"
           onblur="this.style.borderColor='rgba(22,31,22,0.16)';this.style.boxShadow='none'">
        <button id="verif-search-clear" onclick="clearVerifSearch()" style="
          display:none;position:absolute;right:14px;top:50%;transform:translateY(-50%);
          background:none;border:none;cursor:pointer;color:#8B927F;font-size:18px;line-height:1;
          padding:4px;border-radius:50%;transition:color .15s ease;
        " title="Clear search">✕</button>
      </div>
      <div id="verif-no-results" style="display:none;padding:28px;text-align:center;color:#8B927F;font-size:14px;background:#F4EEE1;border-radius:14px;margin-bottom:16px;">
        No verifications match your search.
      </div>

      <style>
        .verif-expand-btn{background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:6px;color:var(--ink-soft);font-size:16px;line-height:1;transition:transform .2s ease,color .15s ease;}
        .verif-expand-btn:hover{color:var(--brand);}
        .verif-expand-btn.open{transform:rotate(180deg);color:var(--brand);}
        .verif-detail-row td{padding:0!important;border-bottom:2px solid var(--brand-light);}
        .verif-detail-panel{padding:20px 22px;background:var(--brand-light);border-radius:0 0 12px 12px;display:none;}
        .verif-detail-panel.open{display:block;}
        .verif-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:14px;}
        .verif-doc-box{background:var(--paper);border-radius:12px;padding:14px;}
        .verif-doc-box h4{margin:0 0 10px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);}
        .verif-doc-img{width:100%;height:170px;object-fit:contain;border-radius:8px;background:#fff;border:1px solid var(--line);cursor:pointer;display:block;}
        .verif-score-row{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0;border-bottom:1px solid var(--line);}
        .verif-score-row:last-child{border-bottom:none;}
        .verif-info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px;}
        .verif-info-chip{background:var(--paper);border-radius:10px;padding:10px 13px;}
        .verif-info-chip .lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);}
        .verif-info-chip .val{font-size:13.5px;font-weight:600;margin-top:3px;}
        .verif-loading{padding:20px;text-align:center;color:var(--ink-soft);font-size:13px;}
        .verif-highlight{background:#F5E6C2;border-radius:3px;padding:0 2px;}
        #verif-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;justify-content:center;align-items:center;}
        #verif-lightbox.on{display:flex;}
        #verif-lightbox img{max-width:90vw;max-height:88vh;border-radius:10px;object-fit:contain;}
        #verif-lightbox-close{position:absolute;top:16px;right:20px;font-size:30px;color:#fff;background:none;border:none;cursor:pointer;line-height:1;}
        @media(max-width:700px){.verif-detail-grid,.verif-info-grid{grid-template-columns:1fr;}}
      </style>

      <div class="panel">
        <table class="data-table" id="verif-table">
          <thead><tr><th></th><th>User</th><th>Submitted</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if ($data['pending']->num_rows === 0): ?>
            <tr><td colspan="4"><div class="empty-state">Nothing pending review.</div></td></tr>
          <?php endif; ?>
          <?php while ($v = $data['pending']->fetch_assoc()): ?>
            <tr class="verif-main-row"
                data-uid="<?= (int)$v['id'] ?>"
                data-name="<?= e(strtolower($v['first_name'] . ' ' . $v['last_name'])) ?>"
                data-email="<?= e(strtolower($v['email'])) ?>">
              <td style="width:36px;padding-right:0;">
                <button class="verif-expand-btn" title="Show verification details" data-uid="<?= (int)$v['id'] ?>">▼</button>
              </td>
              <td>
                <?= e($v['first_name'] . ' ' . $v['last_name']) ?><br>
                <span style="color:var(--ink-soft);font-size:11.5px;"><?= e($v['email']) ?></span>
                <?php if (!empty($v['phone'])): ?><br><span style="color:var(--ink-soft);font-size:11.5px;"><?= e($v['phone']) ?></span><?php endif; ?>
              </td>
              <td><?= e(time_ago($v['created_at'])) ?></td>
              <td>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;"><?= csrf_field() ?>
                  <input type="hidden" name="back" value="verifications">
                  <input type="hidden" name="user_id" value="<?= (int)$v['id'] ?>">
                  <button class="btn-sm" name="action" value="verify_user">Approve</button>
                </form>
                <button type="button" class="btn-sm danger" style="margin-top:6px;"
                  onclick="openReasonModal('reject-modal-<?= (int)$v['id'] ?>')">Reject &amp; delete</button>

                <div class="reason-modal-backdrop" id="reject-modal-<?= (int)$v['id'] ?>">
                  <div class="reason-modal">
                    <h3>Reject <?= e($v['first_name'] . ' ' . $v['last_name']) ?>'s registration?</h3>
                    <p>This permanently deletes the account and any uploaded ID/face data. Please give a reason — it will be emailed to them so they know what to fix if they sign up again.</p>
                    <form method="post" onsubmit="return validateReasonForm(this);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="back" value="verifications">
                      <input type="hidden" name="action" value="reject_verification">
                      <input type="hidden" name="user_id" value="<?= (int)$v['id'] ?>">
                      <textarea name="reason" placeholder="e.g. ID photo was blurry / didn't match the selfie" required></textarea>
                      <div class="reason-modal-error">Please enter a reason.</div>
                      <div class="reason-modal-actions">
                        <button type="button" class="reason-modal-cancel" onclick="closeReasonModal('reject-modal-<?= (int)$v['id'] ?>')">Cancel</button>
                        <button type="submit" class="reason-modal-confirm">Reject &amp; delete</button>
                      </div>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
            <tr class="verif-detail-row" id="verif-detail-<?= (int)$v['id'] ?>">
              <td colspan="4">
                <div class="verif-detail-panel" id="verif-panel-<?= (int)$v['id'] ?>">
                  <div class="verif-loading">Loading…</div>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div id="verif-lightbox">
        <button id="verif-lightbox-close" onclick="document.getElementById('verif-lightbox').classList.remove('on')">✕</button>
        <img id="verif-lightbox-img" src="" alt="">
      </div>

      <script>
      function openReasonModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('show');
      }
      function closeReasonModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('show');
      }
      function validateReasonForm(form) {
        var ta = form.querySelector('textarea[name="reason"]');
        var err = form.querySelector('.reason-modal-error');
        if (!ta.value.trim()) {
          err.style.display = 'block';
          ta.focus();
          return false;
        }
        return true;
      }
      document.querySelectorAll('.reason-modal-backdrop').forEach(function (backdrop) {
        backdrop.addEventListener('click', function (e) {
          if (e.target === backdrop) backdrop.classList.remove('show');
        });
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          document.querySelectorAll('.reason-modal-backdrop.show').forEach(function (b) { b.classList.remove('show'); });
        }
      });
      </script>

      <script>
      (function(){
        var loaded = {};
        var base = '<?= e(BASE_URL) ?>';

        function openLightbox(src){ document.getElementById('verif-lightbox-img').src = src; document.getElementById('verif-lightbox').classList.add('on'); }
        document.getElementById('verif-lightbox').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('on'); });

        function score_color(s){ return s==null?'#999':(s>=80?'#22c55e':(s>=60?'#eab308':'#ef4444')); }
        function esc(s){ var d=document.createElement('div'); d.textContent=s==null?'':String(s); return d.innerHTML; }

        function renderPanel(uid, data){
          var panel = document.getElementById('verif-panel-'+uid);
          var html = '';
          // "IV Status" chip removed per request — this panel now only
          // shows Submitted + ID Type. "Submitted" reads the real ID
          // upload date (falling back to the face-scan or account
          // creation date); "ID Type" reads whatever was actually typed
          // on the government-ID form.
          var submittedLabel = data.submitted_at
            ? new Date(data.submitted_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
            : '—';
          var idTypeLabel = (data.gov_id && data.gov_id.id_type) ? esc(data.gov_id.id_type) : 'Not specified';
          html += '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-soft);margin-bottom:8px;">Registration Details</div>';
          html += '<div class="verif-info-grid">';
          html += '<div class="verif-info-chip"><div class="lbl">Submitted</div><div class="val">'+submittedLabel+'</div></div>';
          html += '<div class="verif-info-chip"><div class="lbl">ID Type</div><div class="val">'+idTypeLabel+'</div></div>';
          html += '</div>';
          html += '<div class="verif-detail-grid">';
          html += '<div class="verif-doc-box">';
          html += '<h4>Government ID</h4>';
          if(data.gov_id && data.gov_id.front_mime){
            var frontSrc = base+'/image.php?type=id_front&id='+data.gov_id.id;
            html += '<div style="margin-bottom:8px;font-size:11.5px;color:var(--ink-soft);">Front</div>';
            html += '<img src="'+esc(frontSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(frontSrc)+'\');" alt="ID Front">';
            if(data.gov_id.back_mime){
              var backSrc = base+'/image.php?type=id_back&id='+data.gov_id.id;
              html += '<div style="margin:10px 0 6px;font-size:11.5px;color:var(--ink-soft);">Back</div>';
              html += '<img src="'+esc(backSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(backSrc)+'\');" alt="ID Back">';
            }
          } else {
            html += '<div style="padding:30px;text-align:center;color:var(--ink-soft);font-size:13px;background:#f5f5f5;border-radius:8px;">No ID document uploaded yet.</div>';
          }
          html += '</div>';
          html += '<div class="verif-doc-box">';
          html += '<h4>Face Verification</h4>';
          if(data.face && data.face.selfie_mime){
            // Similarity Score / Match Distance intentionally removed —
            // this app never actually stores those for the face-api.js
            // flow, so the row always showed "—" here too.
            var selfieSrc = base+'/image.php?type=selfie&id='+data.face.id;
            html += '<img src="'+esc(selfieSrc)+'" class="verif-doc-img" onclick="openLightbox(\''+esc(selfieSrc)+'\');" alt="Selfie">';
          } else if (data.face && (data.face.has_embedding == 1 || data.face.has_embedding === true)) {
            var regDate = data.face.created_at
              ? new Date(data.face.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
              : 'an unknown date';
            html += '<div style="padding:16px;background:var(--brand-light);border-radius:8px;color:var(--brand);font-size:13px;line-height:1.5;">'
                  + '✅ <strong>Face verified</strong> — registered ' + esc(regDate) + '.<br>'
                  + 'This app stores a mathematical face descriptor for on-device matching, not a photo, so there\'s no selfie image to display here.'
                  + '</div>';
          } else {
            html += '<div style="padding:30px;text-align:center;color:var(--ink-soft);font-size:13px;background:#f5f5f5;border-radius:8px;">No face verification submitted (optional step).</div>';
          }
          html += '</div>';
          html += '</div>';
          panel.innerHTML = html;
        }

        document.querySelectorAll('.verif-expand-btn').forEach(function(btn){
          btn.addEventListener('click', function(){
            var uid = btn.getAttribute('data-uid');
            var panel = document.getElementById('verif-panel-'+uid);
            var isOpen = panel.classList.contains('open');
            document.querySelectorAll('.verif-detail-panel.open').forEach(function(p){ p.classList.remove('open'); });
            document.querySelectorAll('.verif-expand-btn.open').forEach(function(b){ b.classList.remove('open'); });
            if(isOpen) return;
            panel.classList.add('open');
            btn.classList.add('open');
            if(loaded[uid]) return;
            loaded[uid] = true;
            fetch('?tab=verifications&verif_detail='+encodeURIComponent(uid), {credentials:'same-origin'})
              .then(function(r){ return r.json(); })
              .then(function(data){ renderPanel(uid, data); })
              .catch(function(){ document.getElementById('verif-panel-'+uid).innerHTML='<div class="verif-loading">Failed to load details.</div>'; });
          });
        });
      })();
      </script>

      <script>
      (function () {
        var input     = document.getElementById('verif-search-input');
        var clearBtn  = document.getElementById('verif-search-clear');
        var noResults = document.getElementById('verif-no-results');
        var table     = document.getElementById('verif-table');
        if (!input || !table) return;

        function filterRows() {
          var q = input.value.trim().toLowerCase();
          clearBtn.style.display = q ? 'block' : 'none';
          var rows   = table.querySelectorAll('tr.verif-main-row');
          var shown  = 0;
          rows.forEach(function (row) {
            var uid      = row.getAttribute('data-uid');
            var name     = (row.getAttribute('data-name')  || '').toLowerCase();
            var email    = (row.getAttribute('data-email') || '').toLowerCase();
            var detailRow = document.querySelector('tr[id="verif-detail-' + uid + '"]');
            var matches  = !q || name.indexOf(q) !== -1 || email.indexOf(q) !== -1;
            row.style.display      = matches ? '' : 'none';
            if (detailRow) detailRow.style.display = matches ? '' : 'none';
            if (matches) shown++;
          });
          noResults.style.display = (shown === 0 && q) ? 'block' : 'none';
          table.style.display     = (shown === 0 && q) ? 'none'  : '';
        }
        function clearVerifSearch() { input.value = ''; filterRows(); input.focus(); }
        window.clearVerifSearch = clearVerifSearch;
        input.addEventListener('input', filterRows);
      })();
      </script>

    <?php elseif ($tab === 'products'):
      $pf = $data['statusFilter'];
      $pBase = rtrim(BASE_URL, '/');
      $pCounts = $data['prodCounts'];
      $pChips = ['all' => 'All', 'active' => 'Active', 'sold' => 'Sold', 'draft' => 'Draft', 'deleted' => 'Removed'];
      $pView = ($_GET['view'] ?? 'grid') === 'list' ? 'list' : 'grid';
      $pQs = fn(array $o) => '?' . http_build_query(array_merge(['tab' => 'products', 'status' => $pf, 'view' => $pView], $o));
    ?>
      <header class="ax-head">
        <div><h1>Products</h1><p>Review photos, check details and moderate marketplace listings.</p></div>
        <div class="ax-actions">
          <div class="ax-search"><?= ax_svg('search', 16) ?><input class="ax-input" type="search" id="pSearch" placeholder="Search title, seller or category…" autocomplete="off"></div>
        </div>
      </header>

      <div class="ax-kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
        <div class="ax-kpi"><div><div class="l">Listings</div><div class="v"><?= number_format($pCounts['all']) ?></div></div><div class="ax-ico"><?= ax_svg('cube', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Active</div><div class="v"><?= number_format($pCounts['active']) ?></div></div><div class="ax-ico blue"><?= ax_svg('check', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Sold</div><div class="v"><?= number_format($pCounts['sold']) ?></div></div><div class="ax-ico gold"><?= ax_svg('cash', 20) ?></div></div>
        <div class="ax-kpi"><div><div class="l">Removed</div><div class="v"><?= number_format($pCounts['deleted']) ?></div></div><div class="ax-ico red"><?= ax_svg('trash', 20) ?></div></div>
      </div>

      <div class="ax-toolbar">
        <div class="ax-chips">
          <?php foreach ($pChips as $k => $label): ?>
            <a class="ax-chip <?= $pf === $k ? 'on' : '' ?>" href="<?= e($pQs(['status' => $k])) ?>"><?= e($label) ?> <em><?= (int) $pCounts[$k] ?></em></a>
          <?php endforeach; ?>
        </div>
        <div style="flex:1"></div>
        <select class="ax-select" id="pCat"><option value="">All categories</option>
          <?php foreach ($data['prodCats'] as $cn): ?><option value="<?= e(strtolower($cn)) ?>"><?= e($cn) ?></option><?php endforeach; ?>
        </select>
        <div class="ax-seg">
          <a class="<?= $pView === 'grid' ? 'on' : '' ?>" href="<?= e($pQs(['view' => 'grid'])) ?>" title="Grid view"><?= ax_svg('grid', 16) ?></a>
          <a class="<?= $pView === 'list' ? 'on' : '' ?>" href="<?= e($pQs(['view' => 'list'])) ?>" title="List view"><?= ax_svg('list', 16) ?></a>
        </div>
      </div>

      <div class="ax-pgrid <?= $pView === 'list' ? 'list' : '' ?>" id="pGrid">
      <?php while ($p = $data['products']->fetch_assoc()):
        $imgIds = array_values(array_filter(explode('|', $p['images_concat'] ?? ''), fn($x) => $x !== ''));
        $imgs   = array_map(fn($id) => $pBase . '/image.php?type=product&id=' . (int) $id, $imgIds);
        $qty    = isset($p['quantity']) ? (int) $p['quantity'] : 1;
        if ((int) $p['is_deleted'])      { $st = 'Removed'; $stc = 'bad'; }
        elseif ((int) $p['is_draft'])    { $st = 'Draft';   $stc = 'dark'; }
        elseif ((int) $p['is_active'])   { $st = 'Active';  $stc = 'good'; }
        else                             { $st = 'Sold';    $stc = 'info'; }
        $seller = trim($p['first_name'] . ' ' . $p['last_name']);
        $cat    = (string) ($p['category_name'] ?? '');
        $card = [
          'id' => (int) $p['id'], 'title' => $p['title'], 'price' => '₱' . number_format((float) $p['price'], 2),
          'total' => '₱' . number_format((float) $p['price'] * max(1, $qty), 2),
          'status' => $st, 'statusCls' => $stc, 'deleted' => (int) $p['is_deleted'], 'seller' => $seller,
          'cat' => $cat, 'loc' => $p['location'] ?? '', 'cond' => $p['condition'] ?? '', 'qty' => $qty,
          'descr' => $p['description'] ?? '', 'posted' => date('M j, Y g:i A', strtotime($p['created_at'])),
          'icon' => $p['icon'] ?? '📦', 'imgs' => $imgs,
        ];
      ?>
        <article class="ax-pcard" data-search="<?= e(strtolower($p['title'] . ' ' . $seller . ' ' . $cat)) ?>" data-cat="<?= e(strtolower($cat)) ?>" data-product="<?= e(json_encode($card, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS)) ?>">
          <div class="ax-pmedia">
            <?php if ($imgs): ?><img src="<?= e($imgs[0]) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
            <span class="emo"><?= e($p['icon'] ?? '📦') ?></span>
            <span class="st"><span class="ax-b <?= $stc ?>"><?= $st ?></span></span>
            <?php if (count($imgs) > 1): ?><span class="ph"><?= ax_svg('image', 12) ?> <?= count($imgs) ?></span><?php endif; ?>
          </div>
          <div class="ax-pbody">
            <div class="ax-pprice">₱<?= number_format((float) $p['price'], 2) ?></div>
            <div class="ax-ptitle"><?= e($p['title']) ?></div>
            <div class="ax-pmeta"><span><?= e($cat ?: 'Uncategorized') ?></span><?php if (!empty($p['location'])): ?><span><?= ax_svg('pin', 12) ?> <?= e($p['location']) ?></span><?php endif; ?></div>
            <div class="ax-pfoot">
              <div class="who"><span class="ax-pav"><?= e(strtoupper(substr($p['first_name'], 0, 1) . substr($p['last_name'], 0, 1))) ?></span><b><?= e($seller) ?></b></div>
              <span class="ax-muted"><?= e(time_ago($p['created_at'])) ?></span>
            </div>
          </div>
        </article>
      <?php endwhile; ?>
      </div>
      <div class="ax-card" id="pEmpty" style="display:none"><div class="ax-empty"><?= ax_svg('image', 34) ?><b>No listings found</b>Try a different search, category or status.</div></div>

      <!-- Product detail modal -->
      <div class="ax-modal" id="pModal" aria-hidden="true">
        <div class="ax-dialog lg">
          <div class="ax-dh"><div><h3 id="pmTitle">Listing</h3><p id="pmSub"></p></div><button type="button" class="ax-x" data-close aria-label="Close">&times;</button></div>
          <div class="ax-db">
            <div class="ax-gal">
              <div><div class="ax-gal-main" id="pmMain"></div><div class="ax-gal-th" id="pmThumbs"></div></div>
              <div>
                <div class="ax-pprice" id="pmPrice" style="font-size:28px"></div>
                <div id="pmStatus" style="margin:8px 0 2px"></div>
                <div class="ax-dl" id="pmDl"></div>
                <div class="ax-dl" style="grid-template-columns:1fr;margin-top:0"><div><small>Description</small><b id="pmDesc" style="font-weight:500;white-space:pre-line;line-height:1.55"></b></div></div>
              </div>
            </div>
          </div>
          <div class="ax-df">
            <form method="post" id="pmForm" style="width:100%"><?= csrf_field() ?>
              <input type="hidden" name="back" value="products">
              <input type="hidden" name="product_id" id="pmId">
              <div id="pmReasonWrap" style="display:none;width:100%;margin-bottom:12px;text-align:left">
                <label for="pmReason" style="display:block;font-size:12.5px;font-weight:650;color:var(--ax-mute);margin-bottom:6px">Reason for removal <span style="color:var(--ax-red)">*</span> <span style="font-weight:500;color:var(--ax-faint)">— the seller will see this in their inbox</span></label>
                <textarea class="ax-textarea" name="reason" id="pmReason" rows="3" maxlength="500" placeholder="e.g. Prohibited item, misleading photos, duplicate listing…"></textarea>
                <div class="ax-err" id="pmErr">Please write a reason before removing this listing.</div>
              </div>
              <div style="display:flex;gap:10px;justify-content:flex-end;width:100%">
                <button type="button" class="ax-btn" data-close>Close</button>
                <button type="button" class="ax-btn danger" id="pmRemove">Remove listing</button>
                <button class="ax-btn danger solid" name="action" value="delete_product" id="pmConfirm" style="display:none">Confirm removal</button>
                <button class="ax-btn primary" name="action" value="restore_product" id="pmRestore">Restore listing</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <div class="ax-lb" id="lb"><button type="button" aria-label="Close">&times;</button><img id="lbImg" alt=""></div>
      <script>
      (function(){
        var m=document.getElementById('pModal'), $=function(i){return document.getElementById(i)};
        function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML}
        var cards=[].slice.call(document.querySelectorAll('.ax-pcard'));
        cards.forEach(function(c){
          c.addEventListener('click',function(){
            var d=JSON.parse(c.getAttribute('data-product'));
            $('pmTitle').textContent=d.title; $('pmSub').textContent='Listing #'+d.id+' · posted '+d.posted;
            $('pmPrice').textContent=d.price; $('pmId').value=d.id;
            $('pmStatus').innerHTML='<span class="ax-b '+d.statusCls+'">'+esc(d.status)+'</span>';
            $('pmRemove').style.display=d.deleted?'none':''; $('pmRestore').style.display=d.deleted?'':'none';
            $('pmConfirm').style.display='none'; $('pmReasonWrap').style.display='none'; $('pmReason').value=''; $('pmErr').style.display='none';
            $('pmDl').innerHTML=[['Seller',d.seller],['Category',d.cat],['Location',d.loc],['Condition',d.cond],['Quantity',d.qty],['Total value',d.total]]
              .map(function(r){return '<div><small>'+r[0]+'</small><b>'+esc(r[1]===''?'—':r[1])+'</b></div>'}).join('');
            $('pmDesc').textContent=d.descr||'No description provided.';
            var main=$('pmMain'), th=$('pmThumbs'); main.innerHTML=''; th.innerHTML='';
            if(!d.imgs.length){main.textContent=d.icon||'📦';}
            else{
              var big=document.createElement('img'); big.src=d.imgs[0]; big.alt=''; big.onclick=function(){$('lbImg').src=big.src;$('lb').classList.add('show')}; main.appendChild(big);
              if(d.imgs.length>1) d.imgs.forEach(function(u,i){var t=document.createElement('img');t.src=u;t.alt='';if(!i)t.className='on';t.onclick=function(){big.src=u;[].forEach.call(th.children,function(x){x.className=''});t.className='on'};th.appendChild(t)});
            }
            m.classList.add('show');
          });
        });
        $('pmRemove').addEventListener('click',function(){
          $('pmReasonWrap').style.display='block'; $('pmRemove').style.display='none'; $('pmConfirm').style.display=''; $('pmReason').focus();
        });
        $('pmForm').addEventListener('submit',function(e){
          var sub=e.submitter; if(sub&&sub.value==='delete_product'&&$('pmReason').value.trim().length<3){e.preventDefault();$('pmErr').style.display='block';$('pmReason').focus();}
        });
        document.addEventListener('click',function(e){
          if(e.target.closest('[data-close]')||e.target===m)m.classList.remove('show');
          if(e.target.closest('#lb'))$('lb').classList.remove('show');
        });
        document.addEventListener('keydown',function(e){if(e.key==='Escape'){m.classList.remove('show');$('lb').classList.remove('show')}});
        var s=$('pSearch'), cat=$('pCat');
        function f(){
          var q=s.value.trim().toLowerCase(), k=cat.value, n=0;
          cards.forEach(function(c){var ok=(!q||c.dataset.search.indexOf(q)>-1)&&(!k||c.dataset.cat===k);c.style.display=ok?'':'none';if(ok)n++});
          $('pEmpty').style.display=n?'none':'block';
        }
        s.addEventListener('input',f); cat.addEventListener('change',f); f();
      })();
      </script>


    <?php elseif ($tab === 'reports'): ?>
      <div class="admin-topbar"><div><h1>Reports</h1><p class="sub">User reports awaiting moderator review.</p></div></div>
      <form class="search-row" method="get">
        <input type="hidden" name="tab" value="reports">
        <select name="status" onchange="this.form.submit()">
          <?php foreach (['open','reviewing','resolved','dismissed'] as $s): ?>
            <option value="<?= $s ?>" <?= $data['statusFilter']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="panel">
        <table class="data-table">
          <thead><tr><th>Reported by</th><th>Against</th><th>Type</th><th>Details</th><th></th></tr></thead>
          <tbody>
          <?php if ($data['reports']->num_rows === 0): ?><tr><td colspan="5"><div class="empty-state">No reports in this view.</div></td></tr><?php endif; ?>
          <?php while ($r = $data['reports']->fetch_assoc()): ?>
            <tr>
              <td><?= e($r['rfn'] . ' ' . $r['rln']) ?></td>
              <td><?= $r['tuid'] ? e($r['tfn'] . ' ' . $r['tln']) : '—' ?></td>
              <td><span class="pill <?= e($r['status']) ?>"><?= e($r['type']) ?></span></td>
              <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($r['description']) ?></td>
              <td>
                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;"><?= csrf_field() ?>
                  <input type="hidden" name="back" value="reports">
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <?php if ($r['status']==='open'): ?><button class="btn-sm" name="sub_action" value="reviewing" formaction="?tab=reports" onclick="this.form.action.value='report_action'">Start review</button><?php endif; ?>
                  <input type="hidden" name="action" value="report_action">
                  <?php if ($r['status']!=='resolved'): ?><button class="btn-sm" name="sub_action" value="resolve">Resolve</button><?php endif; ?>
                  <?php if ($r['status']!=='dismissed'): ?><button class="btn-sm danger" name="sub_action" value="dismiss">Dismiss</button><?php endif; ?>
                </form>
                <?php if ($r['tuid']): ?>
                <form method="post" style="margin-top:6px;"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="notify_reported">
                  <input type="hidden" name="back" value="reports">
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="reported_user_id" value="<?= (int)$r['tuid'] ?>">
                  <button class="btn-sm">Notify user</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'groups'): ?>
      <div class="admin-topbar"><div><h1>Groups</h1><p class="sub">Community groups across SafeTrade.</p></div></div>
      <div class="panel">
        <table class="data-table">
          <thead><tr><th>Group</th><th>Owner</th><th>Members</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php if ($data['groups']->num_rows === 0): ?><tr><td colspan="5"><div class="empty-state">No groups yet.</div></td></tr><?php endif; ?>
          <?php while ($g = $data['groups']->fetch_assoc()): ?>
            <tr>
              <td><?= e($g['icon']) ?> <?= e($g['name']) ?></td>
              <td><?= e($g['owner_fn'] . ' ' . $g['owner_ln']) ?></td>
              <td><?= (int)$g['member_count'] ?></td>
              <td><?= e(time_ago($g['created_at'])) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Delete this group permanently?');"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_group">
                  <input type="hidden" name="back" value="groups">
                  <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                  <button class="btn-sm danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'analytics'): ?>
      <div class="admin-topbar"><div><h1>View Analytics</h1><p class="sub">Users, transactions, listings and groups overview.</p></div></div>

      <!-- ===== Monthly leaderboard ===== -->
      <?php
      $mkYears = range(2025, 2028);
      $mkMon   = (int) substr($data['mk_month'], 5, 2);
      $mkLabel = date('F Y', strtotime($data['mk_month'] . '-01'));
      $medals = ['🥇', '🥈', '🥉', '4', '5'];
      ?>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <h3 style="font-family:'Fraunces',serif;font-size:18px;margin:0;color:var(--brand-dark);">🏆 Monthly Leaderboard — <?= e($mkLabel) ?></h3>
        <form method="get"><input type="hidden" name="tab" value="analytics">
          <div style="display:flex;gap:8px;align-items:center;">
            <select name="month" onchange="this.form.submit()" style="padding:10px 16px;border-radius:999px;border:1.5px solid var(--line-strong);background:#fff;font-family:inherit;font-size:13.5px;">
              <?php for ($mi = 1; $mi <= 12; $mi++): ?><option value="<?= $mi ?>" <?= $mi === $mkMon ? 'selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $mi, 1))) ?></option><?php endfor; ?>
            </select>
            <select name="year" onchange="this.form.submit()" style="padding:10px 16px;border-radius:999px;border:1.5px solid var(--line-strong);background:#fff;font-family:inherit;font-size:13.5px;">
              <?php foreach ($mkYears as $yr): ?><option value="<?= $yr ?>" <?= $yr === (int) $data['mk_year'] ? 'selected' : '' ?>><?= $yr ?></option><?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:20px;margin-bottom:24px;">
        <div class="panel" style="margin:0;">
          <h2>🏅 Top Sellers of <?= e($mkLabel) ?></h2>
          <table class="data-table">
            <thead><tr><th>#</th><th>Seller</th><th>Sales</th><th>Revenue</th></tr></thead>
            <tbody>
            <?php if (!$data['mk_sellers']): ?><tr><td colspan="4"><div class="empty-state">No completed sales this month.</div></td></tr><?php endif; ?>
            <?php foreach ($data['mk_sellers'] as $i => $r): ?>
              <tr><td style="font-size:18px;"><?= $medals[$i] ?></td>
                <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><br><span style="font-size:11.5px;color:var(--ink-soft);"><?= e($r['email']) ?></span></td>
                <td><strong style="color:var(--brand);font-size:16px;"><?= (int) $r['sales'] ?></strong></td>
                <td>₱<?= number_format((float) $r['revenue'], 2) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="panel" style="margin:0;">
          <h2>🔥 Top Items Sold in <?= e($mkLabel) ?></h2>
          <table class="data-table">
            <thead><tr><th>#</th><th>Item</th><th>Units</th><th>Revenue</th></tr></thead>
            <tbody>
            <?php if (!$data['mk_items']): ?><tr><td colspan="4"><div class="empty-state">No items sold this month.</div></td></tr><?php endif; ?>
            <?php foreach ($data['mk_items'] as $i => $r): ?>
              <tr><td style="font-size:18px;"><?= $medals[$i] ?></td>
                <td><strong><?= e(($r['icon'] ?? '') . ' ' . $r['title']) ?></strong><br><span style="font-size:11.5px;color:var(--ink-soft);">by <?= e($r['first_name'] . ' ' . $r['last_name']) ?></span></td>
                <td><strong style="color:var(--brand);font-size:16px;"><?= (int) $r['units'] ?></strong></td>
                <td>₱<?= number_format((float) $r['revenue'], 2) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="panel" style="margin:0;">
          <h2>🛒 Top Buyers of <?= e($mkLabel) ?></h2>
          <table class="data-table">
            <thead><tr><th>#</th><th>Buyer</th><th>Purchases</th><th>Spent</th></tr></thead>
            <tbody>
            <?php if (!$data['mk_buyers']): ?><tr><td colspan="4"><div class="empty-state">No purchases this month.</div></td></tr><?php endif; ?>
            <?php foreach ($data['mk_buyers'] as $i => $r): ?>
              <tr><td style="font-size:18px;"><?= $medals[$i] ?></td>
                <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><br><span style="font-size:11.5px;color:var(--ink-soft);"><?= e($r['email']) ?></span></td>
                <td><strong style="color:var(--brand);font-size:16px;"><?= (int) $r['purchases'] ?></strong></td>
                <td>₱<?= number_format((float) $r['spent'], 2) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <h2>📅 Top Seller &amp; Best-Selling Item — Every Month of <?= (int) $data['mk_year'] ?></h2>
        <table class="data-table">
          <thead><tr><th>Month</th><th>Top Seller</th><th>Sales</th><th>Top Item Sold</th><th>Units</th></tr></thead>
          <tbody>
          <?php foreach ($data['mk_history'] as $h): ?>
            <tr>
              <td><strong><?= e(date('F Y', strtotime($h['ym'] . '-01'))) ?></strong></td>
              <?php if ($h['seller']): ?>
                <td>🥇 <?= e($h['seller']['first_name'] . ' ' . $h['seller']['last_name']) ?></td>
                <td><strong><?= (int) $h['seller']['sales'] ?></strong></td>
                <td><?= e(($h['item']['icon'] ?? '') . ' ' . ($h['item']['title'] ?? '—')) ?></td>
                <td><strong><?= (int) ($h['item']['units'] ?? 0) ?></strong></td>
              <?php else: ?>
                <td colspan="4" style="color:var(--ink-soft);">No completed sales</td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>


      <h3 style="font-family:'Fraunces',serif;font-size:16px;margin:0 0 12px;color:var(--brand-dark);">👥 Users</h3>
      <div class="stat-cards" style="grid-template-columns:repeat(4,1fr);margin-bottom:28px;">
        <div class="stat-card"><div class="label">Total Users</div><div class="value"><?= number_format($data['an_total_users']) ?></div></div>
        <div class="stat-card"><div class="label">Active Users</div><div class="value"><?= number_format($data['an_active_users']) ?></div></div>
        <div class="stat-card"><div class="label">Pending Verification</div><div class="value"><?= number_format($data['an_pending_users']) ?></div></div>
        <div class="stat-card"><div class="label">Banned</div><div class="value" style="color:var(--danger);"><?= number_format($data['an_banned_users']) ?></div></div>
      </div>

      <h3 style="font-family:'Fraunces',serif;font-size:16px;margin:0 0 12px;color:var(--brand-dark);">🔄 Transactions</h3>
      <div class="stat-cards" style="grid-template-columns:repeat(3,1fr);margin-bottom:28px;">
        <div class="stat-card"><div class="label">Total Transactions</div><div class="value"><?= number_format($data['an_total_tx']) ?></div></div>
        <div class="stat-card"><div class="label">Completed</div><div class="value" style="color:var(--brand);"><?= number_format($data['an_completed_tx']) ?></div></div>
        <div class="stat-card"><div class="label">Active / In Progress</div><div class="value" style="color:var(--gold);"><?= number_format($data['an_active_tx']) ?></div></div>
      </div>

      <h3 style="font-family:'Fraunces',serif;font-size:16px;margin:0 0 12px;color:var(--brand-dark);">📦 Listings</h3>
      <div class="stat-cards" style="grid-template-columns:repeat(3,1fr);margin-bottom:28px;">
        <div class="stat-card"><div class="label">Total Listings</div><div class="value"><?= number_format($data['an_total_listings']) ?></div></div>
        <div class="stat-card"><div class="label">Currently Active</div><div class="value" style="color:var(--brand);"><?= number_format($data['an_active_listings']) ?></div></div>
        <div class="stat-card"><div class="label">Sold</div><div class="value" style="color:var(--gold);"><?= number_format($data['an_sold_listings']) ?></div></div>
      </div>

      <h3 style="font-family:'Fraunces',serif;font-size:16px;margin:0 0 12px;color:var(--brand-dark);">👥 Groups</h3>
      <div class="stat-cards" style="grid-template-columns:repeat(2,1fr);margin-bottom:28px;">
        <div class="stat-card"><div class="label">Total Groups</div><div class="value"><?= number_format($data['an_total_groups']) ?></div></div>
        <div class="stat-card"><div class="label">Total Members</div><div class="value"><?= number_format($data['an_total_members']) ?></div></div>
      </div>

      <div class="panel">
        <h2>📂 Listings by Category</h2>
        <table class="data-table">
          <thead><tr><th>Category</th><th>Total Listings</th><th>Share</th></tr></thead>
          <tbody>
          <?php
          $catRows = []; $catTotal = 0;
          while ($r = $data['an_categories']->fetch_assoc()) { $catRows[] = $r; $catTotal += (int)$r['total']; }
          foreach ($catRows as $r):
            $pct = $catTotal > 0 ? round(($r['total'] / $catTotal) * 100) : 0;
          ?>
            <tr>
              <td><?= e($r['icon']) ?> <?= e($r['name']) ?></td>
              <td><strong><?= (int)$r['total'] ?></strong></td>
              <td style="width:200px;">
                <div style="background:var(--line);border-radius:99px;height:8px;overflow:hidden;">
                  <div style="width:<?= $pct ?>%;height:100%;background:var(--brand);border-radius:99px;"></div>
                </div>
                <span style="font-size:11px;color:var(--ink-soft);"><?= $pct ?>%</span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'monitor'): ?>
      <div class="admin-topbar"><div><h1>Monitor Activity</h1><p class="sub">Live transactions and recent platform activity.</p></div></div>

      <div class="panel">
        <h2>🔄 Active Transactions</h2>
        <table class="data-table">
          <thead><tr><th>Product</th><th>Buyer</th><th>Seller</th><th>Status</th><th>Started</th></tr></thead>
          <tbody>
          <?php if ($data['mon_transactions']->num_rows === 0): ?>
            <tr><td colspan="5"><div class="empty-state">No active transactions right now.</div></td></tr>
          <?php endif; ?>
          <?php while ($t = $data['mon_transactions']->fetch_assoc()): ?>
            <tr>
              <td><?= e($t['product_icon']) ?> <?= e($t['product_title']) ?></td>
              <td><?= e($t['buyer_fn'].' '.$t['buyer_ln']) ?></td>
              <td><?= e($t['seller_fn'].' '.$t['seller_ln']) ?></td>
              <td><span class="pill <?= e($t['status']) ?>"><?= e(ucwords(str_replace('_',' ',$t['status']))) ?></span></td>
              <td><?= e(date('M j, Y g:i A', strtotime($t['created_at']))) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <div class="panel">
        <h2>📋 Recent Platform Activity</h2>
        <table class="data-table">
          <thead><tr><th>Actor</th><th>Action</th><th>Entity</th><th>IP</th><th>When</th></tr></thead>
          <tbody>
          <?php while ($a = $data['mon_recent_activity']->fetch_assoc()): ?>
            <tr>
              <td><?= $a['first_name'] ? e($a['first_name'].' '.$a['last_name']) : '<span style="color:var(--ink-soft);">System</span>' ?></td>
              <td><code style="font-size:12px;background:var(--paper);padding:2px 6px;border-radius:5px;"><?= e($a['action']) ?></code></td>
              <td><?= e(trim(($a['target_type'] ?? '').' #'.($a['target_id'] ?? ''), ' #')) ?></td>
              <td style="font-size:12px;color:var(--ink-soft);"><?= e($a['ip_address'] ?? '—') ?></td>
              <td style="font-size:12px;"><?= e(date('M j, g:i A', strtotime($a['created_at']))) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

    <?php elseif ($tab === 'gen_reports'): ?>
      <div class="admin-topbar">
        <div><h1>Generate Reports</h1><p class="sub">Analysis & summary of platform data.</p></div>
        <button onclick="window.print()" class="btn-sm" style="height:fit-content;">🖨 Print / Export</button>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
        <div class="panel" style="margin:0;">
          <h2>👥 Users by Status</h2>
          <table class="data-table">
            <thead><tr><th>Status</th><th>Count</th></tr></thead>
            <tbody>
            <?php while ($r = $data['gr_users_by_status']->fetch_assoc()): ?>
              <tr><td><span class="pill <?= e($r['status']) ?>"><?= e(ucwords(str_replace('_',' ',$r['status']))) ?></span></td><td><strong><?= (int)$r['c'] ?></strong></td></tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
        <div class="panel" style="margin:0;">
          <h2>🔄 Transactions by Status</h2>
          <table class="data-table">
            <thead><tr><th>Status</th><th>Count</th></tr></thead>
            <tbody>
            <?php while ($r = $data['gr_tx_by_status']->fetch_assoc()): ?>
              <tr><td><span class="pill <?= e($r['status']) ?>"><?= e(ucwords(str_replace('_',' ',$r['status']))) ?></span></td><td><strong><?= (int)$r['c'] ?></strong></td></tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="panel">
        <h2>💰 Monthly Sales Summary (Last 12 months)</h2>
        <table class="data-table">
          <thead><tr><th>Month</th><th>Completed Sales</th><th>Total Revenue</th></tr></thead>
          <tbody>
          <?php if ($data['gr_revenue']->num_rows === 0): ?><tr><td colspan="3"><div class="empty-state">No completed sales yet.</div></td></tr><?php endif; ?>
          <?php while ($r = $data['gr_revenue']->fetch_assoc()): ?>
            <tr>
              <td><?= e(date('F Y', strtotime($r['month'].'-01'))) ?></td>
              <td><?= (int)$r['sales'] ?></td>
              <td><strong style="color:var(--brand);">₱<?= number_format((float)$r['total'], 2) ?></strong></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <?php $fb = $data['gr_feedback']->fetch_assoc(); ?>
      <div class="panel">
        <h2>⭐ Feedback Summary</h2>
        <div class="stat-cards" style="grid-template-columns:repeat(4,1fr);">
          <div class="stat-card"><div class="label">Total Reviews</div><div class="value"><?= (int)($fb['total'] ?? 0) ?></div></div>
          <div class="stat-card"><div class="label">Average Rating</div><div class="value" style="color:var(--gold);"><?= $fb['total'] > 0 ? number_format((float)$fb['avg_rating'],2) : '—' ?></div></div>
          <div class="stat-card"><div class="label">5★ Reviews</div><div class="value" style="color:var(--brand);"><?= (int)($fb['five'] ?? 0) ?></div></div>
          <div class="stat-card"><div class="label">1★ Reviews</div><div class="value" style="color:var(--danger);"><?= (int)($fb['one'] ?? 0) ?></div></div>
        </div>
      </div>

    <?php elseif ($tab === 'audit'): ?>
      <div class="admin-topbar"><div><h1>Audit Log</h1><p class="sub">Immutable trail of all security-relevant events (<?= number_format($data['audit_total']) ?> entries).</p></div></div>

      <div class="panel">
        <table class="data-table">
          <thead><tr><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th><th>When</th></tr></thead>
          <tbody>
          <?php if ($data['audit_logs']->num_rows === 0): ?><tr><td colspan="6"><div class="empty-state">No log entries.</div></td></tr><?php endif; ?>
          <?php while ($a = $data['audit_logs']->fetch_assoc()): ?>
            <tr>
              <td><?= $a['first_name'] ? e($a['first_name'].' '.$a['last_name']) : '<span style="color:var(--ink-soft);">System</span>' ?></td>
              <td><code style="font-size:11.5px;background:var(--paper);padding:2px 6px;border-radius:5px;"><?= e($a['action']) ?></code></td>
              <td><?= e(trim(($a['target_type'] ?? '').' #'.($a['target_id'] ?? ''), ' #')) ?></td>
              <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--ink-soft);"><?= e($a['metadata'] ?? '—') ?></td>
              <td style="font-size:12px;color:var(--ink-soft);"><?= e($a['ip_address'] ?? '—') ?></td>
              <td style="font-size:12px;"><?= e(date('M j, Y g:i A', strtotime($a['created_at']))) ?></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
        <?php if ($data['audit_pages'] > 1): ?>
        <div class="pagination">
          <?php for ($p = 1; $p <= $data['audit_pages']; $p++): ?>
            <a href="?tab=audit&apage=<?= $p ?>" class="<?= $p === $data['audit_page'] ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'sys_analytics'): ?>
      <div class="admin-topbar"><div><h1>System Analytics</h1><p class="sub">Server environment and database statistics.</p></div></div>

      <div class="panel">
        <h2>🖥 Server Environment</h2>
        <div class="stat-cards" style="grid-template-columns:repeat(3,1fr);margin-bottom:0;">
          <div class="stat-card"><div class="label">PHP Version</div><div class="value" style="font-size:20px;"><?= e($data['sys_php']) ?></div></div>
          <div class="stat-card"><div class="label">Operating System</div><div class="value" style="font-size:16px;"><?= e($data['sys_os']) ?></div></div>
          <div class="stat-card"><div class="label">Memory Limit</div><div class="value" style="font-size:20px;"><?= e($data['sys_memory']) ?></div></div>
          <div class="stat-card"><div class="label">Upload Max Size</div><div class="value" style="font-size:20px;"><?= e($data['sys_upload']) ?></div></div>
          <div class="stat-card"><div class="label">Database Size</div><div class="value" style="font-size:20px;"><?= e($data['sys_db_size']) ?> MB</div></div>
          <div class="stat-card"><div class="label">Active Extensions</div><div class="value" style="font-size:13px;line-height:1.4;"><?= e($data['sys_extensions']) ?></div></div>
        </div>
      </div>

      <div class="panel">
        <h2>🗄 Database Table Sizes</h2>
        <table class="data-table">
          <thead><tr><th>Table</th><th>Estimated Rows</th></tr></thead>
          <tbody>
          <?php while ($r = $data['sys_row_counts']->fetch_assoc()): ?>
            <tr>
              <td><code style="font-size:13px;"><?= e($r['table_name']) ?></code></td>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <strong><?= number_format((int)$r['table_rows']) ?></strong>
                  <div style="flex:1;max-width:160px;background:var(--line);border-radius:99px;height:7px;overflow:hidden;">
                    <div style="width:<?= min(100, max(2, (int)(($r['table_rows'] / 500) * 100))) ?>%;height:100%;background:var(--brand);border-radius:99px;"></div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>
  </main>
</div>
</body>
</html>
