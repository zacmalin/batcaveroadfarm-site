<?php
// account/forgot.php
ob_start();
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../admin/includes/email_template.php';
header('Cache-Control: no-store, private');

if (is_logged_in()) { ob_end_clean(); header('Location: /'); exit; }

$error = '';
$sent  = false;
$emailOld = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailOld = trim((string)($_POST['email'] ?? ''));
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (honeypot_tripped()) {
        $error = 'Something went wrong. Please try again.';
    } elseif (throttle_hit('forgot', 5, 1800)) {
        $error = 'Too many requests. Please wait a little while and try again.';
    } elseif ($emailOld === '' || !filter_var($emailOld, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, first_name, email FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([strtolower($emailOld)]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Invalidate any earlier unused tokens for this user, then issue a fresh one.
                $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                    ->execute([(int)$user['id']]);

                $token = bin2hex(random_bytes(32));
                $hash  = hash('sha256', $token);
                $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
                    ->execute([(int)$user['id'], $hash]);

                $link = 'https://batcaveroadfarm.com/account/reset.php?token=' . $token;
                $name = trim((string)($user['first_name'] ?? '')) ?: 'there';
                $subject = 'Reset your Bat Cave Road Farm password';
                $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">Reset your password</h1>'
                    . '<p style="margin:0 0 16px;">Hi ' . htmlspecialchars($name) . ', we received a request to reset the password for your Bat Cave Road Farm account.</p>'
                    . '<p style="margin:0 0 24px;"><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px;">Choose a new password</a></p>'
                    . '<p style="margin:0;font-size:14px;color:rgba(245,234,214,0.8);">This link expires in 1 hour. If you didn\'t request this, you can safely ignore this email — your password won\'t change.</p>';
                $sentOk = function_exists('bcf_send_mail')
                    ? bcf_send_mail((string)$user['email'], $subject, $inner, ['subtitle' => 'Password Reset'])
                    : @mail((string)$user['email'], $subject, "Hi {$name},\r\n\r\nReset your password (expires in 1 hour):\r\n{$link}\r\n\r\nIf you didn't request this, ignore this email.\r\n— Bat Cave Road Farm\r\n", "From: Bat Cave Road Farm <no-reply@batcaveroadfarm.com>\r\nContent-Type: text/plain; charset=UTF-8\r\n", '-fno-reply@batcaveroadfarm.com');
                if (!$sentOk) {
                    error_log('[forgot] reset email failed to send (mail() returned false)');
                }
            }
            // Always show the same message — never reveal whether an account exists.
            $sent = true;
        } catch (Throwable $e) {
            error_log('[forgot] ' . $e->getMessage());
            $error = 'A server error occurred. Please try again.';
        }
    }
}

ob_end_clean();

$pageTitle       = 'Forgot Password | Bat Cave Road Farm';
$metaDesc        = 'Reset your Bat Cave Road Farm account password.';
$canonicalUrl    = 'https://batcaveroadfarm.com/account/forgot.php';
$hideBreadcrumbs = true;
require_once '../includes/head.php';
require_once '../includes/header.php';
?>
<div class="content-wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <?php if ($sent): ?>
        <h1>Check Your Email</h1>
        <p class="auth-sub">If an account exists for that address, a password-reset link is on its way. The link expires in 1 hour.</p>
        <p class="auth-alt"><a href="/account/login.php">Back to log in</a></p>
      <?php else: ?>
        <h1>Forgot Password</h1>
        <p class="auth-sub">Enter your email and we'll send you a link to reset your password.</p>

        <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST" action="/account/forgot.php" novalidate>
          <?= csrf_field() ?>
          <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="auth-field">
            <label for="forgot-email">Email</label>
            <input type="email" id="forgot-email" name="email" autocomplete="email" required value="<?= htmlspecialchars($emailOld, ENT_QUOTES) ?>">
          </div>
          <button type="submit" class="auth-btn">Send Reset Link</button>
        </form>

        <p class="auth-alt">Remembered it? <a href="/account/login.php">Back to log in</a></p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
