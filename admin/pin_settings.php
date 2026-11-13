<?php
// admin/pin_settings.php — Settings › Users & Roles › PIN sign-in (Farm OS spec 03, Nov 13, 2026; includes/pin_auth.php).
//   Everyone who can use the register or gate: set, change or remove their own PIN (asks for their password).
//   Owners: set up this device for PIN sign-in, stop a device, see who has a PIN, clear or unlock someone's PIN.
// Not reachable from a PIN session (check_admin() keeps those to the register, gate and waivers).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/pin_auth.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$uid  = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['user_role'] ?? '');
$isOwner = $role === 'administrator';
$canPin  = in_array($role, bcrf_pin_roles(), true);
$ready = bcrf_pin_ready($pdo);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$back = function (string $msg, bool $bad = false) { $_SESSION['pins_flash'] = [$msg, $bad]; header('Location: /admin/pin_settings.php'); exit; };
$local = function (?string $utc): string {
    if (!$utc) return '';
    try { return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, g:i A'); }
    catch (Throwable $e) { return ''; }
};
$me = null;
if ($ready && $uid) {
    $st = $pdo->prepare("SELECT id, first_name, last_name, email, password, role, pin_hash IS NOT NULL AS has_pin, pin_set_at FROM users WHERE id = ?");
    $st->execute([$uid]);
    $me = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($ready && $me && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) $back('The page was open too long. Try again.', true);
    $do = (string)($_POST['do'] ?? '');
    try {
        if ($do === 'set_pin' || $do === 'clear_mine') {
            if (!$canPin) $back('PIN sign-in is for people who use the register or the gate.', true);
            if (!password_verify((string)($_POST['password'] ?? ''), (string)$me['password'])) $back('That password is not right.', true);
            if ($do === 'clear_mine') {
                bcrf_pin_clear($pdo, $uid);
                bcrf_pin_audit($pdo, 'pin_off', $me, 'Removed own PIN');
                $back('Your PIN is removed.');
            }
            $pin = (string)($_POST['pin'] ?? '');
            if ($pin !== (string)($_POST['pin2'] ?? '')) $back('The two PINs are different. Type the same PIN twice.', true);
            bcrf_pin_set($pdo, $uid, $pin);
            bcrf_pin_audit($pdo, $me['has_pin'] ? 'pin_changed' : 'pin_on', $me, $me['has_pin'] ? 'Changed own PIN' : 'Set a PIN');
            $back('Your PIN is saved. Use it on any device set up for PIN sign-in' . (in_array($role, bcrf_pin_manager_roles(), true) ? ', and to approve things on the register and gate.' : '.'));
        }
        if (!$isOwner) $back('Only an Owner can do that.', true);
        if ($do === 'device_add') {
            if (bcrf_pin_device($pdo)) $back('This device is already set up.', true);
            $id = bcrf_pin_device_create($pdo, (string)($_POST['name'] ?? ''), $uid);
            bcrf_pin_audit($pdo, 'device_on', $me, 'Set up device #' . $id . ' for PIN sign-in: ' . mb_substr(trim((string)$_POST['name']), 0, 80));
            $back('This device is set up. Open /admin/pin.php on it (bookmark it, or add it to the home screen) for PIN sign-in.');
        }
        if ($do === 'device_revoke') {
            $id = (int)($_POST['id'] ?? 0);
            bcrf_pin_device_revoke($pdo, $id);
            bcrf_pin_audit($pdo, 'device_off', $me, 'Stopped PIN sign-in on device #' . $id);
            $back('That device can no longer use PIN sign-in. Anyone signed in on it by PIN is signed out.');
        }
        if ($do === 'clear_pin' || $do === 'unlock') {
            $who = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id = ?");
            $st->execute([$who]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) $back('No such person.', true);
            if ($do === 'clear_pin') {
                bcrf_pin_clear($pdo, $who);
                bcrf_pin_audit($pdo, 'pin_off', $me, 'Cleared the PIN of ' . trim($u['first_name'] . ' ' . $u['last_name']));
                $back(trim($u['first_name']) . '’s PIN is cleared. They can set a new one after signing in with their password.');
            }
            $pdo->prepare("UPDATE users SET pin_failed = 0, pin_locked_until = NULL WHERE id = ?")->execute([$who]);
            bcrf_pin_audit($pdo, 'pin_unlock', $me, 'Unlocked the PIN of ' . trim($u['first_name'] . ' ' . $u['last_name']));
            $back(trim($u['first_name']) . '’s PIN is unlocked.');
        }
        $back('Unknown request.', true);
    } catch (InvalidArgumentException $e) {
        $back($e->getMessage(), true);
    } catch (Throwable $e) {
        error_log('[pin_settings] ' . $do . ': ' . $e->getMessage());
        $back('That could not be saved. Try again.', true);
    }
}

$flash = $_SESSION['pins_flash'] ?? null; unset($_SESSION['pins_flash']);
$here = $ready ? bcrf_pin_device($pdo) : null;
$devices = $isOwner ? bcrf_pin_devices($pdo) : [];
$holders = [];
if ($isOwner && $ready) {
    $holders = $pdo->query("SELECT id, first_name, last_name, role, pin_set_at, pin_locked_until > UTC_TIMESTAMP() AS locked
                            FROM users WHERE pin_hash IS NOT NULL ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
}

bcrf_shell_open('PIN sign-in', ['width' => 'max-w-3xl', 'css' => ['pin.css']]);
?>
<div class="pins">
<?php if ($flash): ?>
  <p class="pins-flash<?= $flash[1] ? ' bad' : '' ?>" role="<?= $flash[1] ? 'alert' : 'status' ?>"><?= $h($flash[0]) ?></p>
<?php endif; ?>
<?php if (!$ready): ?>
  <div class="pins-card"><h2>Waiting for migration 0058</h2><p>An Owner runs it in Settings › Database. Then PINs and shared devices can be set up here.</p></div>
<?php else: ?>

  <section class="pins-card">
    <h2>Your PIN</h2>
<?php if (!$canPin): ?>
    <p>PIN sign-in is for people who use the register or the gate.</p>
<?php else: ?>
    <p>On the register or gate tablet, tap your name and type this PIN instead of your email and password.
<?php if (in_array($role, bcrf_pin_manager_roles(), true)): ?>
       As <?= $h(bcrf_role_label($role)) ?>, you also use it to approve refunds over the limit, discounts, overrides at the gate and drawer closes for others.
<?php endif; ?>
       4 to 8 digits. Five wrong tries lock it for 15 minutes.</p>
    <p><b><?= $me['has_pin'] ? 'You have a PIN' . ($me['pin_set_at'] ? ' (set ' . $h($local($me['pin_set_at'])) . ')' : '') . '.' : 'You don’t have a PIN yet.' ?></b></p>
    <form method="post" class="pins-row" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <input type="hidden" name="do" value="set_pin">
      <label>New PIN <input class="pins-in" type="password" name="pin" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required autocomplete="new-password"></label>
      <label>Same PIN again <input class="pins-in" type="password" name="pin2" inputmode="numeric" pattern="[0-9]{4,8}" minlength="4" maxlength="8" required autocomplete="new-password"></label>
      <label>Your password <input class="pins-in" type="password" name="password" required autocomplete="current-password"></label>
      <button class="pins-btn"><?= $me['has_pin'] ? 'Change PIN' : 'Save PIN' ?></button>
    </form>
<?php if ($me['has_pin']): ?>
    <form method="post" class="pins-row" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <input type="hidden" name="do" value="clear_mine">
      <label>Your password <input class="pins-in" type="password" name="password" required autocomplete="current-password"></label>
      <button class="pins-btn danger">Remove my PIN</button>
    </form>
<?php endif; ?>
<?php endif; ?>
  </section>

<?php if ($isOwner): ?>
  <section class="pins-card">
    <h2>Shared devices</h2>
    <p>PIN sign-in only works on a device set up here. A PIN session can use the register, gate and waivers, nothing else,
       and locks after 5 minutes without use. Set up the tablet by opening this page on it.</p>
<?php if ($here): ?>
    <p><b>This device is set up as “<?= $h($here['name']) ?>”.</b> PIN sign-in: <a href="/admin/pin.php">/admin/pin.php</a></p>
<?php else: ?>
    <form method="post" class="pins-row">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <input type="hidden" name="do" value="device_add">
      <label>Name for this device <input class="pins-in" type="text" name="name" maxlength="80" required placeholder="Gate tablet"></label>
      <button class="pins-btn">Set up this device</button>
    </form>
<?php endif; ?>
<?php if ($devices): ?>
    <ul class="pins-list">
<?php foreach ($devices as $d): ?>
      <li>
        <span><b><?= $h($d['name']) ?></b><?php if ($d['revoked_at']): ?><span class="pins-tag off">Stopped</span><?php elseif ($here && $here['id'] === (int)$d['id']): ?><span class="pins-tag">This device</span><?php endif; ?><br>
          <span class="pins-sub">Set up <?= $h($local($d['created_at'])) ?><?= $d['by_name'] ? ' by ' . $h($d['by_name']) : '' ?><?= $d['last_seen_at'] && !$d['revoked_at'] ? ' · last used ' . $h($local($d['last_seen_at'])) : '' ?></span></span>
<?php if (!$d['revoked_at']): ?>
        <form method="post" class="pins-inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="device_revoke"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="pins-btn danger">Stop PIN sign-in</button></form>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>

  <section class="pins-card">
    <h2>People with a PIN</h2>
<?php if (!$holders): ?>
    <p>Nobody yet. Each person sets their own PIN on this page after signing in with their password.</p>
<?php else: ?>
    <ul class="pins-list">
<?php foreach ($holders as $p): ?>
      <li>
        <span><b><?= $h(trim($p['first_name'] . ' ' . $p['last_name'])) ?></b> <span class="pins-sub"><?= $h(bcrf_role_label($p['role'])) ?></span><?php if ((int)$p['locked']): ?><span class="pins-tag bad">Locked</span><?php endif; ?>
          <?php if (!in_array($p['role'], bcrf_pin_roles(), true)): ?><span class="pins-tag off">Role can’t use PIN</span><?php endif; ?></span>
        <span>
<?php if ((int)$p['locked']): ?>
          <form method="post" class="pins-inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="unlock"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="pins-btn light">Unlock</button></form>
<?php endif; ?>
          <form method="post" class="pins-inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="clear_pin"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="pins-btn danger">Clear PIN</button></form>
        </span>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
<?php endif; ?>
<?php endif; ?>
</div>
<?php
bcrf_shell_close();
