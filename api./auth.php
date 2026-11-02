<?php
/**
 * Staff authentication (PHP sessions + password_hash).
 *
 * POST JSON { action: "me" | "login" | "logout" | "create_staff", ... }
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_post();
same_origin_or_fail();
start_session();

$in     = body();
$action = (string)($in['action'] ?? '');
$pdo    = db();

/* ---------- who am I? (also hands the browser its CSRF token) ---------- */
if ($action === 'me') {
    $u = current_user(false);
    respond([
        'ok'   => true,
        'csrf' => csrf_token(),
        'user' => $u ? ['uid' => $u['id'], 'email' => $u['email']] : null,
    ]);
}

/* ---------- login ---------- */
if ($action === 'login') {
    $email = trim((string)($in['email'] ?? ''));
    $pass  = (string)($in['password'] ?? '');
    if ($email === '' || $pass === '' || strlen($email) > 255 || strlen($pass) > 200) {
        fail('Invalid work email or password.', 401, 'auth/invalid-credential');
    }
    $ip      = substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45);
    $emailLc = mb_strtolower($email);
    $cut     = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MIN * 60);

    // Lock out repeated failures (per email and per IP)
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > ?');
    $st->execute([$emailLc, $cut]);
    $byEmail = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?');
    $st->execute([$ip, $cut]);
    $byIp = (int)$st->fetchColumn();
    if ($byEmail >= LOGIN_MAX_FAILS || $byIp >= LOGIN_MAX_FAILS * 4) {
        fail('Too many attempts. Please wait a few minutes and try again.', 429, 'auth/too-many-requests');
    }

    $st = $pdo->prepare('SELECT id, role, active, password_hash FROM users WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();

    if (!$row || !password_verify($pass, $row['password_hash'])) {
        $pdo->prepare('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)')
            ->execute([$emailLc, $ip, date('Y-m-d H:i:s')]);
        fail('Invalid work email or password.', 401, 'auth/invalid-credential');
    }
    if (!is_active_row($row) || !in_array($row['role'], STAFF_ROLES, true)) {
        fail('Your account is deactivated or not set up. Contact the admin.', 403, 'auth/user-disabled');
    }

    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$emailLc]);

    session_regenerate_id(true);                       // prevents session fixation
    $_SESSION['uid']  = $row['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    respond(['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => ['uid' => $row['id'], 'email' => $email]]);
}

/* ---------- logout ---------- */
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    respond(['ok' => true]);
}

/* ---------- admin creates a staff login ---------- */
if ($action === 'create_staff') {
    $me = current_user();
    check_csrf();
    if (!is_admin($me)) {
        fail('Only the admin can create staff accounts.', 403, 'forbidden');
    }

    $name  = trim((string)($in['name'] ?? ''));
    $email = trim((string)($in['email'] ?? ''));
    $role  = (string)($in['role'] ?? '');
    $pass  = (string)($in['password'] ?? '');

    if (!preg_match('/^\p{L}[\p{L}\s.\'’-]{1,59}$/u', $name)) {
        fail('Invalid name. Use letters only (spaces, . \' and - are allowed), 2 to 60 characters.', 422, 'invalid-name');
    }
    if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Invalid email address. Example: name@gmail.com', 422, 'invalid-email');
    }
    if (!in_array($role, ['admin', 'dentist', 'receptionist'], true)) {
        fail('Please choose a valid role.', 422, 'invalid-role');
    }
    if (strlen($pass) < 10 || strlen($pass) > 100 || preg_match('/\s/', $pass)) {
        fail('The password must be at least 10 characters with no spaces.', 422, 'weak-password');
    }

    $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetchColumn()) {
        fail('An account with this email already exists.', 409, 'auth/email-already-in-use');
    }

    $id   = make_id($name);
    $data = [
        'name'       => $name,
        'email'      => $email,
        'role'       => $role,
        'active'     => true,
        'work_days'  => [1, 1, 1, 1, 1, 1, 1],
        'created_at' => date('c'),
        'created_by' => $me['id'],
    ];
    $pdo->prepare('INSERT INTO users (id, name, email, role, active, password_hash, data) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $id, $name, $email, $role, 'true',
            password_hash($pass, PASSWORD_DEFAULT),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    respond(['ok' => true, 'uid' => $id]);
}

fail('Unknown action.', 400, 'bad-action');
