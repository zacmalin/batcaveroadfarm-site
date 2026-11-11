<?php
// gift-cards/card.php — one gift card, by its code (?g=CODE). Farm OS Phase 2, Nov 11, 2026 (includes/gift_cards.php).
// What the emailed link and the printed card's QR open: the amount, what's left, the code and its QR (the register's
// gift card Scan reads it). Online orders not confirmed yet ask Stripe directly (the webhook may be a few seconds behind)
// and offer "Pay now" if checkout was left unfinished. The code is the key: whoever has it can spend it, so noindex.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../admin/includes/audit.php';
require_once __DIR__ . '/../includes/gift_cards.php';

$c = bcrf_gift_cards_ready($pdo) ? bcrf_gc_by_code($pdo, (string)($_GET['g'] ?? '')) : null;
if ($c && $c['status'] === 'pending') $c = bcrf_gc_online_check_stripe($pdo, $c);

$pageTitle  = 'Your Gift Card — ' . bcrf_farm_name();
$metaRobots = 'noindex, nofollow';
$pageCSS    = bcrf_farm_url() . '/css/min.passes.css';
$pageJS     = [bcrf_farm_url() . '/js/qrcode.min.js', bcrf_farm_url() . '/js/min.passes.js'];   // min.passes.js draws #pass-qr from data-url
require '../includes/head.php';
include '../includes/header.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$state = !$c ? 'missing' : ($c['status'] === 'void' ? 'void' : ($c['status'] === 'pending' ? 'unpaid' : 'ok'));
?>
<style>
.gc-card-amt{font-family:'Young Serif',Georgia,serif;font-size:2.4rem;margin:6px 0 2px;color:#3E2A1E}
.gc-card-left{font-size:1.05rem;margin:0 0 14px}
.gc-card-code{font-family:'Courier New',monospace;font-weight:bold;font-size:1.2rem;letter-spacing:.04em}
.gc-card-msg{font-style:italic;border-left:3px solid #c9923a;padding:8px 14px;margin:14px auto;max-width:28rem;text-align:left}
@media print{header,footer,nav,.cta-btn,.gc-noprint{display:none!important}}
</style>
<div class="pass-success-wrap">
  <div class="pass-success-card">
  <?php if ($state === 'missing'): ?>
    <h1>Gift card not found</h1>
    <p>We couldn't find that gift card. Check the link in your email, or call <?= $h(function_exists('site_phone_display') ? site_phone_display() : '') ?> and we'll sort it out.</p>
    <a class="cta-btn" href="/gift-cards/">Buy a gift card</a>

  <?php elseif ($state === 'void'): ?>
    <h1>This gift card was cancelled</h1>
    <p>Gift card <?= $h(bcrf_gc_masked($c['code'])) ?> can't be used. If that's a surprise, please get in touch.</p>

  <?php elseif ($state === 'unpaid'): ?>
    <h1><?= isset($_GET['paid']) ? 'Confirming your payment…' : 'Payment not finished' ?></h1>
    <?php if (isset($_GET['paid'])): ?>
      <p>This usually takes a few seconds. Refresh this page in a moment; we'll also email the gift card to <strong><?= $h($c['recipient_email'] ?: $c['purchaser_email']) ?></strong>.</p>
    <?php else: ?>
      <p><?= isset($_GET['error']) ? 'We couldn\'t start the payment. Please try again.' : 'Your gift card order is saved, but it hasn\'t been paid for yet.' ?></p>
      <a class="cta-btn" href="/gift-cards/pay.php?g=<?= rawurlencode($c['code']) ?>">Pay now (<?= $h(bcrf_gc_money($c['initial_cents'])) ?>)</a>
    <?php endif; ?>

  <?php else: ?>
    <?php if (isset($_GET['paid']) && $c['source'] === 'online'): ?>
      <p class="gc-noprint"><strong>Thank you!</strong> We've emailed this gift card to <?= $h($c['recipient_email'] ?: $c['purchaser_email']) ?>.</p>
    <?php endif; ?>
    <h1><?= $h(bcrf_farm_name()) ?> Gift Card</h1>
    <p class="gc-card-amt"><?= $h(bcrf_gc_money($c['initial_cents'])) ?></p>
    <p class="gc-card-left"><?= $c['balance_cents'] === $c['initial_cents'] ? 'Ready to use' : ($c['balance_cents'] > 0 ? '<strong>' . $h(bcrf_gc_money($c['balance_cents'])) . '</strong> left on this card' : 'This card has been used up. Thanks for visiting!') ?></p>
    <?php if ($c['recipient_name']): ?><p>For <strong><?= $h($c['recipient_name']) ?></strong><?= $c['purchaser_name'] ? ', from ' . $h($c['purchaser_name']) : '' ?></p><?php endif; ?>
    <?php if ($c['message']): ?><p class="gc-card-msg"><?= nl2br($h($c['message'])) ?></p><?php endif; ?>
    <?php if ($c['balance_cents'] > 0): ?>
      <div id="pass-qr" data-url="<?= $h(bcrf_gc_url($c['code'])) ?>"></div>
      <p class="pass-link-box">Code <span class="gc-card-code"><?= $h($c['code_fmt']) ?></span></p>
      <p>Show this QR code (or the code) at the farm stand, gate or thrift shop. It never expires, and whatever you don't spend stays on the card for next time.</p>
      <p><small>Treat the code like cash: anyone who has it can spend it. Gift cards can't be used for online orders yet and can't be exchanged for cash.</small></p>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
<?php require '../includes/footer.php'; ?>
