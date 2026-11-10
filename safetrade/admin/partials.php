<?php
/**
 * admin/partials.php — shared admin sidebar (modern SaaS layout).
 * Used by admin/dashboard.php AND every standalone admin page
 * (users, groups, reports, listings, audit-log, settings, manage-admins...).
 * Styles live in assets/css/admin-ui.css (auto-injected by config.php).
 */

if (!function_exists('ax_svg')) {
    /** Inline stroke icon. */
    function ax_svg(string $name, int $size = 18): string
    {
        static $p = [
            'home'    => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/>',
            'inbox'   => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/>',
            'mega'    => '<path d="M3 11v2a1 1 0 001 1h3l5 4V6L7 10H4a1 1 0 00-1 1z"/><path d="M16 8a5 5 0 010 8"/>',
            'users'   => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6.3 6.5-6.3s6.5 2.7 6.5 6.3"/><path d="M16 4.3a3.2 3.2 0 010 6.3"/><path d="M21.5 20c0-2.9-1.9-5.2-4.5-6"/>',
            'verify'  => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
            'cube'    => '<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
            'alert'   => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L2.7 17a2 2 0 001.7 3h15.2a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>',
            'group'   => '<circle cx="9" cy="8" r="3"/><path d="M2.5 19c0-3.3 2.9-5.8 6.5-5.8s6.5 2.5 6.5 5.8"/><path d="M16.5 6.5a3 3 0 010 5.8"/>',
            'bulb'    => '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 00-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0012 3z"/>',
            'chart'   => '<path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/>',
            'pulse'   => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
            'file'    => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8M10 9H8"/>',
            'audit'   => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12h6M9 16h4"/>',
            'server'  => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 8h.01M7 17h.01"/>',
            'gear'    => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
            'shield'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'search'  => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
            'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'list'    => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'check'   => '<path d="M5 12l5 5 9-10"/>',
            'x'       => '<path d="M6 6l12 12M18 6L6 18"/>',
            'trash'   => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
            'undo'    => '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 010 12h-3"/>',
            'image'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-8 9"/>',
            'cash'    => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>',
            'swap'    => '<path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 013-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 01-3 3H3"/>',
            'eye'     => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
            'print'   => '<path d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-6a2 2 0 012-2h14a2 2 0 012 2v6a1 1 0 01-1 1h-2"/><rect x="6" y="14" width="12" height="7"/>',
            'logout'  => '<path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H9"/>',
            'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'pin'     => '<path d="M12 21s7-6 7-12a7 7 0 10-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/>',
            'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
            'idcard'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M14 10h4M14 14h4M6 17c.6-2 5.4-2 6 0"/>',
            'trend'   => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
            'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        ];
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? $p['cube']) . '</svg>';
    }
}

function render_admin_sidebar(string $activeNav, array $adminUser): void
{
    $aliases = ['listings' => 'products', 'audit-log' => 'audit'];
    $active  = $aliases[$activeNav] ?? $activeNav;

    $pendingVerifs = count_pending_verifications();
    $openReports   = count_open_reports();
    $pendingSug    = count_pending_suggestions();
    $inboxN        = function_exists('inbox_unread') ? inbox_unread((int) $adminUser['id']) : 0;

    $sections = [
        'Workspace' => [
            ['overview',      'Overview',       'dashboard.php',                     'home',   0],
            ['inbox',         'Inbox',          'dashboard.php?tab=inbox',           'inbox',  -1],
            ['announcements', 'Announcements',  'dashboard.php?tab=announcements',   'mega',   0],
        ],
        'Marketplace' => [
            ['products',      'Products',       'dashboard.php?tab=products',        'cube',   0],
            ['users',         'Users',          'dashboard.php?tab=users',           'users',  0],
            ['verifications', 'Verifications',  'dashboard.php?tab=verifications',   'verify', $pendingVerifs],
            ['groups',        'Groups',         'dashboard.php?tab=groups',          'group',  0],
            ['reports',       'Reports',        'dashboard.php?tab=reports',         'alert',  $openReports],
            ['suggestions',   'Suggestions',    'suggestions.php',                   'bulb',   $pendingSug],
        ],
        'Insights & logs' => [
            ['analytics',     'View Analytics',   'dashboard.php?tab=analytics',     'chart',  0],
            ['monitor',       'Monitor Activity', 'dashboard.php?tab=monitor',       'pulse',  0],
            ['gen_reports',   'General Report',   'dashboard.php?tab=gen_reports',   'file',   0],
            ['audit',         'Audit Log',        'dashboard.php?tab=audit',         'audit',  0],
            ['sys_analytics', 'System Analytics', 'dashboard.php?tab=sys_analytics', 'server', 0],
        ],
        'Logs' => [
            ['admin-logs',    'Admin Logs',     'admin-logs.php',                    'shield', 0],
            ['user-logs',     'User Logs',      'user-logs.php',                     'users',  0],
        ],
        'Administration' => [
            ['settings',      'Settings',       'settings.php',                      'gear',   0],
        ],
    ];
    if (($adminUser['role'] ?? '') === 'admin') {
        $sections['Administration'][] = ['manage-admins', 'Manage Admins', 'manage-admins.php', 'shield', 0];
    }

    $first    = (string) ($adminUser['first_name'] ?? '');
    $last     = (string) ($adminUser['last_name'] ?? '');
    $initials = strtoupper(substr($first, 0, 1) . substr($last, 0, 1)) ?: 'A';
    $fullName = trim($first . ' ' . $last);
    $logo     = rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/img/logo1.png';

    echo '<aside class="ax-side">';
    echo '<div class="ax-brand">';
    echo '<button type="button" class="ax-burger" id="ax-burger" aria-label="Toggle sidebar">' . ax_svg('menu', 17) . '</button>';
    echo '<a class="ax-logo" href="dashboard.php"><img src="' . e($logo) . '" alt="" onerror="this.style.display=\'none\'"><span>SafeTrade<small>Admin console</small></span></a>';
    echo '</div><nav class="ax-nav">';

    foreach ($sections as $title => $items) {
        echo '<div class="ax-sec">' . e($title) . '</div>';
        foreach ($items as [$key, $label, $href, $icon, $badge]) {
            $cls = 'ax-link' . ($key === $active ? ' active' : '');
            echo '<a class="' . $cls . '" href="' . e($href) . '" title="' . e($label) . '">' . ax_svg($icon) . '<span>' . e($label) . '</span>';
            if ($badge === -1) {
                // live inbox badge — updated by the realtime poller in includes/inbox.php
                echo '<span class="ax-count" data-inbox-badge data-count="' . $inboxN . '"' . ($inboxN > 0 ? '' : ' style="display:none;"') . '>' . ($inboxN > 99 ? '99+' : $inboxN) . '</span>';
            } elseif ($badge > 0) {
                echo '<span class="ax-count">' . ($badge > 99 ? '99+' : (int) $badge) . '</span>';
            }
            echo '</a>';
        }
    }
    echo '</nav>';
    echo '<div class="ax-me"><div class="ax-av">' . e($initials) . '</div>'
       . '<div class="ax-me-t"><b>' . e($fullName) . '</b><span>' . e((string) ($adminUser['role'] ?? 'admin')) . '</span></div>'
       . '<a class="ax-logout" href="../auth/logout.php" title="Log out">' . ax_svg('logout', 16) . '</a></div>';
    echo '</aside>';

    echo '<script>(function(){var K="safetrade_admin_sidebar_collapsed",a=document.querySelector(".admin-app"),b=document.getElementById("ax-burger");'
       . 'function s(c){if(a)a.classList.toggle("sidebar-collapsed",c);}'
       . 'try{s(localStorage.getItem(K)==="1");}catch(e){}'
       . 'if(b)b.addEventListener("click",function(){var c=!a.classList.contains("sidebar-collapsed");s(c);try{localStorage.setItem(K,c?"1":"0");}catch(e){}});})();</script>';
}

function count_pending_verifications(): int
{
    try {
        $res = db()->query("SELECT COUNT(*) c FROM users WHERE verification_status = 'pending'");
        return (int) ($res->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

function count_open_reports(): int
{
    try {
        $res = db()->query("SELECT COUNT(*) c FROM reports WHERE status = 'open'");
        return (int) ($res->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

/** Suggestions still waiting for an approve/deny decision. Never throws. */
function count_pending_suggestions(): int
{
    try {
        $res = db()->query("SELECT COUNT(*) c FROM suggestions WHERE status = 'none'");
        return (int) ($res->fetch_assoc()['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}
