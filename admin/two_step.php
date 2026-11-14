<?php // admin/two_step.php
/*
 * Two-step sign-in (Farm OS Phase 0, Nov 2026). See includes/staff_auth.php for how it works.
 *   - After a correct password: enter the 6-digit code from the authenticator app (or a backup code),
 *     or set two-step up first when it's required.
 *   - Signed in: turn it on or off, get new backup codes; administrators also see the team's status,
 *     can reset someone's code (lost phone), and switch on "Require for administrators".
 */
ob_start();
require_once '../config.php';
require_once __DIR__ . '/../includes/auth.php';         // csrf_token(), csrf_verify()
require_once __DIR__ . '/../includes/rate_limit.php';   // bcrf_rl_*()
require_once __DIR__ . '/../includes/staff_auth.php';
require_once __DIR__ . '/includes/audit.php';           // erp_audit_security(), bcrf_audit_event()
if (!function_exists('bcrf_farm_name')) require_once __DIR__ . '/../includes/farm.php';
require_once __DIR__ . '/../includes/roles.php';         // role display names (Nov 13, 2026)

const BCRF_MFA_TRIES = 5;      // wrong codes per account …
const BCRF_MFA_WIN   = 900;    // … in 15 minutes

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$go = function (string $to) { ob_end_clean(); header('Location: ' . $to); exit; };

if (!bcrf_mfa_ready($pdo)) {
    $go(!empty($_SESSION['user_id']) ? '/admin/admin.php' : '/admin/login.php');   // migration 0005 not run yet
}

// Who is here: a pending sign-in, or a signed-in staff member.
$pending = bcrf_staff_pending();
$signedIn = !$pending && in_array((string)($_SESSION['user_role'] ?? ''), bcrf_staff_roles(), true) && !empty($_SESSION['user_id']);
if ($signedIn && empty($_SESSION['mfa_ok'])) bcrf_staff_mfa_gate();   // moves the session to pending and comes back here
if (!$pending && !$signedIn) $go('/admin/login.php');

$uid = $pending ? (int)$pending['uid'] : (int)$_SESSION['user_id'];
$me  = bcrf_mfa_row($pdo, $uid);
if (!$me || ($me['account_status'] ?? 'active') === 'disabled' || !in_array((string)$me['role'], bcrf_staff_roles(), true)) {
    $_SESSION = [];
    $go('/admin/login.php');
}
$isAdmin  = $me['role'] === 'administrator';
$enabled  = !empty($me['totp_enabled_at']);
$required = bcrf_mfa_required_admin($pdo);
$label    = trim($me['first_name'] . ' ' . $me['last_name']) ?: $me['email'];

$error = ''; $notice = '';
$showCodes = $_SESSION['mfa_codes_show'] ?? null; unset($_SESSION['mfa_codes_show']);
$after     = (string)($_SESSION['mfa_after'] ?? ''); unset($_SESSION['mfa_after']);

/** Save a newly confirmed secret + backup codes, and remember the codes to show once. */
$enable = function (string $secret, int $step) use ($pdo, $me): void {
    [$plain, $hashes] = bcrf_mfa_new_recovery();
    $pdo->prepare("UPDATE users SET totp_secret_enc = ?, totp_enabled_at = UTC_TIMESTAMP(), totp_last_step = ?, mfa_recovery_json = ? WHERE id = ?")
        ->execute([bcrf_mfa_encrypt($secret), $step, json_encode($hashes), $me['id']]);
    $_SESSION['mfa_codes_show'] = $plain;
    unset($_SESSION['mfa_setup_secret']);
    bcrf_mfa_audit($pdo, 'mfa_on', $me, 'Two-step sign-in turned on: ' . $me['email']);
};

/** Check a 6-digit code or a backup code against $me. Updates last step / remaining codes. */
$check = function (string $code) use ($pdo, $me): string {   // 'totp', 'backup' or ''
    $secret = !empty($me['totp_secret_enc']) ? bcrf_mfa_decrypt((string)$me['totp_secret_enc']) : null;
    if ($secret !== null && ($step = bcrf_totp_verify($secret, $code, isset($me['totp_last_step']) ? (int)$me['totp_last_step'] : null)) !== null) {
        $pdo->prepare("UPDATE users SET totp_last_step = ? WHERE id = ?")->execute([$step, $me['id']]);
        return 'totp';
    }
    $left = bcrf_mfa_use_recovery($code, json_decode((string)$me['mfa_recovery_json'], true) ?: []);
    if ($left !== null) {
        $pdo->prepare("UPDATE users SET mfa_recovery_json = ? WHERE id = ?")->execute([json_encode($left), $me['id']]);
        bcrf_mfa_audit($pdo, 'mfa_backup_used', $me, 'Backup code used (' . count($left) . ' left): ' . $me['email']);
        return 'backup';
    }
    return '';
};

$tooMany = fn() => bcrf_rl_count('mfa', (string)$uid, BCRF_MFA_WIN) >= BCRF_MFA_TRIES;
$failed  = function () use ($uid, $pdo, $me) {
    $n = bcrf_rl_add('mfa', (string)$uid, BCRF_MFA_WIN);
    bcrf_mfa_audit($pdo, 'mfa_failed', $me, 'Wrong two-step code (' . $n . '): ' . $me['email']);
    usleep(random_int(250000, 600000));
    return $n;
};

// ── POST ─────────────────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)($_POST['a'] ?? '');
    $code = (string)($_POST['code'] ?? '');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (in_array($a, ['verify', 'enable', 'regen', 'off'], true) && $tooMany()) {
        $error = 'Too many wrong codes. Wait 15 minutes, then sign in again.';
        if ($pending) { unset($_SESSION['mfa_pending']); $pending = null; }

    } elseif ($a === 'verify' && $pending && $pending['mode'] === 'code') {
        $how = $check($code);
        if ($how !== '') {
            bcrf_rl_clear('mfa', (string)$uid);
            bcrf_staff_session_start($me);
            erp_audit_security($pdo, 'login', (string)$me['email'], (int)$me['id'], (string)$me['first_name']);
            $go(bcrf_staff_safe_dest((string)$pending['dest'], $me['role'] === 'Staff' ? '/admin/admin.php?view=timecard' : '/admin/admin.php'));
        }
        $error = $failed() >= BCRF_MFA_TRIES ? 'Too many wrong codes. Wait 15 minutes, then sign in again.' : 'That code didn\'t match. Check the time on your phone is set automatically, and try the newest code.';

    } elseif ($a === 'enable' && (($pending && $pending['mode'] === 'setup') || ($signedIn && !$enabled))) {
        $secret = (string)($_SESSION['mfa_setup_secret'] ?? '');
        $step = $secret !== '' ? bcrf_totp_verify($secret, $code) : null;
        if ($step === null) {
            $failed();
            $error = 'That code didn\'t match. Scan the QR code again (or type the key), then enter the 6 digits your app shows now.';
        } else {
            try {
                $enable($secret, $step);
                bcrf_rl_clear('mfa', (string)$uid);
                $dest = $pending ? bcrf_staff_safe_dest((string)$pending['dest']) : '/admin/two_step.php';
                if ($pending) {
                    bcrf_staff_session_start($me);
                    erp_audit_security($pdo, 'login', (string)$me['email'], (int)$me['id'], (string)$me['first_name']);
                }
                $_SESSION['mfa_after'] = $dest;
                $go('/admin/two_step.php');
            } catch (Throwable $e) {
                error_log('[two_step] enable: ' . $e->getMessage());
                $error = 'Couldn\'t save it: ' . $e->getMessage();
            }
        }

    } elseif ($signedIn && $a === 'regen' && $enabled) {
        if ($check($code) === '') { $failed(); $error = 'Enter a current code from your app to make new backup codes.'; }
        else {
            [$plain, $hashes] = bcrf_mfa_new_recovery();
            $pdo->prepare("UPDATE users SET mfa_recovery_json = ? WHERE id = ?")->execute([json_encode($hashes), $me['id']]);
            bcrf_mfa_audit($pdo, 'mfa_new_codes', $me, 'New backup codes: ' . $me['email']);
            $_SESSION['mfa_codes_show'] = $plain;
            $go('/admin/two_step.php');
        }

    } elseif ($signedIn && $a === 'off' && $enabled) {
        if ($isAdmin && $required) { $error = 'Two-step sign-in is required for administrators. Turn off "Require" first, or reset your code to set it up on a new phone.'; }
        elseif ($check($code) === '') { $failed(); $error = 'Enter a current code from your app to turn two-step sign-in off.'; }
        else {
            $pdo->prepare("UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL, mfa_recovery_json = NULL WHERE id = ?")->execute([$me['id']]);
            bcrf_mfa_audit($pdo, 'mfa_off', $me, 'Two-step sign-in turned off: ' . $me['email']);
            $_SESSION['sh_notice'] = 'Two-step sign-in is off for your account.';
            $go('/admin/two_step.php');
        }

    } elseif ($signedIn && $isAdmin && ($a === 'require_on' || $a === 'require_off')) {
        if ($a === 'require_on' && !$enabled) { $error = 'Turn it on for your own account first, so you can\'t lock yourself out.'; }
        else {
            $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('mfa_required_admin', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$a === 'require_on' ? '1' : '0']);
            bcrf_mfa_audit($pdo, 'update', $me, 'Require two-step sign-in for administrators: ' . ($a === 'require_on' ? 'on' : 'off'));
            $_SESSION['sh_notice'] = $a === 'require_on' ? 'Required for administrators. Anyone without it sets it up at their next sign-in.' : 'No longer required for administrators.';
            $go('/admin/two_step.php');
        }

    } elseif ($signedIn && $isAdmin && $a === 'reset') {
        $tid = (int)($_POST['uid'] ?? 0);
        $t = $tid && $tid !== (int)$me['id'] ? bcrf_mfa_row($pdo, $tid) : null;
        if (!$t) { $error = 'Pick someone else\'s account to reset.'; }
        else {
            $pdo->prepare("UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL, mfa_recovery_json = NULL WHERE id = ?")->execute([$tid]);
            bcrf_mfa_audit($pdo, 'mfa_reset', $t, 'Two-step sign-in reset by ' . $label . ': ' . $t['email'], (int)$me['id']);
            $_SESSION['sh_notice'] = 'Reset for ' . trim($t['first_name'] . ' ' . $t['last_name']) . '. They set it up again at their next sign-in' . ($t['role'] === 'administrator' && $required ? '.' : ' (if they choose to).');
            $go('/admin/two_step.php');
        }
    } elseif ($a === 'cancel') {
        unset($_SESSION['mfa_pending'], $_SESSION['mfa_setup_secret']);
        $go('/admin/login.php');
    }
}
$notice = (string)($_SESSION['sh_notice'] ?? ''); unset($_SESSION['sh_notice']);

// Setup screen needs a secret (kept in the session until the first code confirms it).
$setupMode = ($pending && $pending['mode'] === 'setup') || ($signedIn && !$enabled);
if ($setupMode && empty($_SESSION['mfa_setup_secret'])) $_SESSION['mfa_setup_secret'] = bcrf_totp_new_secret();
$setupSecret = $setupMode ? (string)$_SESSION['mfa_setup_secret'] : '';
$keyProblem  = $setupMode && bcrf_mfa_key() === '';

$team = [];
if ($signedIn && $isAdmin) {
    $team = $pdo->query("SELECT id, first_name, last_name, email, role, account_status, totp_enabled_at FROM users
                         WHERE role IN ('administrator','moderator','Shopkeep','Media','Staff') ORDER BY role = 'administrator' DESC, first_name")->fetchAll(PDO::FETCH_ASSOC);
}
$csrf = csrf_token();
$local = function (?string $utc): string {
    if (!$utc) return '';
    $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $d->format('M j, Y');
};
ob_end_flush();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-step sign-in · Farm Console</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="/admin/assets/admin-ui.css?v=20261114c">
    <script defer src="/admin/assets/admin-ui.js?v=20261114b"></script>
</head>
<body class="bg-stone-100 min-h-screen p-4 flex items-start sm:items-center justify-center">
<div class="bg-white p-6 sm:p-8 rounded shadow-md max-w-md w-full border border-stone-200 my-6">

<?php if ($error): ?><div class="p-2.5 mb-4 text-sm bg-red-50 text-red-800 border border-red-200 rounded"><?= $h($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="p-2.5 mb-4 text-sm bg-green-50 text-green-900 border border-green-200 rounded"><?= $h($notice) ?></div><?php endif; ?>

<?php if ($showCodes): ?>
    <h1 class="text-xl font-serif font-bold text-stone-800 mb-2">Save your backup codes</h1>
    <p class="text-sm text-stone-600 mb-3">Each one works once, instead of a code from your app, if you lose your phone. Save them in your password manager or somewhere safe. They won't be shown again.</p>
    <div class="grid grid-cols-2 gap-2 font-mono text-base bg-stone-50 border border-stone-200 rounded p-3 mb-3" id="codes">
        <?php foreach ($showCodes as $c): ?><div><?= $h($c) ?></div><?php endforeach; ?>
    </div>
    <button type="button" class="w-full mb-3 border border-stone-300 p-2.5 rounded text-sm font-medium"
        onclick="navigator.clipboard&&navigator.clipboard.writeText(<?= $h(json_encode(implode("\n", $showCodes))) ?>).then(()=>{this.textContent='Copied'})">Copy all</button>
    <a href="<?= $h($after !== '' ? $after : '/admin/two_step.php') ?>" class="block text-center w-full bg-stone-950 text-white p-2.5 rounded text-sm font-medium">I've saved them, continue</a>

<?php elseif ($pending && $pending['mode'] === 'code'): ?>
    <h1 class="text-xl font-serif font-bold text-stone-800 mb-1">Enter your code</h1>
    <p class="text-sm text-stone-600 mb-4">Open your authenticator app and enter the 6-digit code for <?= $h(bcrf_farm_name()) ?>.</p>
    <form method="POST" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="verify">
        <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required maxlength="12"
            class="w-full p-3 border border-stone-300 rounded text-2xl tracking-[.3em] text-center font-mono bg-stone-50" placeholder="123456">
        <button class="w-full bg-stone-950 text-white p-2.5 rounded text-sm font-medium">Sign in</button>
    </form>
    <p class="text-xs text-stone-500 mt-4">Lost your phone? Type one of your backup codes in the box instead (like K7QD-M2XF).</p>
    <form method="POST" class="mt-3"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="cancel">
        <button class="text-xs underline text-stone-600">Cancel and go back</button></form>

<?php elseif ($setupMode): ?>
    <h1 class="text-xl font-serif font-bold text-stone-800 mb-1">Set up two-step sign-in</h1>
    <?php if ($pending): ?><p class="text-sm text-stone-600 mb-3">Administrators need a code from an authenticator app to sign in. This takes a minute.</p><?php endif; ?>
    <?php if ($keyProblem): ?>
        <div class="p-2.5 mb-4 text-sm bg-red-50 text-red-800 border border-red-200 rounded">The server can't store the secret safely yet (no key). Add <code>MFA_KEY</code> to bcrf-secrets.php, or make the home folder writable.</div>
    <?php endif; ?>
    <ol class="text-sm text-stone-700 space-y-3 mb-4 list-decimal pl-5">
        <li>Install an authenticator app if you don't have one: Google Authenticator, Microsoft Authenticator, 1Password or Authy.</li>
        <li>In the app, add an account and scan this code. On this phone? Tap <strong>Copy key</strong> and paste it into the app instead.
            <div id="qr" class="my-3 flex justify-center"></div>
            <div class="font-mono text-xs break-all bg-stone-50 border border-stone-200 rounded p-2"><?= $h(trim(chunk_split($setupSecret, 4, ' '))) ?></div>
            <div class="flex gap-2 mt-2">
                <button type="button" class="flex-1 border border-stone-300 p-2 rounded text-xs font-medium" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= $h($setupSecret) ?>').then(()=>{this.textContent='Copied'})">Copy key</button>
                <a class="flex-1 text-center border border-stone-300 p-2 rounded text-xs font-medium" href="<?= $h(bcrf_totp_uri($setupSecret, (string)$me['email'])) ?>">Open in app</a>
            </div>
        </li>
        <li>Enter the 6-digit code the app shows:</li>
    </ol>
    <form method="POST" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="enable">
        <input name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="7"
            class="w-full p-3 border border-stone-300 rounded text-2xl tracking-[.3em] text-center font-mono bg-stone-50" placeholder="123456">
        <button class="w-full bg-stone-950 text-white p-2.5 rounded text-sm font-medium" <?= $keyProblem ? 'disabled' : '' ?>>Turn on</button>
    </form>
    <?php if ($pending): ?>
        <form method="POST" class="mt-3"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="cancel">
            <button class="text-xs underline text-stone-600">Cancel and go back</button></form>
    <?php else: ?>
        <p class="mt-4 text-xs"><a class="underline text-stone-600" href="/admin/admin.php">← Back to the console</a></p>
    <?php endif; ?>
    <script src="/js/qrcode.min.js"></script>
    <script>try { new QRCode(document.getElementById('qr'), { text: <?= json_encode(bcrf_totp_uri($setupSecret, (string)$me['email'])) ?>, width: 200, height: 200 }); } catch (e) {}</script>

<?php else: /* signed in, two-step on */ ?>
    <p class="text-xs mb-3"><a class="underline text-stone-600" href="/admin/admin.php">← Back to the console</a></p>
    <h1 class="text-xl font-serif font-bold text-stone-800 mb-1">Two-step sign-in</h1>
    <p class="text-sm text-stone-600 mb-4">On for <?= $h($label) ?> since <?= $h($local($me['totp_enabled_at'])) ?>. Backup codes left: <?= count(json_decode((string)$me['mfa_recovery_json'], true) ?: []) ?>.</p>
    <form method="POST" class="space-y-2 mb-5">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <label class="block text-xs font-semibold text-stone-600">Current code from your app</label>
        <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" class="w-full p-2.5 border border-stone-300 rounded text-lg text-center font-mono bg-stone-50" placeholder="123456">
        <div class="flex gap-2">
            <button name="a" value="regen" class="flex-1 border border-stone-300 p-2.5 rounded text-sm font-medium">New backup codes</button>
            <button name="a" value="off" class="flex-1 border border-red-300 text-red-800 p-2.5 rounded text-sm font-medium" <?= ($isAdmin && $required) ? 'disabled title="Required for administrators"' : '' ?>>Turn off</button>
        </div>
    </form>
<?php endif; ?>

<?php if ($signedIn && $isAdmin && !$showCodes): ?>
    <hr class="my-5 border-stone-200">
    <h2 class="text-base font-bold text-stone-800 mb-2">Team</h2>
    <form method="POST" class="flex items-center justify-between gap-3 p-3 border border-stone-200 rounded mb-3">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <div class="text-sm"><strong>Require for administrators</strong><br><span class="text-xs text-stone-500"><?= $required ? 'On: administrators can\'t sign in without a code.' : 'Off. Turn on once every administrator has set it up.' ?></span></div>
        <button name="a" value="<?= $required ? 'require_off' : 'require_on' ?>" class="px-3 py-2 rounded text-sm font-bold <?= $required ? 'border border-stone-300' : 'bg-stone-950 text-white' ?>" <?= (!$required && !$enabled) ? 'disabled title="Set it up for yourself first"' : '' ?>><?= $required ? 'Turn off' : 'Turn on' ?></button>
    </form>
    <?php foreach ($team as $t): $on = !empty($t['totp_enabled_at']); ?>
        <div class="flex items-center justify-between gap-2 py-2 border-b border-stone-100 text-sm">
            <div><?= $h(trim($t['first_name'] . ' ' . $t['last_name'])) ?> <span class="text-xs text-stone-500"><?= $h(bcrf_role_label($t['role'])) ?><?= ($t['account_status'] ?? '') === 'disabled' ? ' · off' : '' ?></span><br>
                <span class="text-xs <?= $on ? 'text-green-800' : ($t['role'] === 'administrator' ? 'text-amber-800' : 'text-stone-500') ?>"><?= $on ? 'Two-step on' : 'Not set up' ?></span></div>
            <?php if ($on && (int)$t['id'] !== (int)$me['id']): ?>
                <form method="POST" data-confirm="Reset two-step sign-in for <?= $h(trim($t['first_name'] . ' ' . $t['last_name'])) ?>? Use this when they lose their phone.">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="a" value="reset"><input type="hidden" name="uid" value="<?= (int)$t['id'] ?>">
                    <button class="text-xs border border-stone-300 px-2 py-1 rounded">Reset</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <p class="text-xs text-stone-500 mt-4">Locked out with no phone and no backup codes? In cPanel → File Manager, create an empty file named <code>bcrf-mfa-off.txt</code> in your home folder (next to bcrf-secrets.php). Two-step is skipped while it's there; sign in, reset, then delete the file.</p>
<?php endif; ?>
</div>
</body>
</html>
