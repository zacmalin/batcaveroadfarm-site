<?php
// account/verify.php
ob_start();
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/email_verify.php';
header('Cache-Control: no-store, private');

// Token rides in via the emailed GET link, then in the POST confirm form.
$token = (string)($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
$token = preg_replace('/[^a-f0-9]/', '', strtolower($token)); // tokens are hex

$error = '';
$done  = false;

// GET only validates (does not consume) so link prefetch / scanners can't burn it.
$live = null;
try { $live = evf_find($pdo, $token); }
catch (Throwable $e) { error_log('[verify lookup] ' . $e->getMessage()); $error = 'A server error occurred. Please try again.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please use the button again.';
    } elseif (throttle_hit('verify', 10, 900)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $uid = evf_consume($pdo, $token);
        if ($uid === null) {
            $error = 'This confirmation link is invalid or has expired.';
        } else {
            $done = true;
            if (is_logged_in() && current_user_id() === $uid) { $_SESSION['email_verified'] = true; }
        }
    }
}

ob_end_clean();

$pageTitle       = 'Confirm Your Email | Bat Cave Road Farm';
$metaDesc        = 'Confirm the email address on your Bat Cave Road Farm account.';
$canonicalUrl    = 'https://batcaveroadfarm.com/account/verify.php';
$hideBreadcrumbs = true;
require_once '../includes/head.php';
require_once '../includes/header.php';

$showConfirm = ($live !== null && !$done);
?>
<div class="content-wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <?php if ($done): ?>
        <h1>Email Confirmed</h1>
        <p class="auth-sub">Thanks — your email is verified.<?= is_logged_in() ? " You're all set." : '' ?></p>
        <p class="auth-alt"><a href="<?= is_logged_in() ? '/' : '/account/login.php' ?>"><?= is_logged_in() ? 'Back to the site' : 'Go to log in' ?></a></p>
      <?php elseif ($showConfirm): ?>
        <h1>Confirm Your Email</h1>
        <p class="auth-sub">Click below to verify this email address for your Bat Cave Road Farm account.</p>
        <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" action="/account/verify.php" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
          <button type="submit" class="auth-btn">Confirm my email</button>
        </form>
      <?php else: ?>
        <h1>Link Invalid or Expired</h1>
        <p class="auth-sub"><?= $error !== '' ? htmlspecialchars($error) : 'This confirmation link is invalid or has expired.' ?></p>
        <?php if (is_logged_in()): ?>
          <form method="POST" action="/account/resend_verification.php" novalidate>
            <?= csrf_field() ?>
            <button type="submit" class="auth-btn">Send me a new link</button>
          </form>
        <?php else: ?>
          <p class="auth-alt"><a href="/account/login.php">Log in</a> to request a new confirmation link.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
