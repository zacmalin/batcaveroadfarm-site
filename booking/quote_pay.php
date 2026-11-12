<?php
// booking/quote_pay.php — sends the customer from their quote page to Stripe Checkout for the deposit or the balance
// (?q=TOKEN&what=deposit|balance). Farm OS Phase 3 slice 4, Nov 12, 2026 (includes/event_quotes.php).
// Only when the farm turned online payment on for this quote and something is left to pay; the amount comes from the
// server, never the link. Meta-refresh redirect (a header redirect to Stripe gets blocked on this site; see tickets/pay.php).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';

$q = bcrf_quotes_ready($pdo) ? bcrf_quote_by_token($pdo, (string)($_GET['q'] ?? '')) : null;
if (!$q) { header('Location: /booking/'); exit; }
$back = '/booking/quote.php?q=' . rawurlencode($q['token']);
$what = ($_GET['what'] ?? '') === 'deposit' ? 'deposit' : 'balance';

try {
    $url = bcrf_quote_checkout($pdo, $q, $what);
} catch (Throwable $e) {
    error_log('[BCRF booking/quote_pay] ' . $q['id'] . ': ' . $e->getMessage());
    header('Location: ' . $back . '&error=pay'); exit;
}
$u = htmlspecialchars($url, ENT_QUOTES);
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<meta http-equiv="refresh" content="0;url=' . $u . '"><meta name="robots" content="noindex"><title>Taking you to secure checkout…</title>';
echo '<link rel="stylesheet" href="/css/min.quote.css">';
echo '</head><body class="quote-redirect"><div><p>Taking you to secure checkout…</p><p><a href="' . $u . '">Continue to payment</a></p></div></body></html>';
