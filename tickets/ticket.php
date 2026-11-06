<?php
// tickets/ticket.php — Farm OS 4b (Nov 6, 2026): the buyer's online ticket. ?c=CODE (the 10-character ticket code).
// Shows the QR the gate scans (it holds this page's address; includes/admission.php reads the code from it),
// what's left on the ticket, and when it expires. If payment hasn't been confirmed yet it asks Stripe directly
// (the webhook may be a few seconds behind), and offers "Pay now" if checkout was left unfinished.
// The code is the key, like the pass link: anyone with it can see the ticket, so the page is noindex.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../admin/includes/audit.php';
require_once __DIR__ . '/../includes/tickets_online.php';

$t = bcrf_online_tickets_ready($pdo) ? bcrf_online_ticket_by_code($pdo, (string)($_GET['c'] ?? '')) : null;
if ($t) $t = bcrf_online_ticket_check_stripe($pdo, $t);

$pageTitle = 'Your Tickets — ' . bcrf_farm_name();
$metaRobots = 'noindex, nofollow';
$pageCSS   = bcrf_farm_url() . '/css/min.passes.css';
$pageJS    = [bcrf_farm_url() . '/js/qrcode.min.js', bcrf_farm_url() . '/js/min.passes.js'];   // min.passes.js draws #pass-qr from data-url
require '../includes/head.php';
include '../includes/header.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$state = !$t ? 'missing' : ($t['status'] === 'void' ? 'void' : ($t['payment_status'] === 'pending' ? 'unpaid' : 'ok'));
$left = $t ? max(0, (int)$t['quantity'] - (int)$t['quantity_redeemed']) : 0;
$expired = $t && $t['expires_at'] && strtotime($t['expires_at'] . ' UTC') < time();
?>
<div class="pass-success-wrap">
  <div class="pass-success-card">
  <?php if ($state === 'missing'): ?>
    <h1>Ticket not found</h1>
    <p>We couldn't find that ticket. Check the link in your email, or call <?= $h(function_exists('site_phone_display') ? site_phone_display() : '') ?> and we'll sort it out.</p>
    <a class="cta-btn" href="/tickets/">Buy tickets</a>

  <?php elseif ($state === 'void'): ?>
    <h1>This ticket was cancelled</h1>
    <p>Ticket <?= $h(bcrf_ticket_code_fmt($t['public_code'])) ?> can't be used. If that's a surprise, please get in touch.</p>

  <?php elseif ($state === 'unpaid'): ?>
    <h1><?= isset($_GET['paid']) ? 'Confirming your payment…' : 'Payment not finished' ?></h1>
    <?php if (isset($_GET['paid'])): ?>
      <p>This usually takes a few seconds. Refresh this page in a moment; we'll also email your ticket to <strong><?= $h($t['purchaser_email']) ?></strong>.</p>
    <?php else: ?>
      <p><?= isset($_GET['error']) ? 'We couldn\'t start the payment. Please try again.' : 'Your order is saved, but it hasn\'t been paid yet.' ?></p>
      <a class="cta-btn" href="/tickets/pay.php?c=<?= rawurlencode($t['public_code']) ?>">Pay now ($<?= number_format((int)$t['amount_cents'] / 100, 2) ?>)</a>
    <?php endif; ?>

  <?php else: ?>
    <h1><?= $left > 0 && !$expired ? 'Your tickets' : 'Ticket used up' ?></h1>
    <p><strong><?= $h($t['purchaser_name']) ?></strong><br>
      <?php foreach ((array)json_decode((string)$t['breakdown_json'], true) as $name => $q): ?><?= (int)$q ?> × <?= $h($name) ?><br><?php endforeach; ?>
    </p>
    <?php if ($left > 0 && !$expired): ?>
      <div id="pass-qr" data-url="<?= $h(bcrf_online_ticket_url($t['public_code'])) ?>"></div>
      <p class="pass-link-box">Ticket code <strong><?= $h(bcrf_ticket_code_fmt($t['public_code'])) ?></strong></p>
      <p><?= $left === (int)$t['quantity'] ? 'Good for ' . $left . ' admission' . ($left === 1 ? '' : 's') : $left . ' of ' . (int)$t['quantity'] . ' admissions left' ?>,
         any day we're open<?= $t['expires_at'] ? ' until ' . $h(bcrf_adm_local($t['expires_at'], 'F j, Y')) : '' ?>.
         Your party can come together or on different days.</p>
      <p>📱 Show this QR at the gate. Screenshot it or keep this page bookmarked.</p>
      <p><small>Online tickets don't reserve a time. We limit how many guests are on the farm at once, so on a busy day you may wait a little. Groups of 6 or more, please book ahead. Tickets are non-refundable.</small></p>
    <?php elseif ($expired && $left > 0): ?>
      <p>This ticket expired on <?= $h(bcrf_adm_local($t['expires_at'], 'F j, Y')) ?>.</p>
    <?php else: ?>
      <p>All <?= (int)$t['quantity'] ?> admissions on this ticket have been used. Thanks for visiting!</p>
      <a class="cta-btn" href="/tickets/">Buy more tickets</a>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
<?php require '../includes/footer.php'; ?>
