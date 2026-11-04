<?php
// tickets/submit.php — processes the day-admission ticket form.
// POST only. Validates quantities + date, inserts a booking record,
// then routes to Stripe checkout (or success for free-only orders).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/booking.php';

function _tix_bail(string $code): void {
    header('Location: /tickets/?error=' . rawurlencode($code)); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /tickets/'); exit;
}

// CSRF
$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || $csrf === '' || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    _tix_bail('csrf');
}

// ── Load ticket types from inventory ────────────────────────────────────────
$ticketTypes = [];
try {
    $ticketTypes = $pdo->query("
        SELECT i.id, i.name, i.price
        FROM inventory i
        JOIN inventory_categories ic ON ic.id = i.category_id
        WHERE ic.slug = 'admissions' AND i.is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[BCRF tickets/submit] DB error loading tickets: ' . $e->getMessage());
    _tix_bail('db');
}

// ── Build ticket breakdown + totals ─────────────────────────────────────────
$breakdown   = [];
$totalPeople = 0;
$amountTotal = 0.0;
$rawQtys     = $_POST['qty'] ?? [];

foreach ($ticketTypes as $tt) {
    $id  = (int)$tt['id'];
    $qty = max(0, (int)($rawQtys[$id] ?? 0));
    if ($qty > 0) {
        $breakdown[$tt['name']] = ['qty' => $qty, 'price' => (float)$tt['price']];
        $amountTotal += $qty * (float)$tt['price'];
        $totalPeople += $qty;
    }
}

if ($totalPeople < 1)  _tix_bail('no_tickets');
if ($totalPeople >= 6) _tix_bail('too_many');   // 6+ → booking form

// ── Validate date ────────────────────────────────────────────────────────────
// Optional (Nov 2026): tickets are good any open day. No date = "any day" ticket,
// stored under today's date with date_flexible = 1 (see includes/booking.php).
$visitDate    = trim((string)($_POST['visit_date'] ?? ''));
$dateFlexible = ($visitDate === '');
$flexColOk    = booking_ensure_flexible_col($pdo);
if ($dateFlexible) {
    if (!$flexColOk) _tix_bail('invalid_date');   // can't flag it as any-day: ask for a date instead
    $visitDate = date('Y-m-d');
} else {
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) _tix_bail('invalid_date');
if (strtotime($visitDate) < strtotime('today'))         _tix_bail('invalid_date');

// Closed day check (use site_hours if available, else default Monday=1)
$closedDays = [1];
if (function_exists('site_hours')) {
    $closedDays = [];
    foreach (range(0, 6) as $d) {
        $h = site_hours('general')[$d] ?? [];
        // PHP date('w') gives 0=Sun...6=Sat; site_hours uses the same index.
        if (!empty($h['closed'])) $closedDays[] = $d;
    }
    if (empty($closedDays)) $closedDays = [1];
}
// PHP date('w') gives 0=Sun, 1=Mon ... 6=Sat — same numbering as JS getDay()
if (in_array((int)date('w', strtotime($visitDate)), $closedDays)) {
    _tix_bail('closed_monday');
}

}   // end: date was picked
// No blackout check: admission tickets are not blacked out for private events
// (policy, Nov 2026). Blackouts still apply to group/party bookings.

// ── Visitor details ──────────────────────────────────────────────────────────
$name  = trim(strip_tags((string)($_POST['customer_name'] ?? '')));
$email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$phone = preg_replace('/[^0-9+\-() ]/', '', (string)($_POST['phone'] ?? ''));

if ($name === '' || !$email) _tix_bail('missing_info');

// ── Get the day_admission booking type ───────────────────────────────────────
$type = null;
try {
    $ts = $pdo->prepare("SELECT * FROM booking_types WHERE type_key = 'day_admission' AND is_active = 1 LIMIT 1");
    $ts->execute();
    $type = $ts->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { /* table may not have ticket type yet */ }

if (!$type) _tix_bail('no_type');

// ── Insert booking ────────────────────────────────────────────────────────────
$token = function_exists('booking_make_token') ? booking_make_token() : bin2hex(random_bytes(24));
$start = '10:00:00';
$end   = '16:00:00';   // full-day farm hours

try {
    $pdo->prepare("
        INSERT INTO bookings
            (type_id, customer_name, email, phone, party_size,
             amount_total, payment_status, booking_date, start_time, end_time,
             is_exclusive, status, ticket_breakdown, token" . ($flexColOk ? ", date_flexible" : "") . ")
        VALUES (?,?,?,?,?, ?,?,?,?,?, 0,'pending',?,?" . ($flexColOk ? ",?" : "") . ")
    ")->execute(array_merge([
        (int)$type['id'], $name, $email, $phone, $totalPeople,
        round($amountTotal, 2),
        $amountTotal > 0 ? 'pending' : 'waived',
        $visitDate, $start, $end,
        json_encode($breakdown, JSON_UNESCAPED_UNICODE),
        $token,
    ], $flexColOk ? [$dateFlexible ? 1 : 0] : []));
} catch (Throwable $e) {
    error_log('[BCRF tickets/submit] INSERT failed: ' . $e->getMessage());
    _tix_bail('db');
}

// Free tickets only (e.g. all under-3) — skip payment, go straight to success.
if ($amountTotal <= 0) {
    $row = ['customer_name' => $name, 'email' => $email, 'phone' => $phone,
            'party_size' => $totalPeople, 'booking_date' => $visitDate, 'date_flexible' => $dateFlexible ? 1 : 0,
            'start_time' => $start, 'amount_total' => 0, 'token' => $token];
    if (function_exists('booking_send_ack')) @booking_send_ack($row, $type);
    header('Location: /booking/success.php?token=' . rawurlencode($token)); exit;
}

// Paid tickets → Stripe checkout (reuses the existing flow).
header('Location: /booking/checkout.php?token=' . rawurlencode($token)); exit;
