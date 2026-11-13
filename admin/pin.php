<?php
// admin/pin.php — PIN sign-in on a shared device (Farm OS spec 03, Nov 13, 2026; includes/pin_auth.php, migration 0058).
// Only works on a device an Owner set up in Settings › Users & Roles › PIN sign-in (admin/pin_settings.php).
//   GET               tap your name, type your PIN, pick Register or Gate
//   POST              sign in (user_id, pin, to, csrf)
//   GET ?lock=1       end the PIN session (the Lock button; also after 5 minutes without use)
//   GET ?ping=1       JSON: someone is still using the screen (assets/pin-lock.js)
// A PIN session can open the register, gate, waiver signing and gift card printing only.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
require_once __DIR__ . '/../includes/pin_auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$ready  = ($pdo instanceof PDO) && bcrf_pin_ready($pdo);
$device = $ready ? bcrf_pin_device($pdo) : null;

// Someone is still at the screen
if (isset($_GET['ping'])) {
    header('Content-Type: application/json');
    $p = bcrf_pin_session();
    $live = $p && $device && $device['id'] === (int)$p['device'] && time() - (int)$p['last'] <= BCRF_PIN_IDLE;
    if ($live) $_SESSION['pin']['last'] = time();
    echo json_encode(['ok' => (bool)$live]);
    exit;
}

$msg = mb_substr(trim((string)($_GET['m'] ?? '')), 0, 200);
$bad = false;
if (isset($_GET['lock'])) {
    if (bcrf_pin_session()) {
        $who = (string)($_SESSION['user_name'] ?? '');
        bcrf_pin_session_end();
        $msg = isset($_GET['idle']) ? 'Locked after 5 minutes without use.' : ($who !== '' ? 'Locked. Bye, ' . $who . '.' : 'Locked.');
    }
}

if (empty($_SESSION['pin_csrf'])) $_SESSION['pin_csrf'] = bin2hex(random_bytes(32));
$people = $device ? bcrf_pin_people($pdo) : [];
$sel = 0;
$to = ($_POST['to'] ?? $_GET['to'] ?? '') === 'gate' ? 'gate' : 'register';

if ($device && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $sel = (int)($_POST['user_id'] ?? 0);
    if (!hash_equals((string)$_SESSION['pin_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $msg = 'The page was open too long. Try again.'; $bad = true;
    } elseif (!$sel || !in_array($sel, array_column($people, 'id'), true)) {
        $msg = 'Tap your name first.'; $bad = true;
    } else {
        $r = bcrf_pin_check($pdo, $sel, (string)($_POST['pin'] ?? ''));
        if ($r['ok']) {
            bcrf_pin_session_start($r['user'], $device);
            bcrf_pin_audit($pdo, 'pin_sign_in', $r['user'], 'Signed in by PIN on ' . $device['name']);
            header('Location: /admin/' . ($to === 'gate' ? 'gate.php' : 'register.php'));
            exit;
        }
        $msg = $r['message']; $bad = true;
    }
}
$farm = function_exists('bcrf_farm_name') ? bcrf_farm_name() : 'Farm OS';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'">
<meta name="robots" content="noindex">
<title>Sign in — <?= $h($farm) ?></title>
<link rel="stylesheet" href="assets/register.css?v=8">
<link rel="stylesheet" href="assets/pin.css?v=<?= bcrf_asset_v('/admin/assets/pin.css') ?>">
</head>
<body>
<header class="top">
  <b><?= $h($farm) ?></b>
  <?php if ($device): ?><span class="who"><?= $h($device['name']) ?></span><?php endif; ?>
</header>
<main class="pinpage">
<?php if ($msg !== ''): ?>
  <p class="pin-msg<?= $bad ? ' bad' : '' ?>" role="<?= $bad ? 'alert' : 'status' ?>"><?= $h($msg) ?></p>
<?php endif; ?>

<?php if (!$ready): ?>
  <h1>PIN sign-in isn't set up yet</h1>
  <p>An Owner runs migration 0058 in Settings › Database first.</p>
  <p><a class="btn primary" href="/admin/login.php">Sign in with a password</a></p>
<?php elseif (!$device): ?>
  <h1>This device isn't set up for PIN sign-in</h1>
  <p>An Owner signs in on this device with their password and opens Settings › Users &amp; Roles › PIN sign-in, then taps
     “Set up this device”.</p>
  <p><a class="btn primary" href="/admin/login.php">Sign in with a password</a></p>
<?php elseif (!$people): ?>
  <h1>Nobody has a PIN yet</h1>
  <p>Each person signs in with their password and sets a PIN in Settings › Users &amp; Roles › PIN sign-in.</p>
  <p><a class="btn primary" href="/admin/login.php">Sign in with a password</a></p>
<?php else: ?>
  <form method="post" action="/admin/pin.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['pin_csrf']) ?>">
    <fieldset class="pin-people">
      <legend>Who's working?</legend>
<?php foreach ($people as $p): ?>
      <label class="pin-person"><input type="radio" name="user_id" value="<?= (int)$p['id'] ?>"<?= $sel === $p['id'] || count($people) === 1 ? ' checked' : '' ?> required><span><?= $h($p['name']) ?></span></label>
<?php endforeach; ?>
    </fieldset>
    <label class="pin-entry">Your PIN
      <input class="mpin-pin" type="password" name="pin" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="8" required autocomplete="off">
    </label>
    <div class="pin-go">
      <button class="btn big<?= $to === 'register' ? ' primary' : '' ?>" name="to" value="register">Open the register</button>
      <button class="btn big<?= $to === 'gate' ? ' primary' : '' ?>" name="to" value="gate">Open the gate</button>
    </div>
  </form>
  <p class="pin-foot">Locks after 5 minutes without use. Five wrong PINs lock that PIN for 15 minutes.
     For anything else, <a href="/admin/login.php">sign in with a password</a>.</p>
<?php endif; ?>
</main>
</body>
</html>
