<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * SafeTrade maintenance mode.
 *
 * A "maintenance" announcement (Admin > Announcements) locks the site for
 * regular members while it is in effect:
 *
 *   Not yet      starts_at is still in the future  -> site open
 *   Maintenance  start reached, not finished        -> members locked out
 *   Done         ends_at passed, OR an admin pressed -> site open again
 *                "Mark done" (announcements.maint_done_at)
 *
 * Admins and managers are never locked out. Audience 'staff' notices
 * never lock anybody.
 *
 * All time comparisons use MySQL's own NOW() so PHP/DB clock drift can't
 * make the lock stick or release early.
 * ---------------------------------------------------------------------
 */

function maint_ensure_column(): void
{
    try {
        $c   = db();
        $chk = $c->query("SHOW COLUMNS FROM announcements LIKE 'maint_done_at'");
        if ($chk && $chk->num_rows === 0) {
            $c->query('ALTER TABLE announcements ADD COLUMN maint_done_at DATETIME NULL');
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] maint_ensure_column failed: ' . $e->getMessage());
    }
}

/** The maintenance currently locking out members, or null when the site is open. */
function maintenance_current(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;

    $sql = "SELECT id, title, starts_at, ends_at,
                   CASE WHEN ends_at IS NULL THEN NULL ELSE TIMESTAMPDIFF(SECOND, NOW(), ends_at) END AS secs_left
            FROM announcements
            WHERE category = 'maintenance'
              AND audience IN ('users','all')
              AND maint_done_at IS NULL
              AND (starts_at IS NULL OR starts_at <= NOW())
              AND (ends_at IS NULL OR ends_at > NOW())
            ORDER BY id DESC LIMIT 1";

    for ($try = 0; $try < 2; $try++) {
        try {
            $row   = db()->query($sql)->fetch_assoc();
            $cache = $row ?: null;
            return $cache;
        } catch (Throwable $e) {
            if ($try === 0) maint_ensure_column(); // column probably missing — add it and retry once
        }
    }
    return $cache = null; // fail open: a DB hiccup must never lock everybody out
}

function maint_is_staff(): bool
{
    return in_array($_SESSION['user_role'] ?? '', ['admin', 'manager'], true);
}

/**
 * Call once per request (done from includes/inbox.php, which config.php
 * already loads on every page). Sends non-staff visitors to the
 * "Server is under maintenance" page while maintenance is active.
 */
/**
 * "Mark done" button (Admin > Announcements). Handled here, on the same
 * page the form is posted to, so no separate endpoint file is needed.
 */
function maint_handle_done_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    if (($_POST['action'] ?? '') !== 'finish_maintenance') return;
    if (!maint_is_staff()) return;

    require_csrf();
    $id = (int) ($_POST['announcement_id'] ?? 0);
    if ($id > 0) {
        maint_ensure_column();
        $stmt = db()->prepare("UPDATE announcements SET maint_done_at = NOW() WHERE id = ? AND category = 'maintenance' AND maint_done_at IS NULL");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        audit_log((int) ($_SESSION['user_id'] ?? 0), 'admin.maintenance.done', 'announcement', $id);
        flash_set('success', 'Maintenance marked as done — members can use SafeTrade again.');
    }
    redirect('/admin/dashboard.php?tab=announcements');
}

function maintenance_gate(): void
{
    if (PHP_SAPI === 'cli') return;
    maint_handle_done_post();

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');

    // Pages that must keep working: the maintenance page itself, its status
    // check, and the login/logout flow (so admins can still sign in).
    $allow = ['maintenance.php', 'maintenance-status.php', 'login.php', 'logout.php',
              'face-verify-api.php', 'lockout-status.php'];
    // A member who is still signed in and clicks "Log in" would be bounced by
    // login.php straight to the dashboard (-> maintenance page again). While
    // maintenance is active, sign non-staff out when they reach the login page
    // so they can actually see it.
    if ($script === 'login.php' && !empty($_SESSION['user_id']) && !maint_is_staff() && maintenance_current() !== null) {
        if (!empty($_SESSION['session_token'])) {
            try {
                $st = db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE session_token = ?');
                $st->bind_param('s', $_SESSION['session_token']);
                $st->execute();
                $st->close();
            } catch (Throwable $e) {
            }
        }
        unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['session_token']);
    }

    if (in_array($script, $allow, true)) return;
    if (maint_is_staff()) return;
    if (maintenance_current() === null) return;

    $json = ['chat-api.php', 'inbox-api.php', 'mini-profile-api.php', 'update-location.php', 'ip-location.php', 'image.php'];
    http_response_code(503);
    header('Retry-After: 60');
    if (in_array($script, $json, true)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'maintenance']);
        exit;
    }
    redirect('/maintenance.php');
}
