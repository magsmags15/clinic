<?php
/**
 * Data endpoint for the staff portal. Every call is an AJAX POST with a JSON body:
 *   { action: "get" | "list" | "set" | "update" | "delete" | "stamps", c: "<collection>", ... }
 *
 * Security: signed-in staff only, CSRF token required, role rules per collection,
 * table/column names come from an allow-list, and all values go through prepared statements.
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

require_post();
same_origin_or_fail();
start_session();
$user = current_user();      // 401 if not signed in
check_csrf();                // 403 if the token is missing/wrong

$in     = body();
$action = (string)($in['action'] ?? '');
$pdo    = db();

/* Collections that used to be copies of other data. They no longer need their own table. */
const ALIAS_NOOP = ['online_bookings', 'contact_reservations', 'public_doctors'];

function real_table(string $name): ?string
{
    if (isset(TABLES[$name])) {
        return $name;
    }
    return match ($name) {
        'public_doctors'                           => 'users',
        'online_bookings', 'contact_reservations'  => 'bookings',
        default                                    => null,
    };
}

function row_to_doc(array $r): array
{
    $d = json_decode((string)$r['data'], true);
    return ['id' => $r['id'], 'data' => obj(is_array($d) ? $d : [])];
}

/** public_doctors used to be a tiny public copy of the dentists: only name + schedule. */
function doctor_view(array $doc): array
{
    $d = is_array($doc['data']) ? $doc['data'] : [];
    return ['id' => $doc['id'], 'data' => [
        'name'      => $d['name'] ?? '',
        'active'    => $d['active'] ?? true,
        'work_days' => $d['work_days'] ?? [1, 1, 1, 1, 1, 1, 1],
    ]];
}

function can_read(array $u, string $c): bool
{
    if (in_array($c, ['audit_log', 'deleted_users'], true)) {
        return is_admin($u);
    }
    return true;
}

function can_write(array $u, string $c, string $op, string $id, array $data): bool
{
    switch ($c) {
        case 'users':
            if (is_admin($u)) {
                return true;
            }
            // Anyone else may only change their own working days
            return $op === 'update' && $id === $u['id'] && array_keys($data) === ['work_days'];
        case 'deleted_users':
            return is_admin($u);
        case 'services':
            return $op !== 'delete' || is_admin($u);
        case 'audit_log':
            return $op === 'set';
        default:
            return true;       // patients, bookings, bills, treatments, unavailability, notifications
    }
}

function validate_doc(string $t, array $data): void
{
    if (strlen((string)json_encode($data)) > 262144) {
        fail('Record is too large.', 413, 'too-large');
    }
    foreach (TABLES[$t] as $col) {
        $v = scal($data[$col] ?? null);
        if ($v !== null && mb_strlen($v) > 255) {
            fail("The field \"$col\" is too long.", 422, 'invalid-field');
        }
    }
    if (in_array($t, ['bookings', 'unavailability'], true)
        && isset($data['date']) && is_string($data['date']) && $data['date'] !== ''
        && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'])) {
        fail('Dates must look like YYYY-MM-DD.', 422, 'invalid-date');
    }
    if ($t === 'users') {
        if (isset($data['role']) && !in_array($data['role'], STAFF_ROLES, true)) {
            fail('Invalid role.', 422, 'invalid-role');
        }
        if (isset($data['email']) && !filter_var((string)$data['email'], FILTER_VALIDATE_EMAIL)) {
            fail('Invalid email address.', 422, 'invalid-email');
        }
        if (isset($data['name']) && (mb_strlen((string)$data['name']) < 2 || mb_strlen((string)$data['name']) > 60)) {
            fail('Name must be 2 to 60 characters.', 422, 'invalid-name');
        }
        if (isset($data['work_days']) && (!is_array($data['work_days']) || count($data['work_days']) !== 7)) {
            fail('Working days must have 7 entries.', 422, 'invalid-work-days');
        }
    }
    if ($t === 'patients' && isset($data['name']) && mb_strlen((string)$data['name']) > 120) {
        fail('Patient name is too long.', 422, 'invalid-name');
    }
}

function fetch_data(PDO $pdo, string $t, string $id, bool $lock = false): ?array
{
    $st = $pdo->prepare("SELECT data FROM `$t` WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    $raw = $st->fetchColumn();
    if ($raw === false) {
        return null;
    }
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}

function save_doc(PDO $pdo, string $t, string $id, array $data): void
{
    $cols  = TABLES[$t];
    $names = array_merge($cols, ['data']);
    $vals  = [];
    foreach ($cols as $c) {
        $vals[] = scal($data[$c] ?? null);
    }
    $vals[] = json_encode(obj($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($t === 'users') {                                   // logins are created by auth.php only
        $set    = implode(', ', array_map(fn($n) => "`$n` = ?", $names));
        $vals[] = $id;
        $pdo->prepare("UPDATE `users` SET $set WHERE id = ?")->execute($vals);
        return;
    }
    $all = array_merge(['id'], $names);
    $cl  = implode(', ', array_map(fn($n) => "`$n`", $all));
    $ph  = implode(', ', array_fill(0, count($all), '?'));
    $up  = implode(', ', array_map(fn($n) => "`$n` = VALUES(`$n`)", $names));
    $pdo->prepare("INSERT INTO `$t` ($cl) VALUES ($ph) ON DUPLICATE KEY UPDATE $up")
        ->execute(array_merge([$id], $vals));
}

/** Build "WHERE ..." from [{f, op:"==", v}] constraints (only equality is supported). */
function build_where(string $t, $wheres, array &$params): string
{
    if (!is_array($wheres) || !$wheres) {
        return '';
    }
    $parts = [];
    foreach ($wheres as $w) {
        $f  = (string)($w['field'] ?? '');
        $op = (string)($w['op'] ?? '');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,60}$/', $f) || $op !== '==') {
            fail('Unsupported filter.', 400, 'bad-filter');
        }
        $v = scal($w['value'] ?? null);
        if (in_array($f, TABLES[$t], true)) {
            if ($v === null) {
                $parts[] = "`$f` IS NULL";
            } else {
                $parts[]  = "`$f` = ?";
                $params[] = $v;
            }
        } else {                                              // field that only lives inside the JSON
            $expr = "JSON_UNQUOTE(JSON_EXTRACT(data, '$.$f'))";
            if ($v === null) {
                $parts[] = "$expr IS NULL";
            } else {
                $parts[]  = "$expr = ?";
                $params[] = $v;
            }
        }
    }
    return ' WHERE ' . implode(' AND ', $parts);
}

/* ====================================================================== stamps (change detection for live refresh) */
if ($action === 'stamps') {
    $names = is_array($in['c'] ?? null) ? $in['c'] : [];
    $out   = [];
    foreach ($names as $n) {
        $n = (string)$n;
        $t = real_table($n);
        if ($t === null || !can_read($user, $t)) {
            continue;
        }
        $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(MAX(updated_at), '0') AS m FROM `$t`")->fetch();
        $out[$n] = $r['n'] . '|' . $r['m'];
    }
    respond(['ok' => true, 'stamps' => $out]);
}

/* ====================================================================== per-collection actions */
$c = (string)($in['c'] ?? '');
$t = real_table($c);
if ($t === null) {
    fail('Unknown collection.', 400, 'bad-collection');
}
$isAlias = in_array($c, ALIAS_NOOP, true);

if (in_array($action, ['get', 'list'], true)) {
    if (!can_read($user, $t)) {
        fail('You do not have access to this data.', 403, 'forbidden');
    }
}

/* ---------- get ---------- */
if ($action === 'get') {
    $id = (string)($in['id'] ?? '');
    if (!valid_id($id)) {
        fail('Invalid id.', 400, 'bad-id');
    }
    if ($c === 'contact_reservations') {
        respond(['ok' => true, 'exists' => false, 'data' => null]);
    }
    $st = $pdo->prepare("SELECT id, data FROM `$t` WHERE id = ?" . ($c === 'public_doctors' ? " AND role = 'dentist'" : ''));
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        respond(['ok' => true, 'exists' => false, 'data' => null]);
    }
    $doc = row_to_doc($r);
    if ($c === 'public_doctors') {
        $doc = doctor_view($doc);
    }
    respond(['ok' => true, 'exists' => true, 'data' => $doc['data']]);
}

/* ---------- list (optionally filtered) ---------- */
if ($action === 'list') {
    if ($c === 'contact_reservations') {
        respond(['ok' => true, 'docs' => []]);
    }
    $params = [];
    $where  = build_where($t, $in['where'] ?? [], $params);
    if ($c === 'public_doctors') {
        $where .= ($where === '' ? ' WHERE' : ' AND') . " role = 'dentist'";
    } elseif ($c === 'online_bookings') {
        $where .= ($where === '' ? ' WHERE' : ' AND') . " walkin = 'false'";
    }
    $st = $pdo->prepare("SELECT id, data FROM `$t`$where ORDER BY id ASC LIMIT 20000");
    $st->execute($params);
    $docs = array_map('row_to_doc', $st->fetchAll());
    if ($c === 'public_doctors') {
        $docs = array_map('doctor_view', $docs);
    }
    respond(['ok' => true, 'docs' => $docs]);
}

/* ---------- writes ---------- */
if (in_array($action, ['set', 'update', 'delete'], true)) {
    $id = (string)($in['id'] ?? '');
    if (!valid_id($id)) {
        fail('Invalid id.', 400, 'bad-id');
    }
    $data = is_array($in['data'] ?? null) ? $in['data'] : [];

    if ($isAlias) {                                           // nothing to store: these were mirror copies
        respond(['ok' => true, 'ignored' => true]);
    }
    if (!can_write($user, $c, $action, $id, $data)) {
        fail('You do not have permission to do that.', 403, 'forbidden');
    }

    if ($action === 'delete') {
        if ($c === 'users' && $id === $user['id']) {
            fail('You cannot delete your own account.', 409, 'self-delete');
        }
        $pdo->prepare("DELETE FROM `$t` WHERE id = ?")->execute([$id]);
        respond(['ok' => true]);
    }

    validate_doc($t, $data);

    $pdo->beginTransaction();
    try {
        $cur = fetch_data($pdo, $t, $id, true);
        if ($action === 'update') {
            if ($cur === null) {
                $pdo->rollBack();
                fail('Record not found.', 404, 'not-found');
            }
            $new = array_replace($cur, $data);
        } else {                                              // set (optionally merged)
            $merge = !empty($in['merge']);
            $new   = ($merge && $cur !== null) ? array_replace($cur, $data) : $data;
        }
        if ($t === 'users') {
            if ($cur === null) {
                $pdo->rollBack();
                fail('Create staff accounts from the Staff panel.', 403, 'forbidden');
            }
            // role / email / active of an existing login may only be changed by an admin (checked above)
            validate_doc('users', $new);
        }
        save_doc($pdo, $t, $id, $new);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    respond(['ok' => true]);
}

fail('Unknown action.', 400, 'bad-action');
