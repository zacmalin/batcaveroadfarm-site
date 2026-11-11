<?php
// gift-cards/pay.php — sends an unpaid online gift card to Stripe Checkout (Farm OS Phase 2, Nov 11, 2026).
// Used right after the order form, and by "Pay now" on the card page if checkout was left unfinished.
// Meta-refresh redirect (a header redirect to Stripe gets blocked by Cloudflare/browsers on this site; see tickets/pay.php).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/gift_cards.php';

$c = bcrf_gift_cards_ready($pdo) ? bcrf_gc_by_code($pdo, (string)($_GET['g'] ?? '')) : null;
if (!$c || $c['source'] !== 'online') { header('Location: /gift-cards/'); exit; }
$back = '/gift-cards/card.php?g=' . rawurlencode($c['code']);
if ($c['status'] !== 'pending') { header('Location: ' . $back); exit; }

try {
    $url = bcrf_gc_online_checkout($pdo, $c);
} catch (Throwable $e) {
    error_log('[BCRF gift-cards/pay] ' . $c['id'] . ': ' . $e->getMessage());
    header('Location: ' . $back . '&error=pay'); exit;
}
$u = htmlspecialchars($url, ENT_QUOTES);
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<meta http-equiv="refresh" content="0;url=' . $u . '"><meta name="robots" content="noindex"><title>Taking you to secure checkout…</title>';
echo '<style>body{font-family:Georgia,serif;background:#f5ead6;color:#3b2010;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;box-sizing:border-box;text-align:center}</style>';
echo '</head><body><div><p>Taking you to secure checkout…</p><p><a href="' . $u . '" style="color:#635bff;font-weight:bold">Continue to payment</a></p></div></body></html>';
