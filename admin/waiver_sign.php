<?php
// admin/waiver_sign.php — sign the waiver on the gate device (Farm OS Phase 3, Nov 12, 2026; includes/waivers.php).
// Staff open it from the gate ("Sign now") and hand the device to the adult, who reads the waiver, lists the children
// with them and signs with a finger. Recorded as via = gate with the staff member who was signed in.
//   GET  [?email=&name=]   the form, prefilled from the ticket, pass, booking or party
//   POST                   sign; then "Signed" with a button back to the gate
// Strict CSP: behaviour in assets/waiver-pad.js, looks in assets/register.css + assets/waiver.css.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/../includes/pin_auth.php';   // a PIN session may open this page (Nov 13, 2026)
bcrf_pin_page_check();
require_once __DIR__ . '/includes/permission_checks.php';
if (($_SESSION['user_role'] ?? '') === 'Media') { http_response_code(403); exit('Media accounts cannot use the gate.'); }
require_permission([['view', 'pos'], ['view', 'passes']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/waivers.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$t = bcrf_waivers_ready($pdo) ? bcrf_waiver_current($pdo) : null;
$in = ['name' => mb_substr((string)($_GET['name'] ?? ''), 0, 120), 'email' => mb_substr((string)($_GET['email'] ?? ''), 0, 190), 'phone' => '', 'minors' => '', 'typed' => ''];
$error = ''; $done = null;
if ($t && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = array_merge($in, array_map(fn($v) => is_string($v) ? $v : '', array_intersect_key($_POST, $in)));
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Reload and try again.';
    } else {
        try {
            $id = bcrf_waiver_sign($pdo, $_POST, 'gate', $uid);
            $done = bcrf_waiver_get($pdo, $id);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'waivers', $id, 'Waiver signed at the gate: ' . $done['signer_name']);
            try { [$subj, $inner] = bcrf_waiver_copy_email($done); _booking_mail($done['signer_email'], $subj, $inner); }
            catch (Throwable $e) { error_log('[waiver_sign] copy email: ' . $e->getMessage()); }
        } catch (BcrfWaiverError $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[waiver_sign] ' . $e->getMessage());
            $error = 'Could not save. Try again.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'">
<meta name="robots" content="noindex">
<title>Sign the waiver — <?= $h(bcrf_farm_name()) ?></title>
<link rel="stylesheet" href="assets/register.css?v=9">
<link rel="stylesheet" href="assets/waiver.css?v=1">
<?= bcrf_pin_head_tags() ?>
</head>
<body class="gate">
<header class="top">
  <a class="back drawerbtn" href="gate.php"><span aria-hidden="true">←</span>&nbsp;Gate</a>
</header>
<main class="gatewrap">
<?php if ($done): ?>
  <section class="gcard waiver-done" role="status">
    <h1>Signed. Thank you!</h1>
    <p><?= $h($done['signer_name']) ?><?= $done['minors'] ? ' and ' . count($done['minors']) . ' child' . (count($done['minors']) === 1 ? '' : 'ren') : '' ?>, good until <?= $h(date('M j, Y', strtotime($done['expires_at'] . ' UTC'))) ?>.</p>
    <p class="muted">Please hand the device back to the staff member.</p>
    <a class="btn primary big" href="gate.php">Back to the gate</a>
  </section>
<?php elseif (!$t): ?>
  <p class="banner err">There's no published waiver yet. An administrator publishes the reviewed wording in Customers › Waivers.</p>
<?php else: ?>
  <h1 class="waiver-h"><?= $h($t['title']) ?></h1>
  <div class="waiver-text" tabindex="0" aria-label="Waiver wording">
    <?php foreach (bcrf_waiver_paragraphs($t['body']) as $p): ?><p><?= nl2br($h($p)) ?></p><?php endforeach; ?>
    <p class="muted">Version <?= (int)$t['version'] ?></p>
  </div>
  <?php if ($error !== ''): ?><p class="banner err" role="alert"><?= $h($error) ?></p><?php endif; ?>
  <form method="POST" action="waiver_sign.php" class="waiver-form" id="waiverForm" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="signature" id="sigData" value="">
    <label>Your full name<input name="name" maxlength="120" required value="<?= $h($in['name']) ?>"></label>
    <label>Email (we send you a copy)<input name="email" type="email" maxlength="190" required value="<?= $h($in['email']) ?>"></label>
    <label>Phone (optional)<input name="phone" type="tel" maxlength="30" value="<?= $h($in['phone']) ?>"></label>
    <label>Children with you (first name and age, one per line)<textarea name="minors" rows="4" maxlength="1200" placeholder="Ava, 7&#10;Ben, 4"><?= $h($in['minors']) ?></textarea></label>
    <label class="waiver-check"><input type="checkbox" name="adult" value="1" required> I am 18 or older, and I'm the parent or guardian of the children listed, or have their permission to sign for them.</label>
    <label class="waiver-check"><input type="checkbox" name="agree" value="1" required> I have read the waiver above and agree to it.</label>
    <label>Type your full name again<input name="typed" maxlength="120" required value="<?= $h($in['typed']) ?>"></label>
    <div class="waiver-padwrap">
      <span>Sign with your finger</span>
      <canvas id="sigPad" class="waiver-pad" width="640" height="200" aria-label="Signature box"></canvas>
      <button type="button" class="btn sm" id="sigClear">Clear</button>
    </div>
    <p class="err" id="sigMsg" hidden>Sign with your finger in the box.</p>
    <button class="btn primary big" type="submit">Sign the waiver</button>
  </form>
<?php endif; ?>
</main>
<script src="assets/waiver-pad.js?v=1"></script>
</body>
</html>
