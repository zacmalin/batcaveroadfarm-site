<?php
// account/welcome.php
// New-hire "set your password" page (Jobs pack P6). The link comes from the
// Hired welcome email: /account/welcome.php?token=<64 hex>. Tokens live in
// password_resets (purpose 'invite'), stored as a SHA-256 hash, single-use,
// valid 7 days. Setting the password turns an invited account active.
ob_start();
require_once '../config.php';
require_once '../includes/auth.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');   // keep the token out of any outgoing Referer
header('X-Robots-Tag: noindex, nofollow');

$error = '';
$done  = false;

$token = (string)($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
$token = preg_replace('/[^a-f0-9]/', '', strtolower($token));

$invite = null;
if (strlen($token) === 64) {
    try {
        $st = $pdo->prepare("SELECT pr.id, pr.user_id, pr.expires_at, u.first_name, u.email, u.account_status
                               FROM password_resets pr JOIN users u ON u.id = pr.user_id
                              WHERE pr.token_hash = ? AND pr.purpose = 'invite' AND pr.used_at IS NULL AND pr.expires_at > NOW()
                              LIMIT 1");
        $st->execute([hash('sha256', $token)]);
        $invite = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($invite && $invite['account_status'] === 'disabled') $invite = null;
    } catch (Throwable $e) {
        error_log('[welcome lookup] ' . $e->getMessage());
        $error = 'A server error occurred. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $pw   = (string)($_POST['password'] ?? '');
    $conf = (string)($_POST['password_confirm'] ?? '');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (honeypot_tripped()) {
        $error = 'Something went wrong. Please try again.';
    } elseif (throttle_hit('welcome', 8, 900)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif (!$invite) {
        $error = 'This link is invalid or has expired.';
    } elseif (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (strlen($pw) > 200) {
        $error = 'That password is too long.';
    } elseif ($pw !== trim($pw)) {
        $error = 'Your password can\'t start or end with a space.';
    } elseif (strcasecmp($pw, (string)$invite['email']) === 0) {
        $error = 'Please don\'t use your email address as your password.';
    } elseif ($pw !== $conf) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            $pdo->beginTransaction();
            // Burn the link first; if someone else used it a moment ago, stop.
            $burn = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL");
            $burn->execute([(int)$invite['id']]);
            if ($burn->rowCount() !== 1) throw new RuntimeException('used');
            $pdo->prepare("UPDATE users SET password = ?, account_status = IF(account_status = 'invited', 'active', account_status),
                                  email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?")
                ->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$invite['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([(int)$invite['user_id']]);
            $pdo->commit();
            $done = true;
            if (is_file(__DIR__ . '/../admin/includes/audit.php')) {
                require_once __DIR__ . '/../admin/includes/audit.php';
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'users', (int)$invite['user_id'], 'Set password from welcome link', (string)$invite['first_name']);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getMessage() === 'used') { $invite = null; $error = 'This link was already used.'; }
            else { error_log('[welcome commit] ' . $e->getMessage()); $error = 'A server error occurred. Please try again.'; }
        }
    }
}

ob_end_clean();

$pageTitle       = 'Welcome | Bat Cave Road Farm';
$metaDesc        = 'Set your password for your Bat Cave Road Farm staff account.';
$canonicalUrl    = 'https://batcaveroadfarm.com/account/welcome.php';
$hideBreadcrumbs = true;
require_once '../includes/head.php';
require_once '../includes/header.php';

$showForm = ($invite !== null && !$done);
$first    = $invite ? trim((string)$invite['first_name']) : '';
?>
<div class="content-wrap">
  <div class="auth-wrap">
    <div class="auth-card">
      <?php if ($done): ?>
        <h1>You're All Set</h1>
        <p class="auth-sub">Your password is saved. Use your email and new password to sign in and clock in.</p>
        <p class="auth-alt"><a href="/admin/login.php">Go to staff log in</a></p>
      <?php elseif (!$showForm): ?>
        <h1>Link Expired</h1>
        <p class="auth-sub"><?= $error !== '' ? htmlspecialchars($error) : 'This link is invalid or has expired.' ?> Ask Zac or Casey to send you a new one, or use <a href="/account/forgot.php">Forgot password</a> with the email you applied with.</p>
      <?php else: ?>
        <h1>Welcome<?= $first !== '' ? ', ' . htmlspecialchars($first) : '' ?></h1>
        <p class="auth-sub">Choose a password for your Bat Cave Road Farm staff account.</p>

        <?php if ($error !== ''): ?><div class="auth-error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST" action="/account/welcome.php" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
          <div class="auth-field">
            <label for="wel-email">Your login email</label>
            <input type="email" id="wel-email" value="<?= htmlspecialchars((string)$invite['email'], ENT_QUOTES) ?>" autocomplete="username" readonly>
          </div>
          <div class="auth-field">
            <label for="wel-pw">New password</label>
            <input type="password" id="wel-pw" name="password" autocomplete="new-password" minlength="8" required>
            <p class="auth-hint">At least 8 characters.</p>
          </div>
          <div class="auth-field">
            <label for="wel-pw2">Confirm password</label>
            <input type="password" id="wel-pw2" name="password_confirm" autocomplete="new-password" required>
          </div>
          <div class="auth-hp" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <button type="submit" class="auth-btn">Save Password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
