<?php
// gift-cards/submit.php — saves an online gift card order and sends the buyer to Stripe (Farm OS Phase 2, Nov 11, 2026).
// POST only, from gift-cards/index.php. Problems go back to the form with what was typed.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../admin/includes/audit.php';
require_once __DIR__ . '/../includes/gift_cards.php';

$back = function (string $msg) {
    $_SESSION['gc_buy_error'] = $msg;
    $keep = [];
    foreach (['amount', 'amount_other', 'send_to', 'recipient_name', 'recipient_email', 'message', 'purchaser_name', 'purchaser_email'] as $k) {
        if (isset($_POST[$k])) $keep[$k] = mb_substr((string)$_POST[$k], 0, 300);
    }
    $_SESSION['gc_buy_form'] = $keep;
    header('Location: /gift-cards/'); exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: /gift-cards/'); exit; }
$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || $csrf === '' || !hash_equals($_SESSION['csrf_token'], $csrf)) $back('Your session expired. Please try again.');
if (!bcrf_gift_cards_ready($pdo)) $back('Online gift cards aren\'t available right now. Please try again later.');

try {
    $card = bcrf_gc_online_create($pdo, $_POST);
} catch (BcrfGiftCardError $e) {
    $back($e->getMessage());
} catch (Throwable $e) {
    error_log('[gift-cards/submit] ' . $e->getMessage());
    $back('Something went wrong. Please try again, or call us.');
}
header('Location: /gift-cards/pay.php?g=' . rawurlencode($card['code'])); exit;
