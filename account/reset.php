<?php
ob_start();
require_once '../config.php';
require_once '../includes/auth.php';
header('Cache-Control: no-store, private');

if (is_logged_in()) { ob_end_clean(); header('Location: /'); exit; }

$error = '';
$done  = false;

// Token comes in via the emailed GET link, then rides along in the POST form.
$token = (string)($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
$token = preg_replace('/[^a-f0-9]/', '', strtolower($token)); // tokens are hex

/** Look up a live (unused, unexpired) reset row for a raw token. */
$findReset = function (PDO $pdo, string $rawToken) {
    if ($rawToken === '') return null;
    $st = $pdo->prepare("SELECT id, user_id, expires_at FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
    $st->execute([hash('sha256', $rawToken)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$reset = null;
try { $reset = $findReset($pdo, $token); }
catch (Throwable $e) { error_log('[reset lookup] ' . $e->getMessage()); $error = 'A server error occurred. Please try again.'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $pw   = (string)($_POST['password'] ?? '');
    $conf = (string)($_POST['password_confirm'] ?? '');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (honeypot_tripped()) {
        $error = 'Something went wrong. Please try again.';
    } elseif (throttle_hit('reset', 8, 900)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif (!$reset) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw !== $conf) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$reset['user_id']]);
            // Single-use: burn this token and any other outstanding ones for the user.
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([(int)$reset['user_id']]);
            $pdo->commit();
            $done = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[reset commit] ' . $e->getMessage());
            $error = 'A server error occurred. Please try again.';
        }
    }
}

ob_end_clean();

$pageTitle       = 'Reset Password | Bat Cave Road Farm';
$metaDesc        = 'Choose a new password for your Bat Cave Road Farm account.';
$canonicalUrl    = 'https://batcaveroadfarm.com/account/reset.php';
$hideBreadcrumbs = true;
require_once '../includes/head.php';
require_once '../includes/header.php';

$showForm = ($reset !== null && !$done);
?>
<div class="content-wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <?php if ($done): ?>
        <h1>Password Updated</h1>
        <p class="auth-sub">Your password has been changed. You can now log in with your new password.</p>
        <p class="auth-alt"><a href="/account/login.php">Go to log in</a></p>
      <?php elseif (!$showForm): ?>
        <h1>Link Expired</h1>
        <p class="auth-sub"><?= $error !== '' ? htmlspecialchars($error) : 'This reset link is invalid or has expired.' ?></p>
        <p class="auth-alt"><a href="/account/forgot.php">Request a new reset link</a></p>
      <?php else: ?>
        <h1>Choose a New Password</h1>
        <p class="auth-sub">Enter a new password for your account.</p>

        <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST" action="/account/reset.php" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
          <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="auth-field">
            <label for="reset-pw">New password</label>
            <input type="password" id="reset-pw" name="password" autocomplete="new-password" required>
            <p class="auth-hint">At least 8 characters.</p>
          </div>
          <div class="auth-field">
            <label for="reset-pw2">Confirm new password</label>
            <input type="password" id="reset-pw2" name="password_confirm" autocomplete="new-password" required>
          </div>
          <button type="submit" class="auth-btn">Update Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
