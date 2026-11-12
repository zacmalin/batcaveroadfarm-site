<?php
// booking/quote.php — the customer's quote for a group booking or party, by its link (?q=TOKEN, 32 hex characters).
// Farm OS Phase 3 slice 4 (Nov 12, 2026; includes/event_quotes.php). Shows the price lines, deposit, what's been paid
// and what's left, and the farm's terms. When the farm turned online payment on, offers Pay deposit / Pay balance
// (booking/quote_pay.php → Stripe Checkout). Back from Stripe (&paid=1) it asks Stripe directly in case the webhook is
// a few seconds behind. Token-only URL (no Stripe session id in it: that trips ModSecurity). The token is the key, so noindex.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../admin/includes/audit.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';

$q = bcrf_quotes_ready($pdo) ? bcrf_quote_by_token($pdo, (string)($_GET['q'] ?? '')) : null;
$s = null; $st = null; $pay = ['deposit' => 0, 'balance' => 0]; $pays = [];
if ($q) {
    if (isset($_GET['paid'])) bcrf_quote_check_stripe($pdo, $q);
    bcrf_quote_mark_viewed($pdo, $q);
    $s = bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
    $st = bcrf_quote_status($pdo, $q);
    $pay = $s ? bcrf_quote_payable($pdo, $q, $s, $st) : $pay;
    $pays = array_values(array_filter(bcrf_quote_payments($pdo, $q['id']), fn($p) => !$p['voided_at']));
}
$stripeReady = defined('BCRF_STRIPE_SK') && BCRF_STRIPE_SK !== '';

$pageTitle  = 'Your Quote — ' . bcrf_farm_name();
$metaRobots = 'noindex, nofollow';
$pageCSS    = [bcrf_farm_url() . '/css/min.passes.css', bcrf_farm_url() . '/css/min.quote.css'];
require '../includes/head.php';
include '../includes/header.php';

$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
$m = fn($c) => bcrf_quote_money((int)$c);
$tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
$local = fn($utc) => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('M j, Y');
?>
<div class="pass-success-wrap">
  <div class="pass-success-card quote-card">
  <?php if (!$q || !$s): ?>
    <h1>Quote not found</h1>
    <p>We couldn't find that quote. Check the link in your email, or call <?= $h(function_exists('site_phone_display') ? site_phone_display() : '') ?> and we'll sort it out.</p>

  <?php else: ?>
    <?php if (isset($_GET['paid'])): ?>
      <p class="quote-flash"><?= $st['paid'] > 0 ? '<strong>Thank you!</strong> Your payment is in. We\'ve emailed you a receipt.' : 'Confirming your payment… this usually takes a few seconds. Refresh this page in a moment.' ?></p>
    <?php elseif (isset($_GET['cancelled'])): ?>
      <p class="quote-flash">Payment cancelled. Nothing was charged.</p>
    <?php elseif (isset($_GET['error'])): ?>
      <p class="quote-flash quote-flash-err">We couldn't start the payment. Please try again, or call us.</p>
    <?php endif; ?>

    <h1>Your <?= $h(strtolower($s['what'])) ?> quote</h1>
    <p class="quote-when"><strong><?= $h($s['name']) ?></strong><br><?= $h(bcrf_quote_when($s)) ?><br><?= (int)$s['guests'] ?> guest<?= $s['guests'] === 1 ? '' : 's' ?></p>

    <?php if (!$s['live']): ?>
      <p class="quote-flash quote-flash-err">This <?= $s['is_party'] ? 'party' : 'booking' ?> is <?= $h($s['status']) ?>. If that's a surprise, please get in touch.</p>
    <?php endif; ?>

    <table class="quote-lines">
      <?php foreach ($q['lines'] as $l): ?>
      <tr><td><?= $h($l['label']) ?><?php if ((int)$l['qty'] > 1): ?> <span class="quote-muted">(<?= (int)$l['qty'] ?> × <?= $h($m($l['unit_cents'])) ?>)</span><?php endif; ?></td>
          <td class="quote-amt"><?= $h($m((int)$l['qty'] * (int)$l['unit_cents'])) ?></td></tr>
      <?php endforeach; ?>
      <tr class="quote-total"><td>Total</td><td class="quote-amt"><?= $h($m($q['total_cents'])) ?></td></tr>
      <?php foreach ($pays as $p): ?>
      <tr class="quote-paid"><td><?= $p['kind'] === 'refund' ? 'Refunded' : 'Paid' ?> <?= $h($local($p['created_at'])) ?></td><td class="quote-amt"><?= $h($m(-$p['amount_cents'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($pays): ?><tr class="quote-total"><td><?= $st['balance'] < 0 ? 'We owe you' : 'Left to pay' ?></td><td class="quote-amt"><?= $h($m(abs($st['balance']))) ?></td></tr><?php endif; ?>
    </table>

    <?php if ($st['balance'] <= 0 && $st['paid'] > 0): ?>
      <p><strong>You're all paid up.</strong> See you on the farm!</p>
    <?php else: ?>
      <?php if ($st['deposit_left'] > 0): ?><p><strong>Deposit: <?= $h($m($st['deposit_left'])) ?></strong><?= $q['deposit_due'] ? ' by ' . $h(date('l, F j', strtotime($q['deposit_due']))) : '' ?>.</p><?php endif; ?>
      <?php if ($st['balance'] > 0): ?><p><?= $st['deposit_left'] > 0 ? 'The rest' : 'The ' . $h($m($st['balance'])) ?> is due <?= $q['balance_when'] === 'before' ? 'before the day' : 'when you arrive' ?>.</p><?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($q['customer_note'])): ?><p class="quote-note"><?= nl2br($h($q['customer_note'])) ?></p><?php endif; ?>

    <?php if ($stripeReady && ($pay['deposit'] > 0 || $pay['balance'] > 0)): ?>
      <div class="quote-pay">
        <?php if ($pay['deposit'] > 0): ?><a class="cta-btn" href="/booking/quote_pay.php?q=<?= rawurlencode($q['token']) ?>&amp;what=deposit">Pay the deposit (<?= $h($m($pay['deposit'])) ?>)</a><?php endif; ?>
        <a class="cta-btn<?= $pay['deposit'] > 0 ? ' quote-btn-alt' : '' ?>" href="/booking/quote_pay.php?q=<?= rawurlencode($q['token']) ?>&amp;what=balance">Pay <?= $pay['deposit'] > 0 ? 'it all' : '' ?> now (<?= $h($m($pay['balance'])) ?>)</a>
        <p class="quote-muted">Secure card payment through Stripe.</p>
      </div>
    <?php endif; ?>

    <h2>Good to know</h2>
    <ul class="quote-terms">
      <?php foreach (bcrf_quote_terms($q, $s) as $t): ?><li><?= $h($t) ?></li><?php endforeach; ?>
    </ul>
    <p>Questions, or need to change anything? Email <a href="mailto:<?= $h(bcrf_farm_email('support')) ?>"><?= $h(bcrf_farm_email('support')) ?></a><?= function_exists('site_phone_display') && site_phone_display() ? ' or call ' . $h(site_phone_display()) : '' ?>.</p>
    <?php require_once __DIR__ . '/../includes/nc_warning.php'; echo bcrf_nc_warning_html(); ?>
  <?php endif; ?>
  </div>
</div>
<?php require '../includes/footer.php'; ?>
