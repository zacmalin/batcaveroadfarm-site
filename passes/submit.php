<?php
// passes/submit.php — creates pass record and initiates Stripe checkout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/stripe.php';

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

// Load pass type
try {
    $st = $pdo->prepare("SELECT * FROM pass_types WHERE id=? AND is_active=1 LIMIT 1");
    $st->execute([$typeId]);
    $pt = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { _pass_bail('db'); }
if (!$pt) _pass_bail('type');

// Check founding member availability
if ($pt['is_founding'] && $pt['founding_qty_limit']) {
    try {
        $sold = (int)$pdo->query("SELECT COUNT(*) FROM passes WHERE pass_type_id={$typeId} AND status != 'cancelled'")->fetchColumn();
        if ($sold >= (int)$pt['founding_qty_limit']) _pass_bail('sold_out');
    } catch (Throwable $e) { /* allow through if can't check */ }
}

// Generate unique token
$token = bin2hex(random_bytes(24));

// Insert pass (status=pending_payment — becomes active when webhook fires)
try {
    $pdo->prepare("
        INSERT INTO passes (pass_type_id, holder_name, email, phone, token, status, payment_status, amount_paid)
        VALUES (?,?,?,?,?, 'pending_payment','pending',?)
    ")->execute([$typeId, $name, $email, $phone ?: null, $token, (float)$pt['price']]);
    $passId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('[BCRF passes/submit] DB insert failed: ' . $e->getMessage());
    _pass_bail('db');
}

// Create Stripe Checkout Session
$base    = rtrim(defined('BCRF_SOCIAL_BASE_URL') ? BCRF_SOCIAL_BASE_URL : 'https://batcaveroadfarm.com', '/');
$tok     = rawurlencode($token);
$perYear = '/ year';

try {
    $session = stripe_api('POST', 'checkout/sessions', [
        'mode'                                          => 'payment',
        'line_items[0][price_data][currency]'           => 'usd',
        'line_items[0][price_data][unit_amount]'        => (int)round((float)$pt['price'] * 100),
        'line_items[0][price_data][product_data][name]' => $pt['label'] . ' — Bat Cave Road Farm',
        'line_items[0][price_data][product_data][description]' =>
            'Annual pass · up to ' . (int)$pt['max_guests'] . ' guest' . ($pt['max_guests'] > 1 ? 's' : '') . ' per visit · ' . (int)($pt['duration_months'] ?? 12) . ' months',
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
    try { $pdo->prepare("DELETE FROM passes WHERE id=? AND status='pending_payment'")->execute([$passId]); } catch (Throwable $ex) {}
    header('Location: /passes/?error=stripe&msg=' . rawurlencode($e->getMessage())); exit;
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
