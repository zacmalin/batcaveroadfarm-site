<?php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
ob_start();
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/email_verify.php';
require_once '../includes/rate_limit.php';   // server-side signup limits (Nov 2026)

$next = safe_next((string)($_GET['next'] ?? ($_POST['next'] ?? '')));

if (is_logged_in()) { ob_end_clean(); header('Location: ' . $next); exit; }

$error = '';
$old   = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone_number' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) { $old[$k] = trim((string)($_POST[$k] ?? '')); }
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (honeypot_tripped()) {
        $error = 'Something went wrong. Please try again.';
    } elseif (throttle_hit('register', 5, 1800)
              // Server-side (survives dropped cookies): 5 per hour, 10 per day per connection.
              | bcrf_rl_hit('reg_ip', bcrf_rl_ip(), 5, 3600)
              | bcrf_rl_hit('reg_ipday', bcrf_rl_ip(), 10, 86400)) {
        $error = 'Too many sign-up attempts. Please wait a little while and try again.';
    } elseif ($old['first_name'] === '' || $old['last_name'] === '' || $old['email'] === '') {
        $error = 'Name and email are required.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            $email = strtolower($old['email']);
            // customers table (Farm OS Phase 0, C-02; includes/customer_auth.php)
            require_once __DIR__ . '/../includes/customer_auth.php';
            if (bcrf_customer_email_taken($pdo, $email)) {
                $error = 'An account with that email already exists. Try logging in.';
            } else {
                $newId = bcrf_customer_create($pdo, $old['first_name'], $old['last_name'], $email,
                    ($old['phone_number'] !== '' ? $old['phone_number'] : null), password_hash($password, PASSWORD_DEFAULT));
                $user = ['id' => $newId, 'role' => 'user', 'email' => $email,
                         'first_name' => $old['first_name'], 'last_name' => $old['last_name']];
                auth_login_user($user);
                @evf_issue($pdo, (int)$user['id'], $email, $old['first_name']);
                require_once __DIR__ . '/../includes/ga.php';
                bcrf_ga_flash('sign_up', 'account');
                ob_end_clean();
                header('Location: ' . $next);
                exit;
            }
        } catch (Throwable $e) {
            error_log('[Account register] ' . $e->getMessage());
            $error = 'A server error occurred. Please try again.';
        }
    }
}

ob_end_clean();

$pageTitle       = 'Create an Account | Bat Cave Road Farm';
$metaDesc        = 'Create a Bat Cave Road Farm account to rate and comment on recipes.';
$canonicalUrl    = (bcrf_farm_url() . '/account/register.php');
$hideBreadcrumbs = true;
require_once '../includes/head.php';
require_once '../includes/header.php';
?>
<div class="content-wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <h1>Join the Farm</h1>
      <p class="auth-sub">Create an account to rate and comment on recipes.</p>

      <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

      <form method="POST" action="/account/register.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">
        <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="auth-row">
          <div class="auth-field">
            <label for="reg-first">First name</label>
            <input type="text" id="reg-first" name="first_name" autocomplete="given-name" required maxlength="50" value="<?= htmlspecialchars($old['first_name'], ENT_QUOTES) ?>">
          </div>
          <div class="auth-field">
            <label for="reg-last">Last name</label>
            <input type="text" id="reg-last" name="last_name" autocomplete="family-name" required maxlength="50" value="<?= htmlspecialchars($old['last_name'], ENT_QUOTES) ?>">
          </div>
        </div>
        <div class="auth-field">
          <label for="reg-email">Email</label>
          <input type="email" id="reg-email" name="email" autocomplete="email" required maxlength="100" value="<?= htmlspecialchars($old['email'], ENT_QUOTES) ?>">
        </div>
        <div class="auth-field">
          <label for="reg-phone">Phone <span class="auth-hint">(optional)</span></label>
          <input type="tel" id="reg-phone" name="phone_number" autocomplete="tel" maxlength="20" value="<?= htmlspecialchars($old['phone_number'], ENT_QUOTES) ?>">
        </div>
        <div class="auth-field">
          <label for="reg-pass">Password</label>
          <input type="password" id="reg-pass" name="password" autocomplete="new-password" required minlength="8">
          <p class="auth-hint">At least 8 characters.</p>
        </div>
        <div class="auth-field">
          <label for="reg-pass2">Confirm password</label>
          <input type="password" id="reg-pass2" name="password_confirm" autocomplete="new-password" required minlength="8">
        </div>
        <button type="submit" class="auth-btn">Create Account</button>
      </form>

      <p class="auth-alt">Already have an account? <a href="/account/login.php<?= $next !== '/' ? '?next=' . urlencode($next) : '' ?>">Log in</a></p>
    </div>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
