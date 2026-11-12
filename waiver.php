<?php
// waiver.php — sign the farm's waiver online (Farm OS Phase 3, Nov 12, 2026; includes/waivers.php, migration 0039).
//   GET  [?email=&name=]   the waiver in effect and the form (prefilled when a link carries them)
//   POST                   one adult signs for the minors in their care: name, email, phone, children, 18+, agree,
//                          typed signature. Saves it, emails the signer a copy, then shows "Signed".
// Nothing can be signed until an administrator publishes a reviewed version (admin/waivers.php); until then the page
// says waivers aren't open yet. The gate device signs with a finger signature on admin/waiver_sign.php instead.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/includes/farm.php';
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/admin/includes/audit.php';
require_once __DIR__ . '/includes/booking.php';
require_once __DIR__ . '/includes/waivers.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$ready = isset($pdo) && bcrf_waivers_ready($pdo);
$t = $ready ? bcrf_waiver_current($pdo) : null;
$error = '';
$in = ['name' => mb_substr((string)($_GET['name'] ?? ''), 0, 120), 'email' => mb_substr((string)($_GET['email'] ?? ''), 0, 190), 'phone' => '', 'minors' => '', 'typed' => ''];
if ($t && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = array_merge($in, array_map(fn($v) => is_string($v) ? $v : '', array_intersect_key($_POST, $in)));
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (bcrf_rl_hit('waiver_ip', bcrf_rl_ip(), 15, 3600)) {
        $error = 'Too many signatures from here. Please try again later, or sign at the gate.';
    } else {
        try {
            $id = bcrf_waiver_sign($pdo, $_POST, 'online');
            $w = bcrf_waiver_get($pdo, $id);
            try { [$subj, $inner] = bcrf_waiver_copy_email($w); _booking_mail($w['signer_email'], $subj, $inner); }
            catch (Throwable $e) { error_log('[waiver] copy email: ' . $e->getMessage()); }
            $_SESSION['waiver_done'] = ['name' => $w['signer_name'], 'email' => $w['signer_email'], 'until' => $w['expires_at'], 'kids' => count($w['minors'])];
            ob_end_clean(); header('Location: /waiver.php?signed=1'); exit;
        } catch (BcrfWaiverError $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[waiver] sign: ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}
$done = isset($_GET['signed']) ? ($_SESSION['waiver_done'] ?? null) : null;
unset($_SESSION['waiver_done']);
ob_end_clean();

$pageTitle       = 'Sign the waiver | ' . bcrf_farm_name();
$metaDesc        = 'Sign the farm\'s waiver before your visit.';
$hideBreadcrumbs = true;
$pageCSS         = [bcrf_farm_url() . '/css/min.portal.css'];
require_once __DIR__ . '/includes/head.php';
require_once __DIR__ . '/includes/header.php';
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
?>
<div class="content-wrap">
  <div class="portal-wrap">
    <h1 class="portal-title">Waiver</h1>
    <?php if ($done): ?>
      <section class="portal-card" role="status">
        <h2>Signed. Thank you!</h2>
        <p><?= $h($done['name']) ?><?= $done['kids'] ? ' and ' . (int)$done['kids'] . ' child' . ($done['kids'] === 1 ? '' : 'ren') : '' ?> are covered until <?= $h(date('F j, Y', strtotime($done['until'] . ' UTC'))) ?>.</p>
        <p class="portal-muted">A copy is on its way to <?= $h($done['email']) ?>. At the gate, just give us that email or your name.</p>
      </section>
    <?php elseif (!$t): ?>
      <section class="portal-card">
        <h2>Waivers aren't open yet</h2>
        <p>You don't need to sign anything before your visit right now. If that changes, we'll let you know.</p>
      </section>
    <?php else: ?>
      <p class="portal-sub">One adult signs for the whole group, listing the children with them. It's good for <?= bcrf_waiver_valid_months($pdo) ?> months.</p>
      <?php if ($error !== ''): ?><p class="portal-flash portal-flash-err" role="alert"><?= $h($error) ?></p><?php endif; ?>
      <section class="portal-card">
        <h2><?= $h($t['title']) ?></h2>
        <div class="waiver-text" tabindex="0" aria-label="Waiver wording">
          <?php foreach (bcrf_waiver_paragraphs($t['body']) as $p): ?><p><?= nl2br($h($p)) ?></p><?php endforeach; ?>
          <p class="portal-muted">Version <?= (int)$t['version'] ?>, in effect from <?= $h(date('F j, Y', strtotime($t['effective_at'] . ' UTC'))) ?>.</p>
        </div>
      </section>
      <section class="portal-card">
        <form method="POST" action="/waiver.php" class="portal-form" autocomplete="on">
          <?= csrf_field() ?>
          <div class="auth-field"><label for="w-name">Your full name</label><input id="w-name" name="name" maxlength="120" required autocomplete="name" value="<?= $h($in['name']) ?>"></div>
          <div class="auth-field"><label for="w-email">Email</label><input id="w-email" name="email" type="email" maxlength="190" required autocomplete="email" value="<?= $h($in['email']) ?>"></div>
          <div class="auth-field"><label for="w-phone">Phone (optional)</label><input id="w-phone" name="phone" type="tel" maxlength="30" autocomplete="tel" value="<?= $h($in['phone']) ?>"></div>
          <div class="auth-field"><label for="w-minors">Children with you (first name and age, one per line)</label><textarea id="w-minors" name="minors" rows="4" maxlength="1200" placeholder="Ava, 7&#10;Ben, 4"><?= $h($in['minors']) ?></textarea></div>
          <label class="pref-row"><input type="checkbox" name="adult" value="1" required><span>I am 18 or older, and I'm the parent or guardian of the children listed, or have their permission to sign for them.</span></label>
          <label class="pref-row"><input type="checkbox" name="agree" value="1" required><span>I have read the waiver above and agree to it.</span></label>
          <div class="auth-field"><label for="w-typed">Signature: type your full name again</label><input id="w-typed" name="typed" maxlength="120" required autocomplete="off" value="<?= $h($in['typed']) ?>"></div>
          <button type="submit" class="auth-btn">Sign the waiver</button>
        </form>
      </section>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
