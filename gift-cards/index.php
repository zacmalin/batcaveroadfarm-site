<?php
// gift-cards/index.php — buy a gift card online (Farm OS Phase 2, Nov 11, 2026; includes/gift_cards.php)
// $25 / $50 / $100 or any whole amount $10–$500. Emailed to the buyer or straight to the person it's for, with an
// optional message. POST → gift-cards/submit.php → Stripe Checkout → gift-cards/card.php (the card, once paid).
// Spent at the farm (register) for now; online redemption can come later (owner decision, Nov 11, 2026).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/gift_cards.php';
if (!function_exists('site_phone_display')) require_once __DIR__ . '/../includes/site_info.php';

$pageTitle    = 'Gift Cards — ' . bcrf_farm_name();
$metaDesc     = 'Give a ' . bcrf_farm_name() . ' gift card: petting zoo admission, the farm stand and the thrift shop in Old Fort, NC. Emailed instantly, never expires.';
$canonicalUrl = bcrf_farm_url() . '/gift-cards/';
$ogImage      = bcrf_farm_url() . '/img/og-pettingzoo.jpg';
$pageCSS      = bcrf_farm_url() . '/css/min.tickets.css';
$pageJS       = bcrf_farm_url() . '/js/min.giftcards.js';
require '../includes/head.php';
include '../includes/header.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$err = (string)($_SESSION['gc_buy_error'] ?? ''); unset($_SESSION['gc_buy_error']);
$d = (array)($_SESSION['gc_buy_form'] ?? []); unset($_SESSION['gc_buy_form']);
$ready = bcrf_gift_cards_ready($pdo) && defined('BCRF_STRIPE_SK') && BCRF_STRIPE_SK !== '';
$amt = (string)($d['amount'] ?? '5000');
$sendTo = ($d['send_to'] ?? 'them') === 'me' ? 'me' : 'them';
?>
<style>
.gc-amounts{margin:0 0 10px}.gc-amt-opt{justify-content:flex-start;cursor:pointer}.gc-amt-opt input{width:20px;height:20px;accent-color:#8A3324;margin:0}
#gc-form textarea{width:100%;padding:10px 14px;border:2px solid #c9923a;border-radius:9px;font-family:'Lora',serif;font-size:.95rem;color:var(--brown-dark);background:#fffdf7;box-sizing:border-box;resize:vertical}
#gc-form .tickets-field{margin-top:10px}
</style>
<div class="tickets-wrap">
    <div class="tickets-intro">
        <h1>Gift Cards</h1>
        <p>Give a day on the farm. A <?= $h(bcrf_farm_name()) ?> gift card works for petting zoo admission, the farm stand and the thrift shop. It's emailed right away and <strong>never expires</strong>.</p>
    </div>

    <?php if ($err !== ''): ?><div class="tickets-error" role="alert"><?= $h($err) ?></div><?php endif; ?>

    <?php if (!$ready): ?>
    <div class="tickets-unavailable">
        <p>Online gift cards aren't available right now. You can buy one at the farm stand, or call <a href="tel:<?= $h(site_tel('general')) ?>"><?= $h(site_phone_display('general')) ?></a>.</p>
    </div>
    <?php else: ?>
    <form id="gc-form" method="POST" action="/gift-cards/submit.php">
        <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">

        <section class="tickets-section">
            <h2>Choose an amount</h2>
            <div class="gc-amounts" role="radiogroup" aria-label="Amount">
                <?php foreach (BCRF_GC_ONLINE_PRESETS as $p): ?>
                <label class="ticket-type-row gc-amt-opt"><input type="radio" name="amount" value="<?= $p ?>"<?= $amt === (string)$p ? ' checked' : '' ?>> <span class="ticket-type-name">$<?= $p / 100 ?></span></label>
                <?php endforeach; ?>
                <label class="ticket-type-row gc-amt-opt"><input type="radio" name="amount" value="other"<?= $amt === 'other' ? ' checked' : '' ?>> <span class="ticket-type-name">Other amount</span></label>
            </div>
            <div class="tickets-field" id="gc-other-wrap">
                <label for="gc_other">Other amount ($10–$500, whole dollars)</label>
                <input type="number" id="gc_other" name="amount_other" min="10" max="500" step="1" inputmode="numeric" placeholder="e.g. 40" value="<?= $h($d['amount_other'] ?? '') ?>">
            </div>
        </section>

        <section class="tickets-section">
            <h2>Who is it for?</h2>
            <div class="gc-amounts" role="radiogroup" aria-label="Send to">
                <label class="ticket-type-row gc-amt-opt"><input type="radio" name="send_to" value="them"<?= $sendTo === 'them' ? ' checked' : '' ?>> <span class="ticket-type-name">Email it to them</span></label>
                <label class="ticket-type-row gc-amt-opt"><input type="radio" name="send_to" value="me"<?= $sendTo === 'me' ? ' checked' : '' ?>> <span class="ticket-type-name">Send it to me (I'll print or forward it)</span></label>
            </div>
            <div class="tickets-fields" id="gc-recipient">
                <div class="tickets-field">
                    <label for="gc_rname">Their name <span class="tickets-optional">(optional)</span></label>
                    <input type="text" id="gc_rname" name="recipient_name" maxlength="120" autocomplete="off" value="<?= $h($d['recipient_name'] ?? '') ?>">
                </div>
                <div class="tickets-field">
                    <label for="gc_remail">Their email</label>
                    <input type="email" id="gc_remail" name="recipient_email" maxlength="190" autocomplete="off" placeholder="them@example.com" value="<?= $h($d['recipient_email'] ?? '') ?>">
                </div>
            </div>
            <div class="tickets-field">
                <label for="gc_msg">Message <span class="tickets-optional">(optional, up to 300 characters)</span></label>
                <textarea id="gc_msg" name="message" maxlength="300" rows="3" placeholder="Happy birthday! Say hi to the goats for me."><?= $h($d['message'] ?? '') ?></textarea>
            </div>
        </section>

        <section class="tickets-section">
            <h2>Your details</h2>
            <div class="tickets-fields">
                <div class="tickets-field">
                    <label for="gc_name">Your name *</label>
                    <input type="text" id="gc_name" name="purchaser_name" required maxlength="120" autocomplete="name" value="<?= $h($d['purchaser_name'] ?? '') ?>">
                </div>
                <div class="tickets-field">
                    <label for="gc_email">Your email *</label>
                    <input type="email" id="gc_email" name="purchaser_email" required maxlength="190" autocomplete="email" placeholder="you@example.com" value="<?= $h($d['purchaser_email'] ?? '') ?>">
                </div>
            </div>
        </section>

        <p class="purchase-policy">Gift cards are spent at the farm (farm stand, gate and thrift shop) and can't be used for online orders yet. They never expire, aren't refundable, and can't be exchanged for cash. Treat the code like cash: anyone who has it can spend it. <a href="<?= bcrf_farm_url() ?>/terms/#purchases">Full policy</a></p>
        <div class="tickets-actions">
            <button type="submit" class="cta-btn tickets-buy-btn">Buy Gift Card →</button>
            <p class="tickets-secure-note">🔒 Secure checkout via Stripe — no card details stored on our servers.</p>
        </div>
    </form>
    <?php endif; ?>

    <div class="tickets-group-note">
        <strong>Rather give a year of visits?</strong> An <a href="/passes/">annual pass</a> makes a great gift too.
    </div>
</div>
<?php require '../includes/footer.php'; ?>
