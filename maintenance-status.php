<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

/**
 * maintenance-status.php — tiny public JSON endpoint polled by
 * maintenance.php so the "Server is under maintenance" page can send
 * people back in the moment maintenance ends (or an admin marks it done).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

$m = maintenance_current();
echo json_encode([
    'active'    => $m !== null,
    'title'     => $m['title'] ?? null,
    'ends_at'   => $m['ends_at'] ?? null,
    'secs_left' => ($m && $m['secs_left'] !== null) ? max(0, (int) $m['secs_left']) : null,
]);
