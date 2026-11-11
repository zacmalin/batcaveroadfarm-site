<?php
// admin/gift_card_print.php — printable gift cards (Farm OS Phase 2, Nov 11, 2026; includes/gift_cards.php)
//   ?sale=N   every active card sold on that register sale (the register's "Print gift card" button)
//   ?id=N     one card (Gift Cards screen)
// Business-card size (3.5 × 2 in), several to a page, with cut marks. The QR holds the card's page
// (/gift-cards/card.php?g=CODE), which the register's gift card Scan reads. Full-screen print layout:
// listed in tools/admin-shell-exceptions.txt. Strict CSP: looks in assets/giftcard.css, QR + Print in assets/giftcard.js.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
if (($_SESSION['user_role'] ?? '') === 'Media') { http_response_code(403); exit('Media accounts cannot print gift cards.'); }
require_permission([['view', 'pos']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/gift_cards.php';
require_once __DIR__ . '/../includes/site_info.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$cards = [];
if (bcrf_gift_cards_ready($pdo)) {
    // The full code is on the card, so: register staff can print cards from today's sales; an Owner or Manager can print any card.
    $mgr = bcrf_gc_is_manager();
    if ($sid = (int)($_GET['sale'] ?? 0)) {
        $st = $pdo->prepare("SELECT g.id FROM gift_cards g JOIN sales s ON s.id = g.sale_id
                             WHERE g.sale_id = ? AND g.status = 'active' AND (? = 1 OR s.business_date = ?) ORDER BY g.id");
        $st->execute([$sid, $mgr ? 1 : 0, (new DateTimeImmutable('now', new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York')))->format('Y-m-d')]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $cards[] = bcrf_gc_get($pdo, (int)$id);
    } elseif (($id = (int)($_GET['id'] ?? 0)) && $mgr) {
        if (($c = bcrf_gc_get($pdo, $id)) && $c['status'] === 'active') $cards[] = $c;
    }
}
foreach ($cards as $c) bcrf_gc_audit($pdo, 'gift_card_print', $c, 'printed');
$farm = bcrf_farm_name();
$domain = function_exists('bcrf_farm_domain') ? bcrf_farm_domain() : '';
$phone = function_exists('site_phone_display') ? site_phone_display() : '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'">
<meta name="robots" content="noindex">
<title>Gift card — <?= $h($farm) ?></title>
<link rel="stylesheet" href="assets/giftcard.css?v=1">
<script src="/js/qrcode.min.js" defer></script>
<script src="assets/giftcard.js?v=1" defer></script>
</head>
<body>
<header class="noprint">
  <a href="register.php">← Register</a> · <a href="gift_cards.php">Gift Cards</a>
  <?php if ($cards): ?><button type="button" id="btnPrint">Print</button><?php endif; ?>
  <p>Prints at business-card size (3.5 × 2 in). Cut along the marks; card stock works best. In the print dialog, set scale to 100% (not "fit").</p>
</header>
<?php if (!$cards): ?>
  <p class="none">No gift card to print here. It may have been cancelled or refunded, or it was sold on an earlier day (an Owner or Manager can reprint it from Gift Cards).</p>
<?php else: ?>
<main class="sheet">
  <?php foreach ($cards as $c): ?>
  <section class="gc">
    <div class="gc-top">
      <img class="gc-logo" src="/img/logo.webp" alt="">
      <div>
        <div class="gc-farm"><?= $h($farm) ?></div>
        <div class="gc-kind">Gift Card</div>
      </div>
      <div class="gc-amt"><?= $h(bcrf_gc_money($c['initial_cents'])) ?></div>
    </div>
    <div class="gc-mid">
      <div class="gc-qr" data-url="<?= $h(bcrf_gc_url($c['code'])) ?>" aria-label="QR code for this gift card"></div>
      <div class="gc-text">
        <div class="gc-code"><?= $h($c['code_fmt']) ?></div>
        <div class="gc-small">Never expires. Use it at the farm stand, gate or thrift shop. Scan for the balance.</div>
        <div class="gc-small">Treat it like cash. Not redeemable for cash.</div>
      </div>
    </div>
    <div class="gc-foot"><?= $h($domain) ?><?= $phone !== '' ? ' · ' . $h($phone) : '' ?></div>
  </section>
  <?php endforeach; ?>
</main>
<?php endif; ?>
</body>
</html>
