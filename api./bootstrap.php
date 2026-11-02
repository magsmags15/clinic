<?php
/**
 * Shared helpers for every API endpoint: JSON responses, PDO connection, sessions, CSRF,
 * input validation, and the table map used by records.php.
 */
declare(strict_types=1);

require __DIR__ . '/config.php';

ini_set('display_errors', '0');          // never leak PHP errors to the browser
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const STAFF_ROLES = ['admin', 'owner', 'receptionist', 'dentist'];

/**
 * Every collection is one MySQL table: id + a few indexed key columns + the full record as JSON in "data".
 * The key columns are copied out of the JSON on every write so they can be searched and indexed fast.
 */
const TABLES = [
    'users'          => ['name', 'email', 'role', 'active'],
    'patients'       => ['name', 'name_key', 'contact_number', 'email'],
    'services'       => ['name'],
    'bookings'       => ['patient_id', 'patient_name', 'name_key', 'doc_id', 'date', 'status', 'walkin'],
    'bills'          => ['booking_id', 'patient_id', 'patient_name', 'doc_id', 'date', 'status'],
    'treatments'     => ['booking_id', 'patient_id', 'patient_name', 'doc_id'],
    'unavailability' => ['doc_id', 'date'],
    'notifications'  => ['status', 'type', 'channel'],
    'audit_log'      => [],
    'deleted_users'  => [],
];

/* ---------------------------------------------------------------- responses */

function respond(array $payload, int $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail(string $message, int $status = 400, string $code = 'error')
{
    respond(['ok' => false, 'error' => $message, 'code' => $code], $status);
}

set_exception_handler(function (Throwable $e) {
    error_log('[clinic-api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
        fail('That record already exists.', 409, 'duplicate');
    }
    if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1044, 1045, 1049, 2002, 2003, 2006], true)) {
        fail('Cannot connect to the database. Check DB_HOST, DB_NAME, DB_USER and DB_PASS in api/config.php (use the values from your hosting) and that the tables were imported.', 500, 'db-connect');
    }
    if ($e instanceof PDOException && in_array((string)$e->getCode(), ['42S02', '42S22'], true)) {
        fail('A database table is missing. Import database/schema.sql in phpMyAdmin.', 500, 'db-tables');
    }
    fail('Server error. Please try again.', 500, 'server-error');
});

/* ---------------------------------------------------------------- database */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/* ---------------------------------------------------------------- request helpers */

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        fail('POST required.', 405, 'method');
    }
}

function body(): array
{
    static $parsed = null;
    if ($parsed !== null) {
        return $parsed;
    }
    // Some hosting firewalls block JSON POST bodies, so the page may send the same JSON as a form field "payload".
    if (isset($_POST['payload']) && is_string($_POST['payload'])) {
        $raw = $_POST['payload'];
    } else {
        $raw = file_get_contents('php://input');
    }
    if ($raw === false || $raw === '') {
        $parsed = [];
        return $parsed;
    }
    if (strlen($raw) > 1048576) {
        fail('Request too large.', 413, 'too-large');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail('Invalid JSON body.', 400, 'bad-json');
    }
    $parsed = $data;
    return $parsed;
}

/** Reject requests that come from another website (basic CSRF defence for anonymous endpoints). */
function same_origin_or_fail(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }
    $host = (string)parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    $originHost = $host . ($port ? ':' . $port : '');
    if ($originHost !== ($_SERVER['HTTP_HOST'] ?? '')) {
        fail('Cross-site request blocked.', 403, 'cross-site');
    }
}

function valid_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,120}$/', $id);
}

/** Convert any JSON scalar into the string form stored in the indexed key columns. */
function scal($v): ?string
{
    if ($v === null) {
        return null;
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_scalar($v)) {
        return (string)$v;
    }
    return null;
}

function obj(array $a)
{
    return $a === [] ? new stdClass() : $a;
}

function name_key(string $s): string
{
    return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s)));
}

function make_id(string $label): string
{
    $s = $label;
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) {
            $s = $t;
        }
    }
    $s = strtolower($s);
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = substr($s, 0, 40);
    if ($s === '') {
        $s = 'record';
    }
    return $s . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
}

/* ---------------------------------------------------------------- sessions, auth, CSRF */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('mbclinic');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    start_session();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !is_string($sent) || !hash_equals($_SESSION['csrf'], $sent)) {
        fail('Your session token expired. Please refresh the page.', 403, 'csrf');
    }
}

function is_active_row(array $row): bool
{
    return ($row['active'] ?? null) !== 'false';
}

/** The signed-in staff member (fresh from the DB on every request, so deactivation takes effect at once). */
function current_user(bool $required = true): ?array
{
    start_session();
    $uid = $_SESSION['uid'] ?? null;
    if (!is_string($uid) || $uid === '') {
        if ($required) {
            fail('Not signed in.', 401, 'unauthenticated');
        }
        return null;
    }
    $st = db()->prepare('SELECT id, name, email, role, active, data FROM users WHERE id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row || !is_active_row($row) || !in_array($row['role'], STAFF_ROLES, true)) {
        $_SESSION = [];
        session_destroy();
        if ($required) {
            fail('Your account is deactivated or no longer exists.', 401, 'unauthenticated');
        }
        return null;
    }
    return $row;
}

function is_admin(array $u): bool
{
    return in_array($u['role'], ['admin', 'owner'], true);
}

/* ---------------------------------------------------------------- misc */

/** Simple per-IP rate limit for public endpoints. Fails with HTTP 429 when the limit is exceeded. */
function throttle(string $bucket, int $max, int $windowSec): void
{
    $ip  = substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45);
    $pdo = db();
    $cut = date('Y-m-d H:i:s', time() - $windowSec);
    $st  = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND ip = ? AND created_at > ?');
    $st->execute([$bucket, $ip, $cut]);
    if ((int)$st->fetchColumn() >= $max) {
        fail('Too many requests. Please wait a few minutes and try again.', 429, 'rate-limit');
    }
    $pdo->prepare('INSERT INTO rate_limits (bucket, ip, created_at) VALUES (?, ?, ?)')
        ->execute([$bucket, $ip, date('Y-m-d H:i:s')]);
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM rate_limits WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    }
}
