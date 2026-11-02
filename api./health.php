<?php
/**
 * Quick check: open https://YOUR-SITE/api/health.php
 * Shows only yes/no and counts (no passwords, no data). Safe to leave, or delete when finished.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$out = ['ok' => true, 'php' => PHP_VERSION, 'pdo_mysql' => extension_loaded('pdo_mysql'), 'db' => false];
try {
    $pdo = db();
    $out['db']       = true;
    $out['services'] = (int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn();
    $out['dentists'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'dentist' AND (active IS NULL OR active <> 'false')")->fetchColumn();
} catch (Throwable $e) {
    $out['ok']          = false;
    $out['error_class'] = get_class($e);
    $out['error_code']  = ($e instanceof PDOException) ? (int)($e->errorInfo[1] ?? 0) : (int)$e->getCode();
    $out['hint']        = 'Check api/config.local.php (or DB_* environment variables) and that database/schema.sql was imported.';
}
respond($out);
