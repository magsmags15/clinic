<?php
/**
 * Public endpoint for the online booking page (index.html). No login required, so it only
 * exposes the minimum a patient needs and validates everything again on the server.
 *
 *   bootstrap          -> active dentists (name + working days) and the services list
 *   unavailability     -> which dentists are off on a date
 *   patient_lookup     -> is this an existing patient?
 *   patient_has_braces -> has this patient already availed of braces?
 *   create_booking     -> validate and save an online booking
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in     = $method === 'POST' ? body() : [];
$action = (string)($in['action'] ?? $_GET['action'] ?? '');
// Read-only public data (dentists, services, who is off) is harmless to anyone, so it is never blocked by the
// origin check (hosting proxies / www vs non-www can make the Origin and Host differ). Writes still are checked.
if (!in_array($action, ['bootstrap', 'unavailability'], true)) {
    same_origin_or_fail();
}
$pdo    = db();

/* ---- rules shared with the booking page ---- */
const OLD_PATIENT_ONLY_RX = ['/braces?\s*adjust/i', '/bracket\s*re-?\s*attach/i'];
const DOCTOR_ONLY_RX      = ['/wire/i'];
const BRACES_AVAILED_RX   = '/(traditional|common|metal|self[\s-]*ligat|braces?)/i';
const NOT_AVAILED         = ['cancelled', 'canceled', 'no-show', 'no show', 'noshow', 'declined', 'rejected'];
const CUTOFF_MINUTES      = 18 * 60;      // online reservations close at 6:00 PM

/* Same list the staff portal seeds. If the services table is empty, the booking page would show nothing,
   so the server fills it once (fixed ids, so it can never create duplicates). */
const DEFAULT_SERVICES = [
    ['category' => 'General', 'name' => 'Oral Prophylaxis (Cleaning)', 'min_price' => 800, 'max_price' => 1500, 'price_range' => 'Depends on plaque buildup'],
    ['category' => 'General', 'name' => 'Restoration (Pasta)', 'min_price' => 1000, 'max_price' => 2500, 'price_range' => 'Per tooth / Surface', 'clinical_input' => 'surfaces'],
    ['category' => 'General', 'name' => 'Extraction (Bunot)', 'min_price' => 500, 'max_price' => 1500, 'price_range' => 'Per tooth', 'clinical_input' => 'teeth'],
    ['category' => 'Prosthodontics', 'name' => 'Dentures', 'min_price' => 8000, 'max_price' => 15000, 'price_range' => 'Upper/Lower set'],
    ['category' => 'Prosthodontics', 'name' => 'Jacket Crown', 'min_price' => 5000, 'max_price' => 8000, 'price_range' => 'Per tooth', 'clinical_input' => 'teeth'],
    ['category' => 'Prosthodontics', 'name' => 'Fixed Bridge', 'min_price' => 12000, 'max_price' => 20000, 'price_range' => '3-unit bridge'],
    ['category' => 'Aesthetics', 'name' => 'Veneers (Zirconia)', 'min_price' => 7000, 'max_price' => 12000, 'price_range' => 'Per tooth', 'clinical_input' => 'teeth'],
    ['category' => 'Aesthetics', 'name' => 'Tooth Whitening', 'min_price' => 3500, 'max_price' => 7000, 'price_range' => 'Full session'],
    ['category' => 'Orthodontics', 'name' => 'Braces Installation (Common)', 'min_price' => 30000, 'max_price' => 40000, 'price_range' => 'Upper and Lower', 'allow_partial' => true],
    ['category' => 'Orthodontics', 'name' => 'Braces Installation (Self-Ligating)', 'min_price' => 45000, 'max_price' => 60000, 'price_range' => 'Upper and Lower', 'allow_partial' => true],
    ['category' => 'Orthodontics', 'name' => 'Braces Adjustment (Old Patient)', 'min_price' => 0, 'max_price' => 0, 'price_range' => 'No charge (free)'],
    ['category' => 'Orthodontics', 'name' => 'Bracket Reattachment', 'min_price' => 1000, 'max_price' => 1000, 'price_range' => 'Per bracket', 'clinical_input' => 'teeth', 'unit_price' => 1000],
    ['category' => 'Orthodontics', 'name' => 'Broken Wire Replacement (Add-on)', 'min_price' => 300, 'max_price' => 300, 'price_range' => 'Add-on • fixed price (old braces patients only)'],
];

function ensure_default_services(PDO $pdo): void
{
    try {
        if ((int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() > 0) {
            return;
        }
        $ins = $pdo->prepare('INSERT IGNORE INTO services (id, name, data) VALUES (?, ?, ?)');
        foreach (DEFAULT_SERVICES as $svc) {
            $ins->execute([
                'seed_' . substr(md5($svc['name']), 0, 12),
                $svc['name'],
                json_encode($svc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    } catch (Throwable $e) {
        error_log('[clinic-api] could not seed services: ' . $e->getMessage());
    }
}

function matches_any(array $patterns, string $s): bool
{
    foreach ($patterns as $rx) {
        if (preg_match($rx, $s)) {
            return true;
        }
    }
    return false;
}

function clean_name(string $s): string
{
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

function valid_person_name(string $n): bool
{
    return (bool)preg_match('/^\p{L}[\p{L}\s.\'’-]{1,59}$/u', $n)
        && preg_match_all('/\p{L}/u', $n) >= 2;
}

function patient_has_braces(PDO $pdo, string $name): bool
{
    $key = name_key($name);

    $st = $pdo->prepare('SELECT data FROM patients WHERE name_key = ? OR LOWER(TRIM(name)) = ?');
    $st->execute([$key, $key]);
    foreach ($st->fetchAll() as $r) {
        $d = json_decode((string)$r['data'], true);
        if (is_array($d) && ($d['has_braces'] ?? false) === true) {
            return true;
        }
    }

    $st = $pdo->prepare('SELECT data FROM bookings WHERE name_key = ?');
    $st->execute([$key]);
    foreach ($st->fetchAll() as $r) {
        $b = json_decode((string)$r['data'], true);
        if (!is_array($b)) {
            continue;
        }
        $status   = strtolower(trim((string)($b['status'] ?? '')));
        $attended = ($b['verified'] ?? false) === true || in_array($status, ['done', 'completed'], true);
        $svc      = (string)($b['service'] ?? '');
        $isBraces = preg_match(BRACES_AVAILED_RX, $svc) && !matches_any(OLD_PATIENT_ONLY_RX, $svc);
        if ($isBraces && $attended && !in_array($status, NOT_AVAILED, true)) {
            return true;
        }
    }
    return false;
}

/* ====================================================================== bootstrap */
if ($action === 'bootstrap') {
    ensure_default_services($pdo);

    $doctors = [];
    foreach ($pdo->query("SELECT id, name, data FROM users WHERE role = 'dentist' AND (active IS NULL OR active <> 'false') ORDER BY name")->fetchAll() as $r) {
        $d    = json_decode((string)$r['data'], true);
        $d    = is_array($d) ? $d : [];
        $days = (isset($d['work_days']) && is_array($d['work_days']) && count($d['work_days']) === 7) ? array_values($d['work_days']) : [1, 1, 1, 1, 1, 1, 1];
        $doctors[] = [
            'id'        => $r['id'],
            'name'      => (string)(($d['name'] ?? '') !== '' ? $d['name'] : $r['name']),
            'work_days' => $days,
        ];
    }

    $services = [];
    foreach ($pdo->query('SELECT id, name, data FROM services')->fetchAll() as $r) {
        $d = json_decode((string)$r['data'], true);
        $d = is_array($d) ? $d : [];
        if (($d['name'] ?? '') === '') {
            $d['name'] = (string)$r['name'];
        }
        if ($d['name'] === '') {
            continue;
        }
        $services[] = array_merge($d, ['id' => $r['id']]);
    }
    // keep the clinic's usual order (ids are random); services added later go last
    $order = array_flip(array_column(DEFAULT_SERVICES, 'name'));
    usort($services, function ($a, $b) use ($order) {
        $x = $order[$a['name']] ?? 999;
        $y = $order[$b['name']] ?? 999;
        return $x <=> $y ?: strcmp((string)$a['name'], (string)$b['name']);
    });

    respond(['ok' => true, 'doctors' => $doctors, 'services' => $services]);
}

/* ====================================================================== unavailability */
if ($action === 'unavailability') {
    $date = (string)($in['date'] ?? $_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fail('Invalid date.', 422, 'invalid-date');
    }
    $st = $pdo->prepare('SELECT doc_id FROM unavailability WHERE date = ?');
    $st->execute([$date]);
    respond(['ok' => true, 'doc_ids' => array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN)))]);
}

/* ====================================================================== patient lookup */
if ($action === 'patient_lookup' || $action === 'patient_has_braces') {
    require_post();
    throttle('lookup', 60, 600);
    $name = clean_name((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        fail('Please enter your full name.', 422, 'invalid-name');
    }
    if ($action === 'patient_has_braces') {
        respond(['ok' => true, 'has_braces' => patient_has_braces($pdo, $name)]);
    }

    $key = name_key($name);
    $st  = $pdo->prepare('SELECT name, data FROM patients WHERE name_key = ? OR LOWER(TRIM(name)) = ?');
    $st->execute([$key, $key]);
    $patients = [];
    foreach ($st->fetchAll() as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        $patients[] = ['name' => (string)$r['name'], 'has_braces' => ($d['has_braces'] ?? false) === true];
    }
    $bookingName = null;
    if (!$patients) {
        $st = $pdo->prepare('SELECT patient_name FROM bookings WHERE name_key = ? LIMIT 1');
        $st->execute([$key]);
        $bn = $st->fetchColumn();
        $bookingName = $bn === false ? null : (string)$bn;
    }
    respond(['ok' => true, 'patients' => $patients, 'bookingName' => $bookingName]);
}

/* ====================================================================== create booking */
if ($action === 'create_booking') {
    require_post();
    throttle('booking', 8, 3600);

    $name    = clean_name((string)($in['name'] ?? ''));
    $email   = trim((string)($in['email'] ?? ''));
    $mobile  = trim((string)($in['mobile'] ?? ''));
    $date    = (string)($in['date'] ?? '');
    $docId   = (string)($in['doc_id'] ?? '');
    $service = trim((string)($in['service'] ?? ''));

    if (!valid_person_name($name)) {
        fail('Please enter a valid full name (letters only).', 422, 'invalid-name');
    }
    if (!preg_match('/^(09\d{9}|\+639\d{9})$/', $mobile)) {
        fail('Please enter a valid mobile number that starts with 09 or +63.', 422, 'invalid-mobile');
    }
    $mobile = '0' . substr($mobile, -10);
    if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || str_contains($email, '..')) {
        fail('Please enter a valid email address.', 422, 'invalid-email');
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        fail('Please choose a valid date.', 422, 'invalid-date');
    }
    $today = date('Y-m-d');
    if ($date < $today || $date > date('Y-m-d', strtotime('+366 days'))) {
        fail('That date is not available for booking.', 422, 'invalid-date');
    }
    if ($date === $today && ((int)date('G') * 60 + (int)date('i')) >= CUTOFF_MINUTES) {
        fail('Online reservations for today closed at 6:00 PM. Please choose another date.', 422, 'cutoff');
    }

    if (!valid_id($docId)) {
        fail('Please choose a dentist.', 422, 'invalid-doctor');
    }
    $st = $pdo->prepare("SELECT name, active, data FROM users WHERE id = ? AND role = 'dentist'");
    $st->execute([$docId]);
    $doc = $st->fetch();
    if (!$doc || $doc['active'] === 'false') {
        fail('The selected dentist is not available.', 422, 'invalid-doctor');
    }
    $dd   = json_decode((string)$doc['data'], true) ?: [];
    $days = $dd['work_days'] ?? [1, 1, 1, 1, 1, 1, 1];
    if (($days[(int)$dt->format('w')] ?? 0) != 1) {
        fail('The dentist does not work on that day.', 422, 'doctor-off');
    }
    $st = $pdo->prepare('SELECT 1 FROM unavailability WHERE doc_id = ? AND date = ?');
    $st->execute([$docId, $date]);
    if ($st->fetchColumn()) {
        fail('The dentist is unavailable on that date.', 422, 'doctor-off');
    }

    $st = $pdo->prepare('SELECT 1 FROM services WHERE name = ?');
    $st->execute([$service]);
    if ($service === '' || !$st->fetchColumn()) {
        fail('Please choose a service from the list.', 422, 'invalid-service');
    }
    if (matches_any(DOCTOR_ONLY_RX, $service)) {
        fail('This service can only be added by your dentist.', 422, 'service-restricted');
    }
    if (matches_any(OLD_PATIENT_ONLY_RX, $service) && !patient_has_braces($pdo, $name)) {
        fail('This service is only for patients who have already availed of braces.', 422, 'service-restricted');
    }

    // Same person, same day, same service: do not create a double booking
    $st = $pdo->prepare("SELECT data FROM bookings WHERE name_key = ? AND date = ? AND status IN ('Scheduled', 'Rescheduled')");
    $st->execute([name_key($name), $date]);
    foreach ($st->fetchAll() as $r) {
        $b = json_decode((string)$r['data'], true);
        if (is_array($b) && ($b['service'] ?? '') === $service) {
            fail('You already have this booking. Please check your messages.', 409, 'duplicate');
        }
    }

    $now  = date('c');
    $id   = make_id($date . ' ' . $name);
    $data = [
        'patient_name'   => $name,
        'name_key'       => name_key($name),
        'email'          => $email,
        'contact_number' => $mobile,
        'date'           => $date,
        'doc_id'         => $docId,
        'doc_name'       => (string)$doc['name'],
        'service'        => $service,
        'status'         => 'Scheduled',
        'walkin'         => false,
        'timestamp'      => $now,
        'booked_at'      => $now,
        'booked_time'    => date('g:i A'),
    ];
    $pdo->prepare(
        'INSERT INTO bookings (id, patient_id, patient_name, name_key, doc_id, date, status, walkin, data)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $id, $name, name_key($name), $docId, $date, 'Scheduled', 'false',
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    respond(['ok' => true, 'id' => $id, 'doc_name' => (string)$doc['name']]);
}

fail('Unknown action.', 400, 'bad-action');
