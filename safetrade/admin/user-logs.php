<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/../includes/log_viewer.php';
require_once __DIR__ . '/partials.php';

$adminUser = require_role('admin', 'manager');
$conn = db();

lv_handle_export($conn, 'user'); // ?export=1 streams a CSV before any HTML

$pageTitle = 'User Logs · SafeTrade Admin';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';
?>
<div class="admin-app">
  <?php render_admin_sidebar('user-logs', $adminUser); ?>
  <main class="admin-main">
    <?php render_flashes(); ?>
    <?php lv_render($conn, 'user'); ?>
  </main>
</div>
</body>
</html>
