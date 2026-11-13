<?php
// passes/submit.php — creates pass record and initiates Stripe checkout.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/stripe.php';
require_once __DIR__ . '/../includes/pass_reservations.php';

function _pass_bail(string $code): void {
    header('Location: /passes/?error=' . rawurlencode($code)); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /passes/'); exit;
}
if (!empty($_POST['website'])) { header('Location: /passes/'); exit; } // honeypot

// CSRF
$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || $csrf === '' || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    _pass_bail('csrf');
}

// Validate inputs
$typeId = (int)($_POST['pass_type_id'] ?? 0);
$name   = trim(strip_tags((string)($_POST['holder_name'] ?? '')));
$email  = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$phone  = preg_replace('/[^0-9+\-() ]/', '', (string)($_POST['phone'] ?? ''));

if ($typeId <= 0)  _pass_bail('type');
if ($name === '')  _pass_bail('name');
if (!$email)       _pass_bail('email');

// Atomically check capacity and reserve a slot under the pass-type row lock.
$token = bin2hex(random_bytes(24));
try {
    $reservation = bcrf_pass_reserve($pdo, $typeId, $name, $email, $phone ?: null, $token);
    $passId = $reservation['id'];
    $pt = $reservation['type'];

} catch (DomainException $e) {
    _pass_bail(in_array($e->getMessage(), ['type', 'sold_out'], true) ? $e->getMessage() : 'db');
} catch (Throwable $e) {
    error_log('[BCRF passes/submit] Reservation failed: ' . $e->getMessage());
    _pass_bail('db');
}

// Analytics must never interrupt checkout after a slot has been reserved.
try {
    require_once __DIR__ . '/../includes/ga.php';
    bcrf_ga_store_ids($pdo, 'passes', $passId);
} catch (Throwable $e) {
    error_log('[BCRF passes/submit] Analytics IDs failed for pass #' . $passId . ': ' . $e->getMessage());
}

// Create Stripe Checkout Session
$base    = rtrim(defined('BCRF_SOCIAL_BASE_URL') ? BCRF_SOCIAL_BASE_URL : (bcrf_farm_url()), '/');
$tok     = rawurlencode($token);
$perYear = '/ year';

try {
    $session = stripe_api('POST', 'checkout/sessions', [
        'mode'                                          => 'payment',
        // Stripe Checkout minimum expiry is 30 minutes; use the same hold window.
        'expires_at'                                    => time() + 1800,
        'line_items[0][price_data][currency]'           => 'usd',
        'line_items[0][price_data][unit_amount]'        => (int)round((float)$pt['price'] * 100),
        'line_items[0][price_data][product_data][name]' => $pt['label'] . ' — Bat Cave Road Farm',
        'line_items[0][price_data][product_data][description]' =>
            'Annual pass · up to ' . (int)$pt['max_guests'] . ' guest' . ($pt['max_guests'] > 1 ? 's' : '') . ' per visit · ' . (int)($pt['duration_months'] ?? 12) . ' months · Price includes NC sales tax',
        'line_items[0][quantity]'                       => 1,
        'customer_email'                                => $email,
        'success_url'                                   => $base . '/passes/success.php?token=' . $tok,
        'cancel_url'                                    => $base . '/passes/?cancelled=1',
        'metadata[pass_id]'                             => (string)$passId,
        'metadata[pass_token]'                          => $token,
    ]);
    if (empty($session['url'])) throw new RuntimeException('No URL in Stripe response.');
} catch (Throwable $e) {
    error_log('[BCRF passes/submit] Stripe error: ' . $e->getMessage());
    // Clean up the pending pass so it doesn't orphan
    try { $pdo->prepare("UPDATE passes SET reservation_expires_at=NOW() WHERE id=? AND status='pending_payment' AND payment_status='pending'")->execute([$passId]); } catch (Throwable $ex) {}
    header('Location: /passes/?error=stripe'); exit;
}

// Store session reference
try {
    $pdo->prepare("UPDATE passes SET payment_reference=? WHERE id=?")->execute([$session['id'], $passId]);
} catch (Throwable $e) {}

// Meta-refresh to Stripe (avoids header() redirect issues with Cloudflare)
$url = htmlspecialchars($session['url'], ENT_QUOTES);
echo '<!doctype html><html><head><meta charset="utf-8">';
echo '<meta http-equiv="refresh" content="0;url=' . $url . '">';
echo '<title>Redirecting to checkout…</title>';
echo '<style>body{font-family:Georgia,serif;background:#f5ead6;color:#3b2010;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px}</style>';
echo '</head><body><p>Taking you to secure checkout… <a href="' . $url . '">click here if not redirected</a></p></body></html>';
exit;
