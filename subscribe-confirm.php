<?php
// subscribe-confirm.php — second step of the mailing-list double opt-in (fix pack K).
//
// GET  (link from the email)  -> shows a "Confirm" button. Email security
//                                scanners open links automatically, so a bare
//                                GET never subscribes anyone.
// POST (button)               -> verifies the signature and adds the address.
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/subscribe_confirm.php';
require_once __DIR__ . '/admin/includes/audit.php';

$src       = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? $_POST : $_GET;
$email     = strtolower(trim((string)($src['e'] ?? '')));
$nursery   = (int)($src['n'] ?? 0) === 1 ? 1 : 0;
$inventory = (int)($src['i'] ?? 0) === 1 ? 1 : 0;
$ts        = (int)($src['ts'] ?? 0);
$token     = (string)($src['t'] ?? '');

$state = filter_var($email, FILTER_VALIDATE_EMAIL)
    ? bcrf_subconf_check($email, $nursery, $inventory, $ts, $token)
    : 'invalid';

$done = false;
$fail = false;
if ($state === 'ok' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $done = isset($pdo) && bcrf_subconf_apply($pdo, $email, $nursery, $inventory);
    $fail = !$done;
}

ob_end_clean();
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store, private');

$pageTitle    = 'Confirm Signup | Bat Cave Road Farm';
$metaDesc     = 'Confirm your Bat Cave Road Farm mailing list signup.';
$canonicalUrl = 'https://batcaveroadfarm.com/subscribe.php';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.contactus.css';
require_once __DIR__ . '/includes/head.php';
require_once __DIR__ . '/includes/header.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
        <div id="page-subscribe" class="page1">
          <div class="content-wrap1">
            <div class="contact-form reveal">
                <h2>Farm Connection</h2>

<?php if ($done): ?>
                <div class="subscribe-msg is-ok" role="status">You're on the list! Welcome to the farm community.</div>
                <p><?= $h($email) ?> will now get Bat Cave Road Farm updates. You can unsubscribe anytime from the link at the bottom of every email.</p>
                <p><a href="/">Back to the farm &rarr;</a></p>

<?php elseif ($fail): ?>
                <div class="subscribe-msg is-err" role="alert">Something went wrong on our end. Please try the link again in a moment, or email support@batcaveroadfarm.com.</div>

<?php elseif ($state === 'ok'): ?>
                <p>Confirm Bat Cave Road Farm updates for <strong><?= $h($email) ?></strong>?</p>
                <form method="POST" action="/subscribe-confirm.php" class="sub-form">
                    <input type="hidden" name="e"  value="<?= $h($email) ?>">
                    <input type="hidden" name="n"  value="<?= $nursery ?>">
                    <input type="hidden" name="i"  value="<?= $inventory ?>">
                    <input type="hidden" name="ts" value="<?= $ts ?>">
                    <input type="hidden" name="t"  value="<?= $h($token) ?>">
                    <button type="submit" class="form-submit cta-btn">Yes, sign me up</button>
                </form>

<?php elseif ($state === 'expired'): ?>
                <div class="subscribe-msg is-err" role="alert">This confirmation link has expired (links work for 7 days).</div>
                <p><a href="/subscribe.php">Sign up again &rarr;</a></p>

<?php else: ?>
                <div class="subscribe-msg is-err" role="alert">This confirmation link isn't valid. It may have been cut off by your email app.</div>
                <p><a href="/subscribe.php">Sign up again &rarr;</a></p>
<?php endif; ?>
            </div>
          </div>
        </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
