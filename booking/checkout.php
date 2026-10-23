<?php
// booking/checkout.php — order summary + payment method selection (Phase 2: Stripe only).
// Reached after booking/submit.php when the booking type requires_payment = 1.
// The booking row already exists (status=pending, payment_status=pending).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/booking.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$token = trim((string)($_GET['token'] ?? ''));
$booking = null; $type = null;
// Error can come from DB lookup below, or from stripe_session.php bailing back here.
$error = trim((string)($_GET['error'] ?? ''));

if ($token !== '') {
    try {
        $st = $pdo->prepare("SELECT b.*, t.label, t.pricing_model, t.unit_price, t.requires_payment
                              FROM bookings b JOIN booking_types t ON t.id = b.type_id
                              WHERE b.token = ? AND b.status != 'cancelled' LIMIT 1");
        $st->execute([$token]);
        $booking = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        error_log('[BCRF checkout] ' . $e->getMessage());
        $error = 'Could not load your booking. Please contact us at (828) 216-5488.';
    }
}

if (!$booking)         $error = $error ?: 'Booking not found. The link may have expired.';
if (!$error && $booking['payment_status'] === 'paid') {
    // Already paid — bounce to success
    header('Location: /booking/success.php?token=' . rawurlencode($token)); exit;
}

$total = (float)($booking['amount_total'] ?? 0);
$stripeReady = defined('BCRF_STRIPE_PK') && BCRF_STRIPE_PK !== '' && defined('BCRF_STRIPE_SK') && BCRF_STRIPE_SK !== '';

// CSRF token for the Stripe session form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Complete Your Booking — Bat Cave Road Farm</title>
<link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;background:#f5ead6;font-family:Lora,Georgia,serif;color:#3b2010;padding:24px;display:flex;align-items:flex-start;justify-content:center;padding-top:48px}
  .wrap{width:100%;max-width:480px}
  .logo{text-align:center;margin-bottom:28px}
  .logo-title{font-family:Rye,Georgia,serif;font-size:1.25rem;color:#5c3317;line-height:1.2}
  .logo-sub{font-size:.8rem;color:#8b5c2a;margin-top:3px;letter-spacing:.06em}
  .card{background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;border-radius:14px;padding:28px 24px;box-shadow:0 10px 30px rgba(59,32,16,.14);margin-bottom:16px}
  h1{font-family:Rye,Georgia,serif;font-size:1.3rem;color:#5c3317;margin:0 0 20px;text-align:center}
  .summary-row{display:flex;justify-content:space-between;align-items:baseline;padding:7px 0;border-bottom:1px solid rgba(201,146,58,.25);font-size:.92rem}
  .summary-row:last-of-type{border-bottom:none}
  .summary-label{color:#8b5c2a;font-weight:600}
  .summary-value{color:#3b2010;text-align:right}
  .total-row{display:flex;justify-content:space-between;align-items:baseline;padding:14px 0 0;margin-top:8px;border-top:2px solid #c9923a}
  .total-label{font-family:Rye,Georgia,serif;font-size:1rem;color:#5c3317}
  .total-amount{font-family:Rye,Georgia,serif;font-size:1.4rem;color:#5c3317}
  .pay-btn{display:block;width:100%;background:#5c3317;color:#f5ead6;border:2px solid #c9923a;border-radius:9px;padding:14px;font-family:Rye,Georgia,serif;font-size:1.05rem;cursor:pointer;text-align:center;margin-top:22px;text-decoration:none;transition:background .15s}
  .pay-btn:hover{background:#3b2010}
  .pay-btn.stripe{background:#635bff;border-color:#7a73ff}
  .pay-btn.stripe:hover{background:#524ddb}
  .cancel-link{display:block;text-align:center;margin-top:14px;font-size:.85rem;color:#8b5c2a;text-decoration:none}
  .cancel-link:hover{text-decoration:underline}
  .error-msg{background:#fde8e8;border:1px solid #f5c6c6;border-radius:8px;padding:14px;color:#b91c1c;font-size:.9rem;text-align:center}
  .note{font-size:.78rem;color:#8b5c2a;text-align:center;margin-top:12px;line-height:1.5}
</style>
</head><body>
<div class="wrap">
  <div class="logo">
    <div class="logo-title">🐐 Bat Cave Road Farm</div>
    <div class="logo-sub">Secure Checkout</div>
  </div>

<?php if ($error): ?>
  <div class="card">
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <a class="cancel-link" href="/booking/">← Back to booking form</a>
  </div>
<?php else: ?>
  <div class="card">
    <h1>Your booking summary</h1>
    <div class="summary-row"><span class="summary-label">Booking type</span><span class="summary-value"><?= htmlspecialchars($booking['label']) ?></span></div>
    <div class="summary-row"><span class="summary-label">Date</span><span class="summary-value"><?= htmlspecialchars(function_exists('booking_fmt_date') ? booking_fmt_date($booking['booking_date']) : $booking['booking_date']) ?></span></div>
    <div class="summary-row"><span class="summary-label">Time</span><span class="summary-value"><?= htmlspecialchars(function_exists('booking_fmt_time') ? booking_fmt_time($booking['start_time']) : $booking['start_time']) ?></span></div>
    <div class="summary-row"><span class="summary-label">Party size</span><span class="summary-value"><?= (int)$booking['party_size'] ?> guests</span></div>
    <div class="summary-row"><span class="summary-label">Name</span><span class="summary-value"><?= htmlspecialchars($booking['customer_name']) ?></span></div>
    <div class="total-row">
      <span class="total-label">Total</span>
      <span class="total-amount"><?= $total > 0 ? '$' . number_format($total, 2) : 'Free' ?></span>
    </div>

    <?php if ($stripeReady && $total > 0): ?>
    <form method="POST" action="/booking/stripe_session.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
      <button type="submit" class="pay-btn stripe">💳 Pay with Stripe</button>
    </form>
    <?php elseif ($total <= 0): ?>
      <a class="pay-btn" href="/booking/success.php?token=<?= rawurlencode($token) ?>">Complete booking →</a>
    <?php else: ?>
      <div class="error-msg" style="margin-top:18px">Payment is not configured yet. Please call us at (828) 216-5488 to complete your booking.</div>
    <?php endif; ?>

    <a class="cancel-link" href="/booking/cancel.php?token=<?= rawurlencode($token) ?>">Cancel this booking</a>
    <p class="note">Nothing is reserved until payment is complete. You'll receive a confirmation email once your booking is confirmed.</p>
  </div>
<?php endif; ?>
</div>
</body></html>
