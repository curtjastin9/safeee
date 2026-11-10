<?php
/**
 * patch_sysanalytics.php — run once:
 *   http://localhost/safetrade/patch_sysanalytics.php
 * Fixes the broken sidebar/layout on Admin > System Analytics.
 * Backs up admin/dashboard.php to admin/dashboard.php.bak4. DELETE THIS FILE afterwards.
 */
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/admin/dashboard.php';
if (!is_file($file)) exit("admin/dashboard.php not found.\n");
$src = file_get_contents($file);

if (strpos($src, "sys_rows") !== false) exit("Already patched — nothing to do.\n");

/* ---------- 1. data block ---------- */
$startMarker = "if (\$tab === 'sys_analytics') {";
$endMarker   = "function tab_link(";
$s = strpos($src, $startMarker);
$e = strpos($src, $endMarker);
if ($s === false || $e === false || $e < $s) exit("FAILED: could not find the sys_analytics data block. Nothing changed.\n");

$newData = <<<'PHPCODE'
if ($tab === 'sys_analytics') {
    $data['sys_php']        = (string) phpversion();
    $data['sys_os']         = php_uname('s') . ' ' . php_uname('r');
    $data['sys_memory']     = (string) ini_get('memory_limit');
    $data['sys_upload']     = (string) ini_get('upload_max_filesize');
    $data['sys_extensions'] = implode(', ', array_filter(['gd','mysqli','mbstring','json','curl'], 'extension_loaded'));

    $data['sys_db_size'] = '—';
    $data['sys_rows']    = [];
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
            $data['sys_rows'][] = ['name' => (string) $r['tname'], 'rows' => (int) $r['trows']];
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] sys_analytics failed: ' . $e->getMessage());
    }
}

PHPCODE;
$src = substr($src, 0, $s) . $newData . substr($src, $e);

/* ---------- 2. table rows in the view ---------- */
$loopStart = "<?php while (\$r = \$data['sys_row_counts']->fetch_assoc()): ?>";
$ls = strpos($src, $loopStart);
if ($ls === false) exit("FAILED: could not find the table rows loop. Nothing changed.\n");
$endwhile = "<?php endwhile; ?>";
$le = strpos($src, $endwhile, $ls);
if ($le === false) exit("FAILED: could not find the end of the table rows loop. Nothing changed.\n");
$le += strlen($endwhile);

$newLoop = <<<'PHPCODE'
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
$src = substr($src, 0, $ls) . $newLoop . substr($src, $le);

copy($file, $file . '.bak4');
file_put_contents($file, $src);
echo "Done. Backup saved as admin/dashboard.php.bak4.\nPress Ctrl+F5 on Admin > System Analytics, then delete patch_sysanalytics.php.\n";
