<?php
// account/link.php — sign in with an emailed link (Farm OS Phase 3 slice 6, Nov 12, 2026; includes/customer_portal.php).
//   GET            the form: type your email, get a one-time link (20 minutes)
//   POST email     sends the link (same answer whether or not the address is known; 3 an hour per address, 10 per IP)
//   GET ?token=    shows a "Sign in" button; POST token spends the link and signs in. The button step is on purpose:
//                  mail scanners open links, and a plain GET would use the link up before the customer clicks.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
ob_start();
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/rate_limit.php';
require_once '../includes/customer_auth.php';
require_once '../includes/customer_portal.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');   // keep the token out of any outgoing Referer
header('X-Robots-Tag: noindex, nofollow');

$next = safe_next((string)($_GET['next'] ?? ($_POST['next'] ?? '')), '/account/');
if (is_logged_in() && empty($_GET['token']) && empty($_POST['token'])) { ob_end_clean(); header('Location: ' . $next); exit; }

$error = '';
$sent = false;
$emailOld = '';
$token = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['token'] ?? ($_GET['token'] ?? ''))));
$who = $token !== '' ? bcrf_portal_signin_peek($pdo, $token) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($token !== '') {
        try {
            $c = bcrf_portal_signin_use($pdo, $token);
            if ($c) {
                auth_login_user($c);
                ob_end_clean();
                header('Location: ' . $next);
                exit;
            }
            $who = null;
        } catch (Throwable $e) {
            error_log('[portal link] ' . $e->getMessage());
            $error = 'A server error occurred. Please try again.';
        }
    } else {
        $emailOld = trim((string)($_POST['email'] ?? ''));
        if (honeypot_tripped()) {
            $error = 'Something went wrong. Please try again.';
        } elseif ($emailOld === '' || !filter_var($emailOld, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (throttle_hit('signin_link', 5, 1800) || bcrf_rl_hit('signin_link_ip', bcrf_rl_ip(), 10, 3600)) {
            $error = 'Too many requests. Please wait a little while and try again.';
        } elseif (bcrf_rl_hit('signin_link_email', strtolower($emailOld), 3, 3600)) {
            $sent = true;   // quietly stop flooding an inbox; same message as normal
        } else {
            try { bcrf_portal_signin_request($pdo, $emailOld); }
            catch (Throwable $e) { error_log('[portal link] ' . $e->getMessage()); }
            $sent = true;   // never reveal whether an address is known
        }
    }
}

ob_end_clean();

$pageTitle       = 'Sign In by Email | ' . bcrf_farm_name();
$metaDesc        = 'Get a one-time link to sign in and see your bookings, tickets and passes.';
$metaRobots      = 'noindex, nofollow';
$canonicalUrl    = bcrf_farm_url('/account/link.php');
$hideBreadcrumbs = true;
require_once '../includes/head.php';
require_once '../includes/header.php';
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
?>
<div class="content-wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <?php if ($token !== ''): ?>
        <?php if ($who): ?>
          <h1>Sign In</h1>
          <p class="auth-sub">Signing in as <strong><?= $h($who['email']) ?></strong>.</p>
          <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= $h($error) ?></div><?php endif; ?>
          <form method="POST" action="/account/link.php">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= $h($token) ?>">
            <input type="hidden" name="next" value="<?= $h($next) ?>">
            <button type="submit" class="auth-btn">Sign In</button>
          </form>
        <?php else: ?>
          <h1>Link Expired</h1>
          <p class="auth-sub">That sign-in link has been used or is more than <?= BCRF_PORTAL_LINK_MINUTES ?> minutes old. Get a fresh one below.</p>
          <p class="auth-alt"><a href="/account/link.php">Email me a new link</a></p>
        <?php endif; ?>

      <?php elseif ($sent): ?>
        <h1>Check Your Email</h1>
        <p class="auth-sub">If we have bookings, tickets, a pass or an account for that address, a sign-in link is on its way. It works once, for <?= BCRF_PORTAL_LINK_MINUTES ?> minutes.</p>
        <p class="auth-alt">Nothing arrived? Check spam, or <a href="/account/link.php">try again</a>.</p>

      <?php else: ?>
        <h1>Sign In by Email</h1>
        <p class="auth-sub">See your bookings, parties, tickets and passes. Enter the email you booked with and we'll send you a link. No password needed.</p>
        <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= $h($error) ?></div><?php endif; ?>
        <form method="POST" action="/account/link.php" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="<?= $h($next) ?>">
          <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="auth-field">
            <label for="link-email">Email</label>
            <input type="email" id="link-email" name="email" autocomplete="email" required value="<?= $h($emailOld) ?>">
          </div>
          <button type="submit" class="auth-btn">Email Me a Link</button>
        </form>
        <p class="auth-alt">Have a password? <a href="/account/login.php?next=<?= $h(urlencode($next)) ?>">Log in with it</a></p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
