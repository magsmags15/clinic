<?php
/**
 * Database settings.
 *
 * Do NOT put your real password in this file (it is committed to GitHub).
 * Use ONE of these instead:
 *   1) create  api/config.local.php  on the server (copy config.local.example.php). It is git-ignored.
 *   2) set the environment variables DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS in your hosting panel.
 * With neither, the defaults below match a fresh XAMPP install (local testing only).
 */
$__local = __DIR__ . '/config.local.php';
$__cfg   = is_file($__local) ? require $__local : [];
if (!is_array($__cfg)) {
    $__cfg = [];
}
$__pick = static function (string $key, string $env, $default) use ($__cfg) {
    if (isset($__cfg[$key]) && $__cfg[$key] !== '') {
        return $__cfg[$key];
    }
    $v = getenv($env);
    return ($v !== false && $v !== '') ? $v : $default;
};

define('DB_HOST', (string)$__pick('host', 'DB_HOST', '127.0.0.1'));
define('DB_PORT', (int)$__pick('port', 'DB_PORT', 3306));
define('DB_NAME', (string)$__pick('name', 'DB_NAME', 'mb_pagaduan_clinic'));
define('DB_USER', (string)$__pick('user', 'DB_USER', 'root'));
define('DB_PASS', (string)(isset($__cfg['pass']) ? $__cfg['pass'] : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '')));

// Brute-force protection for the staff login
const LOGIN_MAX_FAILS  = 5;    // failed tries allowed ...
const LOGIN_WINDOW_MIN = 15;   // ... within this many minutes

date_default_timezone_set('Asia/Manila');
