<?php
// Self-contained booking request handler for batcaveroadfarm.com.
// Renders its OWN result page (no header() redirect) so a stray BOM / broken
// target page can't turn this into a white screen. Mirrors send-message.php.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/auth.php';        // csrf_verify() (fix pack L)
require_once __DIR__ . '/../includes/rate_limit.php';  // bcrf_rl_*()   (fix pack L)

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($method !== 'POST') {
    echo '<!doctype html><meta charset="utf-8">';
    echo '<body style="font-family:Georgia,serif;padding:40px;background:#f5ead6;color:#3b2010">';
    echo '<p>✅ Booking endpoint is running. Use the <a href="https://batcaveroadfarm.com/booking/">booking form</a> to request a visit.</p>';
    exit;
}

$isError   = false;
$errMsg    = '';
$isQuote   = false; // private event (request-a-quote) vs. confirmable booking

if (!empty($_POST['website'])) {
    // honeypot tripped — pretend success, do nothing
} elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
    // Fix pack L: request must come from our own booking form in this browser.
    $isError = true;
    $errMsg  = "Your session timed out before the form was sent. Please tap your browser's Back button (your details should still be filled in), then press the button to send again.";
} elseif (bcrf_rl_hit('booking_ip', bcrf_rl_ip(), 5, 3600)) {
    // Fix pack L: max 5 booking requests per connection per hour (stops slot-filling spam).
    $isError = true;
    $errMsg  = "We've received several booking requests from you in the last hour. Please wait a bit before sending another.";
} else {
    $noCrlf  = fn($v) => trim(preg_replace('/[\r\n]+/', ' ', (string)$v));
    $typeKey = trim(strip_tags((string)($_POST['type_key'] ?? '')));
    $name    = $noCrlf(strip_tags((string)($_POST['customer_name'] ?? '')));
    $email   = trim((string)($_POST['email'] ?? ''));
    $phone   = $noCrlf(strip_tags((string)($_POST['phone'] ?? '')));
    $size    = (int)($_POST['party_size'] ?? 0);
    $date    = trim((string)($_POST['booking_date'] ?? ''));
    $time    = trim((string)($_POST['start_time'] ?? ''));
    $message = trim(strip_tags((string)($_POST['message'] ?? '')));

    $type       = function_exists('booking_type') ? booking_type($pdo, $typeKey) : null;
    $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
    $dateTs     = $date !== '' ? strtotime($date) : false;
    $startTs    = ($date !== '' && $time !== '') ? strtotime($date . ' ' . $time) : false;

    if (!$type) {
        $isError = true; $errMsg = "Please choose what you'd like to book.";
    } elseif ($name === '' || $validEmail === false) {
        $isError = true; $errMsg = "Please give us your name and a valid email.";
    } elseif ($dateTs === false || $startTs === false) {
        $isError = true; $errMsg = "Please pick a valid date and time.";
    } elseif ($size < (int)$type['min_party']) {
        $isError = true; $errMsg = "“" . htmlspecialchars($type['label']) . "” is for groups of " . (int)$type['min_party'] . " or more.";
    } elseif ($size > (int)$type['max_party'] || $size > 25) {
        $isError = true; $errMsg = "That party is larger than we can host (max " . min(25, (int)$type['max_party']) . "). Please call us to arrange.";
    } elseif ($startTs < time() + (int)$type['lead_time_hours'] * 3600) {
        $isError = true; $errMsg = "Please choose a time at least " . (int)$type['lead_time_hours'] . " hours from now.";
    } else {
        $isQuote = ((int)$type['is_exclusive'] === 1 || (int)$type['self_serve'] === 0);
        $start   = date('H:i:s', $startTs);
        $end     = booking_end_time($start, (int)$type['duration_min']);
        $token   = booking_make_token();
        $needsPay = !empty($type['requires_payment']);

        // ── Per-person tier pricing ───────────────────────────────────────────
        $breakdown     = null;
        $isPerPerson   = ($type['pricing_model'] ?? 'flat') === 'per_person' && !empty($_POST['tier_qty']);
        if ($isPerPerson) {
            $admTiers = [];
            try {
                $admTiers = $pdo->query("
                    SELECT i.id, i.name, i.price
                    FROM inventory i
                    JOIN inventory_categories ic ON ic.id = i.category_id
                    WHERE ic.slug = 'admissions' AND i.is_active = 1
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {}
            $tierPrice = array_column($admTiers, 'price', 'id');
            $tierName  = array_column($admTiers, 'name',  'id');
            $tierTotal = 0.0;
            $tierPeople = 0;
            $breakdown = [];
            foreach ((array)$_POST['tier_qty'] as $tid => $qty) {
                $tid = (int)$tid;
                $qty = max(0, (int)$qty);
                if ($qty > 0 && isset($tierPrice[$tid])) {
                    $breakdown[$tierName[$tid]] = ['qty' => $qty, 'price' => (float)$tierPrice[$tid]];
                    $tierTotal  += $qty * (float)$tierPrice[$tid];
                    $tierPeople += $qty;
                }
            }
            $size  = $tierPeople ?: $size;
            $total = round($tierTotal, 2);
        } else {
            $total = function_exists('booking_calculate_total') ? booking_calculate_total($type, $size) : 0.0;
        }

        $payStatus = ($needsPay && $total > 0) ? 'pending' : 'waived';
        try {
            $st = $pdo->prepare(
                "INSERT INTO bookings
                   (type_id, customer_name, email, phone, party_size, amount_total, payment_status,
                    booking_date, start_time, end_time, is_exclusive, status, notes, ticket_breakdown, token)
                 VALUES (?,?,?,?,?,?,?, ?,?,?,?, 'pending', ?, ?, ?)"
            );
            $st->execute([
                (int)$type['id'], $name, $validEmail, $phone, $size,
                $total > 0 ? $total : null, $payStatus,
                date('Y-m-d', $dateTs), $start, $end, (int)$type['is_exclusive'],
                ($message !== '' ? $message : null),
                $breakdown !== null ? json_encode($breakdown, JSON_UNESCAPED_UNICODE) : null,
                $token,
            ]);
            $b = [
                'id' => (int)$pdo->lastInsertId(), 'customer_name' => $name, 'email' => $validEmail,
                'phone' => $phone, 'party_size' => $size, 'booking_date' => date('Y-m-d', $dateTs),
                'start_time' => $start, 'amount_total' => $total, 'payment_status' => $payStatus,
                'notes' => $message, 'token' => $token,
            ];
            if ($needsPay && $total > 0) {
                // Send admin alert but NOT the customer ack — confirmation fires after payment.
                if (function_exists('booking_send_admin_alert')) booking_send_admin_alert($b, $type);
                // Redirect to checkout — renders its own standalone page.
                header('Location: /booking/checkout.php?token=' . rawurlencode($token)); exit;
            }
            if (function_exists('booking_send_ack'))         booking_send_ack($b, $type);
            if (function_exists('booking_send_admin_alert')) booking_send_admin_alert($b, $type);
        } catch (Throwable $e) {
            error_log('[BCRF booking submit] ' . $e->getMessage());
            $isError = true; $errMsg = "Something went wrong saving your request.";
        }
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $isError ? "That didn't go through" : "Request Received"; ?> &mdash; Bat Cave Road Farm</title>
<link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:wght@400;600&display=swap" rel="stylesheet">
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:#f5ead6;font-family:Lora,Georgia,serif;color:#3b2010;padding:24px}
  .card{max-width:480px;background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;
    border-radius:14px;padding:36px 28px;text-align:center;box-shadow:0 10px 30px rgba(59,32,16,.18)}
  .emoji{font-size:3rem;line-height:1}
  h1{font-family:Rye,Georgia,serif;font-size:1.5rem;margin:14px 0 8px;color:#5c3317}
  p{font-size:1rem;line-height:1.6;margin:0 0 22px}
  a.btn{display:inline-block;background:#5c3317;color:#f5ead6;text-decoration:none;font-weight:600;
    padding:12px 26px;border-radius:8px;border:2px solid #c9923a}
  a.btn:hover{background:#3b2010}
</style></head>
<body>
  <div class="card">
    <div class="emoji"><?php echo $isError ? '🌾' : ($isQuote ? '🎈' : '🐐'); ?></div>
    <h1><?php echo $isError ? "That didn't go through" : "Request Received!"; ?></h1>
    <p><?php
        if ($isError) {
            echo htmlspecialchars($errMsg) . " You can also call us at "
               . htmlspecialchars(function_exists('site') ? site('phone.general') : '(828) 216-5488') . ".";
        } elseif ($isQuote) {
            echo "Thanks! We've received your private event request and will call or email to confirm details and pricing. Nothing is reserved until we confirm.";
        } else {
            echo "Thanks! We've received your request and will confirm by email shortly. Nothing is reserved until we confirm.";
        }
    ?></p>
    <a class="btn" href="https://batcaveroadfarm.com/booking/">Back to Booking</a>
  </div>
</body></html>
