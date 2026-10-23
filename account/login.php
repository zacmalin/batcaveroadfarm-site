<?php
ob_start(); // buffer so header() redirects work even if an include emits whitespace
require_once '../config.php';
require_once '../includes/auth.php';

$next = safe_next((string)($_GET['next'] ?? ($_POST['next'] ?? '')));

if (is_logged_in()) { ob_end_clean(); header('Location: ' . $next); exit; }

$error = '';
$emailOld = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailOld = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (honeypot_tripped()) {
        $error = 'Something went wrong. Please try again.';
    } elseif (throttle_hit('login', 8, 600)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif ($emailOld === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([strtolower($emailOld)]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($password, (string)($user['password'] ?? ''))) {
                auth_login_user($user);
                ob_end_clean();
                header('Location: ' . $next);
                exit;
            }
            $error = 'Email or password is incorrect.';
        } catch (Throwable $e) {
            error_log('[Account login] ' . $e->getMessage());
            $error = 'A server error occurred. Please try again.';
        }
    }
}

ob_end_clean();

$pageTitle       = 'Log In | Bat Cave Road Farm';
$metaDesc        = 'Log in to your Bat Cave Road Farm account.';
$canonicalUrl    = 'https://batcaveroadfarm.com/account/login.php';
$hideBreadcrumbs = true;
require_once '../includes/head.php';
require_once '../includes/header.php';
?>
<div class="content-wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <h1>Welcome Back</h1>
      <p class="auth-sub">Log in to rate and comment on recipes.</p>

      <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <form method="POST" action="/account/login.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
        <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="auth-field">
          <label for="login-email">Email</label>
          <input type="email" id="login-email" name="email" autocomplete="email" required value="<?= htmlspecialchars($emailOld, ENT_QUOTES) ?>">
        </div>
        <div class="auth-field">
          <label for="login-password">Password</label>
          <input type="password" id="login-password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="auth-btn">Log In</button>
      </form>

      <p class="auth-alt"><a href="/account/forgot.php">Forgot your password?</a></p>
      <p class="auth-alt">New here? <a href="/account/register.php<?= $next !== '/' ? '?next=' . urlencode($next) : '' ?>">Create an account</a></p>
    </div>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
