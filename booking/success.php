<?php
// booking/success.php — post-payment confirmation (paid + free bookings).
// session_id is NOT in the URL (ModSecurity blocks it). Payment status is
// checked from the DB; if still pending, we verify via the stored payment_reference.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/booking.php';

$token   = trim((string)($_GET['token'] ?? ''));
$booking = null;

if ($token !== '') {
    try {
        $st = $pdo->prepare("SELECT b.*, t.label FROM bookings b
                              JOIN booking_types t ON t.id = b.type_id
                              WHERE b.token = ? LIMIT 1");
        $st->execute([$token]);
        $booking = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { /* non-fatal */ }
}

$paid = $booking && ($booking['payment_status'] === 'paid');
$free = $booking && ((float)($booking['amount_total'] ?? 0) <= 0);

// Webhook may not have fired yet — if still pending and we have a payment_reference,
// check Stripe directly using the stored session ID.
if ($booking && !$paid && !$free && !empty($booking['payment_reference'])) {
    try {
        require_once __DIR__ . '/../includes/stripe.php';
        $session = stripe_retrieve_session((string)$booking['payment_reference']);
        if (($session['payment_status'] ?? '') === 'paid') {
            $pdo->prepare("UPDATE bookings SET payment_status='paid', paid_at=COALESCE(paid_at,NOW()),
                           payment_provider='stripe' WHERE id=? AND payment_status='pending'")
                ->execute([(int)$booking['id']]);
            $booking['payment_status'] = 'paid';
            $paid = true;
        }
    } catch (Throwable $e) { /* non-fatal — webhook will handle it */ }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Booking Confirmed — Bat Cave Road Farm</title>
<link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:#f5ead6;font-family:Lora,Georgia,serif;color:#3b2010;padding:24px}
  .card{max-width:480px;width:100%;background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;
    border-radius:14px;padding:36px 28px;text-align:center;box-shadow:0 10px 30px rgba(59,32,16,.14)}
  .emoji{font-size:3rem;line-height:1}
  h1{font-family:Rye,Georgia,serif;font-size:1.5rem;margin:14px 0 8px;color:#5c3317}
  p{font-size:1rem;line-height:1.6;margin:0 0 16px}
  .summary{background:rgba(201,146,58,.12);border-radius:8px;padding:12px 16px;margin:16px 0;text-align:left;font-size:.88rem;color:#5c3317}
  .summary strong{color:#3b2010}
  a.btn{display:inline-block;background:#5c3317;color:#f5ead6;text-decoration:none;font-weight:600;
    padding:12px 26px;border-radius:8px;border:2px solid #c9923a}
  a.btn:hover{background:#3b2010}
  .note{font-size:.8rem;color:#8b5c2a;margin-top:14px}
</style>
</head><body>
<div class="card">
<?php if (!$booking): ?>
  <div class="emoji">🌾</div>
  <h1>Booking not found</h1>
  <p>We couldn't locate your booking. Please call us at (828) 216-5488 if you need help.</p>
  <a class="btn" href="/booking/">Back to booking</a>

<?php elseif ($paid || $free): ?>
  <div class="emoji">🐐</div>
  <h1><?= $free ? 'Request Received!' : 'Payment Confirmed!' ?></h1>
  <p><?= $free
    ? "We've received your booking request and will confirm by email shortly."
    : "Your payment was successful. We'll send a confirmation email to <strong>" . htmlspecialchars($booking['email']) . "</strong> shortly."
  ?></p>
  <div class="summary">
    <strong><?= htmlspecialchars($booking['label']) ?></strong><br>
    <?= htmlspecialchars(function_exists('booking_fmt_date') ? booking_fmt_date($booking['booking_date']) : $booking['booking_date']) ?>
    at <?= htmlspecialchars(function_exists('booking_fmt_time') ? booking_fmt_time($booking['start_time']) : $booking['start_time']) ?><br>
    <?= (int)$booking['party_size'] ?> guests · <?= htmlspecialchars($booking['customer_name']) ?>
    <?php if (!empty($booking['amount_total']) && (float)$booking['amount_total'] > 0): ?>
    <br><strong>Paid: $<?= number_format((float)$booking['amount_total'], 2) ?></strong>
    <?php endif; ?>
  </div>
  <a class="btn" href="https://batcaveroadfarm.com/">Visit the farm site</a>
  <p class="note">Questions? Call (828) 216-5488 or email support@batcaveroadfarm.com</p>
  <p class="note" style="margin-top:10px;padding-top:10px;border-top:1px solid #e8d5b0">
    🪪 Plan to visit again? Our <a href="https://batcaveroadfarm.com/passes/" style="color:#5c3317;font-weight:bold">annual passes</a> give you unlimited visits for a year.
  </p>

<?php else: ?>
  <div class="emoji">⏳</div>
  <h1>Almost there!</h1>
  <p>We're confirming your payment — this usually takes just a moment. You'll receive a confirmation email at <strong><?= htmlspecialchars($booking['email'] ?? '') ?></strong> once everything is verified.</p>
  <p>If you don't hear from us within 15 minutes, please call (828) 216-5488.</p>
  <a class="btn" href="https://batcaveroadfarm.com/">Back to the farm</a>
<?php endif; ?>
</div>
</body></html>
