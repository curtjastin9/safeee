<?php
/**
 * admin/sg-sidebar-link.php
 * "Suggestions" sidebar button for admin/dashboard.php.
 * Included from inside the <nav class="admin-nav"> right after the Groups link,
 * so it runs in dashboard.php's scope ($conn and count_q() are available).
 */
$sgPending = 0;
try {
    $sgPending = count_q($conn, "SELECT COUNT(*) c FROM suggestions WHERE status='none'");
} catch (Throwable $e) {
    // suggestions table not created yet — just show no badge
}
?>
<a class="admin-link sg-link" href="suggestions.php" title="Suggestions">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 00-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0012 3z"/></svg>
  <span>Suggestions</span>
  <?php if ($sgPending > 0): ?><span class="count"><?= (int) $sgPending ?></span><?php endif; ?>
</a>
