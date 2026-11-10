<?php
/**
 * patch_admin_suggestions.php — run ONCE:  http://localhost/safetrade/patch_admin_suggestions.php
 *
 * Puts the purple "Suggestions" button directly BELOW "Groups" in the sidebar of
 * admin/dashboard.php (with an unread badge = suggestions still undecided).
 * It first removes any older Suggestions link added by earlier patchers, so it is
 * safe to run even if patch_suggestions_v2.php / patch_admin_fix.php already ran.
 *
 * (admin/partials.php — the sidebar used by every other admin page — is replaced
 *  by the full file I sent, so this patcher only touches admin/dashboard.php.)
 *
 * Backup: admin/dashboard.php.bak6.   DELETE THIS FILE afterwards.
 */
header('Content-Type: text/plain; charset=utf-8');
if (function_exists('opcache_reset')) { @opcache_reset(); }

$file = __DIR__ . '/admin/dashboard.php';
if (!is_file($file)) exit("admin/dashboard.php not found next to this script. Nothing changed.\n");
foreach (['admin/suggestions.php', 'includes/suggestions.php'] as $need) {
    if (!is_file(__DIR__ . '/' . $need)) exit("Missing $need — put it in place first. Nothing changed.\n");
}

$src = file_get_contents($file);

// Remove any earlier Suggestions block so the link is never duplicated.
$clean = preg_replace('/\s*<!--SG-LINK-->.*?<!--\/SG-LINK-->/s', '', $src);

if (!preg_match('/^[^\n]*tab_link\(\'groups\'[^\n]*$/m', $clean, $m)) {
    exit("FAILED: couldn't find the Groups tab_link line in admin/dashboard.php. Nothing changed.\n");
}

$icon = '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 00-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0012 3z"/>';

$block = <<<'HTML'

      <!--SG-LINK-->
      <?php $sgPending = 0; try { $sgPending = count_q($conn, "SELECT COUNT(*) c FROM suggestions WHERE status='none'"); } catch (Throwable $e) {} ?>
      <a class="admin-link sg-link" href="suggestions.php" title="Suggestions"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">@@ICON@@</svg><span>Suggestions</span><?php if ($sgPending > 0): ?><span class="count"><?= (int) $sgPending ?></span><?php endif; ?></a>
      <style>
      body .admin-nav .admin-link.sg-link{background:linear-gradient(135deg,#7B5CFF,#3F7CF4) !important;color:#fff !important;margin-top:6px;box-shadow:0 6px 16px rgba(91,63,208,.45);}
      body .admin-nav .admin-link.sg-link:hover{filter:brightness(1.1);}
      body .admin-nav .admin-link.sg-link svg{background:#fff !important;color:#5B3FD0 !important;}
      </style>
      <!--/SG-LINK-->
HTML;
$block = str_replace('@@ICON@@', $icon, $block);

$new = str_replace($m[0], $m[0] . $block, $clean);

copy($file, $file . '.bak6');
file_put_contents($file, $new);

echo "OK: Suggestions button added below Groups in admin/dashboard.php (backup: admin/dashboard.php.bak6).\n";
echo "Now paste the new admin/partials.php, press Ctrl+F5 on the admin pages, and delete this file.\n";
