<?php
// booking/stripe_session.php — creates a Stripe Checkout Session and redirects.
// POST only; called from booking/checkout.php. CSRF-protected.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/stripe.php';

function _stripe_bail(string $msg, string $token = ''): void {
    if ($token === '') $token = trim((string)($_POST['token'] ?? ''));
    error_log('[BCRF stripe_session] bail: ' . $msg);
    $back = $token !== '' ? '/booking/checkout.php?error=' . rawurlencode($msg) . '&token=' . rawurlencode($token) : '/booking/';
    header('Location: ' . $back); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /booking/'); exit;
}

$csrf  = trim((string)($_POST['csrf_token'] ?? ''));
$token = trim((string)($_POST['token']      ?? ''));

if (empty($_SESSION['csrf_token']) || $csrf === '' || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    _stripe_bail('Security token expired — please go back and try again.', $token);
}
if ($token === '') _stripe_bail('Missing booking token.');

try {
    $st = $pdo->prepare("SELECT b.*, t.label, t.pricing_model, t.unit_price, t.requires_payment
                          FROM bookings b JOIN booking_types t ON t.id = b.type_id
                          WHERE b.token = ? AND b.status != 'cancelled' LIMIT 1");
    $st->execute([$token]);
    $booking = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('[BCRF stripe_session] DB error: ' . $e->getMessage());
    _stripe_bail('Could not load your booking — please try again or call (828) 216-5488.', $token);
}

if (!$booking)                               _stripe_bail('Booking not found or cancelled.', $token);
if ($booking['payment_status'] === 'paid') { header('Location: /booking/success.php?token=' . rawurlencode($token)); exit; }
if ((float)($booking['amount_total'] ?? 0) <= 0) _stripe_bail('No payment amount set on this booking.', $token);

try {
    $session = stripe_create_checkout_session($booking, $booking);
    if (empty($session['url'])) throw new RuntimeException('No checkout URL returned by Stripe.');
} catch (Throwable $e) {
    error_log('[BCRF stripe_session] Stripe error: ' . $e->getMessage());
    _stripe_bail('Payment could not be started: ' . $e->getMessage(), $token);
}

try {
    $pdo->prepare("UPDATE bookings SET payment_reference=? WHERE id=?")
        ->execute([$session['id'], (int)$booking['id']]);
} catch (Throwable $e) { /* non-fatal */ }

// meta-refresh avoids header() redirect being blocked by Cloudflare/browser
$stripeUrl = htmlspecialchars($session['url'], ENT_QUOTES);
echo '<!doctype html><html><head><meta charset="utf-8">';
echo '<meta http-equiv="refresh" content="0;url=' . $stripeUrl . '">';
echo '<title>Redirecting to secure checkout…</title>';
echo '<style>body{font-family:Georgia,serif;background:#f5ead6;color:#3b2010;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;box-sizing:border-box;}.card{text-align:center;max-width:400px;}</style>';
echo '</head><body><div class="card"><p>Taking you to secure checkout…</p>';
echo '<p><a href="' . $stripeUrl . '" style="color:#635bff;font-weight:bold;">Click here if not redirected</a></p>';
echo '</div></body></html>';
exit;
