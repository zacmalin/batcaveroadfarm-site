<?php
// account/resend_verification.php
ob_start();
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/email_verify.php';
header('Cache-Control: no-store, private');

if (!is_logged_in()) { ob_end_clean(); header('Location: /account/login.php'); exit; }

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (throttle_hit('verify_resend', 5, 1800)) {
        $error = 'Too many requests. Please wait a little while and try again.';
    } else {
        try {
            $st = $pdo->prepare("SELECT email, first_name, email_verified_at FROM users WHERE id = ? LIMIT 1");
            $st->execute([current_user_id()]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            // Only send if still unverified; either way show the same confirmation.
            if ($u && empty($u['email_verified_at'])) {
                @evf_issue($pdo, current_user_id(), (string)$u['email'], (string)($u['first_name'] ?? ''));
            } elseif ($u && !empty($u['email_verified_at'])) {
                $_SESSION['email_verified'] = true;
            }
            $sent = true;
        } catch (Throwable $e) {
            error_log('[resend_verification] ' . $e->getMessage());
            $error = 'A server error occurred. Please try again.';
        }
    }
}

ob_end_clean();

$pageTitle       = 'Resend Verification | Bat Cave Road Farm';
$metaDesc        = 'Resend your Bat Cave Road Farm verification email.';
$canonicalUrl    = 'https://batcaveroadfarm.com/account/resend_verification.php';
$hideBreadcrumbs = true;
require_once '../includes/head.php';
require_once '../includes/header.php';
?>
<div class="content-wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <?php if ($sent): ?>
        <h1>Check Your Inbox</h1>
        <p class="auth-sub">If your email still needs confirming, a new verification link is on its way. It expires in 24 hours.</p>
        <p class="auth-alt"><a href="/">Back to the site</a></p>
      <?php else: ?>
        <h1>Resend Verification Email</h1>
        <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <p class="auth-sub">We'll send a fresh confirmation link to your account email.</p>
        <form method="POST" action="/account/resend_verification.php" novalidate>
          <?= csrf_field() ?>
          <button type="submit" class="auth-btn">Send verification email</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
