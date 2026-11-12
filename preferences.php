<?php
// preferences.php — email preferences from the link at the bottom of our optional emails (Farm OS Phase 3, Nov 12, 2026;
// includes/email_prefs.php, migration 0037).
//   GET  ?t=<token>                 the address's settings (reminders, newsletter and topics)
//   POST act=save | all_off, t      saves, then back to the page
// The token is random and stored per address, so the URL never carries the email. Having the link (it was emailed to
// that inbox) is proof enough to turn the newsletter on. Without a valid token the page only says where to find one,
// so it can't be used to look up or change someone else's settings.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/includes/farm.php';
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/admin/includes/audit.php';
require_once __DIR__ . '/includes/email_prefs.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$row = isset($pdo) ? bcrf_email_prefs_by_token($pdo, $token) : null;
$error = '';
if ($row && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            if (($_POST['act'] ?? '') === 'all_off') {
                bcrf_email_prefs_all_off($pdo, $row['email'], 'link');
                $_SESSION['prefs_flash'] = 'Done. You\'ll only get emails about things you book or buy.';
            } else {
                bcrf_email_prefs_save($pdo, $row['email'], bcrf_email_prefs_from_post($_POST), 'link', null, true);
                $_SESSION['prefs_flash'] = 'Saved.';
            }
            ob_end_clean(); header('Location: /preferences.php?t=' . $row['token']); exit;
        } catch (Throwable $e) {
            error_log('[preferences] save: ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}
$flash = (string)($_SESSION['prefs_flash'] ?? ''); unset($_SESSION['prefs_flash']);
$prefs = $row ? bcrf_email_prefs_get($pdo, $row['email']) : null;
ob_end_clean();

$pageTitle       = 'Email preferences | ' . bcrf_farm_name();
$metaDesc        = 'Choose which emails you get from the farm.';
$hideBreadcrumbs = true;
$pageCSS         = [bcrf_farm_url() . '/css/min.portal.css'];
require_once __DIR__ . '/includes/head.php';
require_once __DIR__ . '/includes/header.php';
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
?>
<div class="content-wrap">
  <div class="portal-wrap">
    <h1 class="portal-title">Email preferences</h1>
    <?php if (!$row): ?>
      <section class="portal-card">
        <h2>This link doesn't work</h2>
        <p>Use the "Change your email preferences" link at the bottom of any of our emails, or <a href="/account/">sign in to My farm</a> and change them there.</p>
        <p class="portal-muted">Just want to stop the newsletter? <a href="/unsubscribe.php">Unsubscribe here</a>.</p>
      </section>
    <?php else: ?>
      <p class="portal-sub">For <strong><?= $h($row['email']) ?></strong></p>
      <?php if ($flash !== ''): ?><p class="portal-flash" role="status"><?= $h($flash) ?></p><?php endif; ?>
      <?php if ($error !== ''): ?><p class="portal-flash portal-flash-err" role="alert"><?= $h($error) ?></p><?php endif; ?>
      <section class="portal-card">
        <form method="POST" action="/preferences.php" class="portal-form">
          <?= csrf_field() ?>
          <input type="hidden" name="t" value="<?= $h($row['token']) ?>">
          <input type="hidden" name="act" value="save">
          <?= bcrf_email_prefs_fields($prefs, true) ?>
          <button type="submit" class="auth-btn">Save</button>
        </form>
        <form method="POST" action="/preferences.php" class="portal-news">
          <?= csrf_field() ?>
          <input type="hidden" name="t" value="<?= $h($row['token']) ?>">
          <input type="hidden" name="act" value="all_off">
          <button type="submit" class="portal-btn-alt">Stop all of these</button>
        </form>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
