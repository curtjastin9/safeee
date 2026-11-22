<?php
/**
 * patch_admin_fix.php — run ONCE:  http://localhost/safetrade/patch_admin_fix.php
 *
 * 1. Fixes Admin > System Analytics (broken sidebar/layout).
 * 2. Adds the purple "Suggestions" link to the bottom of the admin sidebar
 *    (admin/dashboard.php AND admin/partials.php).
 * 3. Renames the stale, wrongly-named duplicates:
 *      admin/admin-dashboard.php     -> admin/admin-dashboard.php.old
 *      admin/admin-verifications.php -> admin/admin-verifications.php.old
 *    (the real pages are admin/dashboard.php and admin/verifications.php)
 *
 * Backups are saved as .bak5. DELETE THIS FILE afterwards.
 */
header('Content-Type: text/plain; charset=utf-8');
if (function_exists('opcache_reset')) { @opcache_reset(); }

$ICON = '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 00-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0012 3z"/>';
$log  = [];

foreach (['admin/dashboard.php', 'admin/partials.php', 'admin/suggestions.php', 'includes/suggestions.php'] as $need) {
    if (!is_file(__DIR__ . '/' . $need)) exit("Missing $need — nothing changed.\n");
}

/* ============ 1 + 2a. admin/dashboard.php ============ */
$f   = __DIR__ . '/admin/dashboard.php';
$src = file_get_contents($f);
$new = $src;
$ok  = true;

/* --- System Analytics data block --- */
if (strpos($new, 'sys_rows') !== false) {
    $log[] = 'dashboard.php: System Analytics already fixed';
} else {
    $s = strpos($new, "if (\$tab === 'sys_analytics') {");
    $e = strpos($new, 'function tab_link(');
    if ($s === false || $e === false || $e < $s) {
        $log[] = 'dashboard.php FAILED: sys_analytics data block not found'; $ok = false;
    } else {
        $block = <<<'PHPCODE'
if ($tab === 'sys_analytics') {
    $data['sys_php']        = (string) phpversion();
    $data['sys_os']         = php_uname('s') . ' ' . php_uname('r');
    $data['sys_memory']     = (string) ini_get('memory_limit');
    $data['sys_upload']     = (string) ini_get('upload_max_filesize');
    $data['sys_extensions'] = implode(', ', array_filter(['gd','mysqli','mbstring','json','curl'], 'extension_loaded'));
    $data['sys_db_size']    = '—';
    $data['sys_rows']       = [];
    try {
        $row = $conn->query(
            "SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS size_mb
             FROM information_schema.tables WHERE table_schema=DATABASE()"
        )->fetch_assoc();
        $data['sys_db_size'] = (string) ($row['size_mb'] ?? '—');

        $res = $conn->query(
            "SELECT table_name AS tname, table_rows AS trows
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
             ORDER BY table_rows DESC"
        );
        while ($r = $res->fetch_assoc()) {
            $data['sys_rows'][] = ['name' => (string) ($r['tname'] ?? ''), 'rows' => (int) ($r['trows'] ?? 0)];
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] sys_analytics failed: ' . $e->getMessage());
    }
}

PHPCODE;
        $new = substr($new, 0, $s) . $block . substr($new, $e);

        $loopStart = "<?php while (\$r = \$data['sys_row_counts']->fetch_assoc()): ?>";
        $ls = strpos($new, $loopStart);
        $endw = "<?php endwhile; ?>";
        $le = $ls === false ? false : strpos($new, $endw, $ls);
        if ($ls === false || $le === false) {
            $log[] = 'dashboard.php FAILED: System Analytics table loop not found'; $ok = false;
        } else {
            $le += strlen($endw);
            $loop = <<<'PHPCODE'
<?php foreach ($data['sys_rows'] as $r): ?>
            <tr>
              <td><code style="font-size:13px;"><?= e($r['name']) ?></code></td>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <strong><?= number_format($r['rows']) ?></strong>
                  <div style="flex:1;max-width:160px;background:var(--line);border-radius:99px;height:7px;overflow:hidden;">
                    <div style="width:<?= min(100, max(2, (int) (($r['rows'] / 500) * 100))) ?>%;height:100%;background:var(--brand);border-radius:99px;"></div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
PHPCODE;
            $new = substr($new, 0, $ls) . $loop . substr($new, $le);
            $log[] = 'dashboard.php: System Analytics fixed';
        }
    }
}

/* --- Suggestions link at the bottom of the sidebar --- */
if ($ok) {
    $new = preg_replace('/\s*<!--SG-LINK-->.*?<!--\/SG-LINK-->/s', '', $new);
    $block = <<<'HTML'

      <!--SG-LINK-->
      <a class="admin-link sg-link" href="suggestions.php" title="Suggestions"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">@@ICON@@</svg><span>Suggestions</span></a>
      <style>
      body .admin-nav .admin-link.sg-link{background:linear-gradient(135deg,#7B5CFF,#3F7CF4) !important;color:#fff !important;margin-top:10px;box-shadow:0 6px 16px rgba(91,63,208,.45);}
      body .admin-nav .admin-link.sg-link:hover{filter:brightness(1.1);}
      body .admin-nav .admin-link.sg-link svg{background:#fff !important;color:#5B3FD0 !important;}
      </style>
      <!--/SG-LINK-->
HTML;
    $block = str_replace('@@ICON@@', $ICON, $block);
    if (preg_match('/^[^\n]*tab_link\(\'sys_analytics\'[^\n]*$/m', $new, $m)) {
        $new = str_replace($m[0], $m[0] . $block, $new);
        $log[] = 'dashboard.php: Suggestions link added';
    } else {
        $log[] = 'dashboard.php FAILED: sys_analytics sidebar link line not found'; $ok = false;
    }
}

if ($ok) { copy($f, $f . '.bak5'); file_put_contents($f, $new); }

/* ============ 2b. admin/partials.php ============ */
$f = __DIR__ . '/admin/partials.php';
$s = file_get_contents($f);
$s = preg_replace('/^[^\n]*\'suggestions\'\s*=>[^\n]*\n?/m', '', $s);
$s = str_replace(" . (\$key === 'suggestions' ? ' sg-link' : '')", '', $s);
$s = preg_replace('/echo \'<style>\/\*SG\*\/.*?<\/style>\';\s*/s', '', $s);
$pok = true;

if (preg_match('/^[^\n]*\'manage-admins\'\s*=>\s*\[[^\n]*$/m', $s, $m)) {
    $s = str_replace($m[0], $m[0] . "\n        'suggestions'    => ['Suggestions',     'suggestions.php',     '" . $ICON . "'],", $s);
} else { $log[] = 'partials.php FAILED: manage-admins entry not found'; $pok = false; }

$cls = "class=\"admin-link' . \$active . '\"";
if ($pok && substr_count($s, $cls) === 1) {
    $s = str_replace($cls, "class=\"admin-link' . \$active . (\$key === 'suggestions' ? ' sg-link' : '') . '\"", $s);
} elseif ($pok) { $log[] = 'partials.php FAILED: link class anchor'; $pok = false; }

$a = "echo '<aside class=\"admin-side\">';";
if ($pok && substr_count($s, $a) === 1) {
    $css = "echo '<style>/*SG*/body .admin-nav .admin-link.sg-link{background:linear-gradient(135deg,#7B5CFF,#3F7CF4) !important;color:#fff !important;margin-top:10px;box-shadow:0 6px 16px rgba(91,63,208,.45);}body .admin-nav .admin-link.sg-link:hover{filter:brightness(1.1);}body .admin-nav .admin-link.sg-link svg{background:#fff !important;color:#5B3FD0 !important;}</style>';\n    ";
    $s = str_replace($a, $css . $a, $s);
} elseif ($pok) { $log[] = 'partials.php FAILED: aside anchor'; $pok = false; }

if ($pok) { copy($f, $f . '.bak5'); file_put_contents($f, $s); $log[] = 'partials.php: Suggestions link added'; }

/* ============ 3. rename stale, wrongly-named files ============ */
foreach (['admin/admin-dashboard.php', 'admin/admin-verifications.php'] as $stale) {
    $p = __DIR__ . '/' . $stale;
    if (is_file($p)) { rename($p, $p . '.old'); $log[] = "renamed $stale -> $stale.old"; }
    else $log[] = "$stale: not present (fine)";
}

echo implode("\n", $log) . "\n\nIf there is no FAILED line: press Ctrl+F5 on the admin panel, then DELETE patch_admin_fix.php.\nIf something says FAILED, copy that line to me.\n";
