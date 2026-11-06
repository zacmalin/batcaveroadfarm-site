<?php
// tickets/pay.php — Farm OS 4b (Nov 6, 2026): sends an unpaid online ticket order to Stripe Checkout.
// Used right after the ticket form, and by "Pay now" on the ticket page if checkout was left unfinished.
// Meta-refresh redirect (a header redirect to Stripe gets blocked by Cloudflare/browsers on this site).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/tickets_online.php';

$t = bcrf_online_tickets_ready($pdo) ? bcrf_online_ticket_by_code($pdo, (string)($_GET['c'] ?? '')) : null;
if (!$t) { header('Location: /tickets/'); exit; }
$back = '/tickets/ticket.php?c=' . rawurlencode($t['public_code']);
if ($t['payment_status'] !== 'pending') { header('Location: ' . $back); exit; }

try {
    $url = bcrf_online_ticket_checkout($pdo, $t);
} catch (Throwable $e) {
    error_log('[BCRF tickets/pay] ' . $t['id'] . ': ' . $e->getMessage());
    header('Location: ' . $back . '&error=pay'); exit;
}
$u = htmlspecialchars($url, ENT_QUOTES);
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<meta http-equiv="refresh" content="0;url=' . $u . '"><title>Taking you to secure checkout…</title>';
echo '<style>body{font-family:Georgia,serif;background:#f5ead6;color:#3b2010;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;box-sizing:border-box;text-align:center}</style>';
echo '</head><body><div><p>Taking you to secure checkout…</p><p><a href="' . $u . '" style="color:#635bff;font-weight:bold">Continue to payment</a></p></div></body></html>';
