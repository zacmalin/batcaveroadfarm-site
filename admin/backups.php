<?php // admin/backups.php
/*
 * Backups status + "Connect Google Drive" (Nov 2026). Administrators only.
 * Connecting uses Google's device-code flow (enter a code at google.com/device), because
 * Bluehost's ModSecurity blocks the normal OAuth redirect back to this site.
 */
require_once '../config.php';
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/../includes/backup_offsite.php';
require_once __DIR__ . '/includes/audit.php';
date_default_timezone_set('America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$flash = $_SESSION['backups_flash'] ?? null; unset($_SESSION['backups_flash']);
$go = function (string $type, string $msg) { $_SESSION['backups_flash'] = [$type, $msg]; header('Location: /admin/backups.php'); exit; };

// ── 1. Sign-in polling (device code flow — see includes/backup_offsite.php) ───
// The page asks this URL every few seconds while you approve on your phone.
$dev = $_SESSION['gdrive_device'] ?? null;
$pollOnce = function () use (&$dev, $pdo): array {
    if (!$dev) return ['none', 'No sign-in in progress.'];
    if (time() > $dev['expires_at']) { unset($_SESSION['gdrive_device']); $dev = null; return ['expired', 'The code expired. Tap Connect again.']; }
    try { $r = bcrf_gdrive_device_poll($dev['device_code']); }
    catch (Throwable $e) { unset($_SESSION['gdrive_device']); $dev = null; return ['error', $e->getMessage()]; }
    if ($r === 'ok') {
        unset($_SESSION['gdrive_device']); $dev = null;
        try { bcrf_gdrive_folder(bcrf_gdrive_access_token()); } catch (Throwable $e) { return ['error', 'Connected, but the folder check failed: ' . $e->getMessage()]; }
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'backups', null, 'Connected Google Drive for off-site backups');
        return ['ok', 'Google Drive connected. The "' . BCRF_GDRIVE_FOLDER . '" folder is ready; the next backup will copy there.'];
    }
    if ($r === 'slow_down') { $_SESSION['gdrive_device']['interval'] = ($dev['interval'] ?? 5) + 5; return ['pending', 'Waiting…']; }
    if ($r === 'denied')  { unset($_SESSION['gdrive_device']); $dev = null; return ['denied', 'Access was declined on the Google screen.']; }
    if ($r === 'expired') { unset($_SESSION['gdrive_device']); $dev = null; return ['expired', 'The code expired. Tap Connect again.']; }
    return ['pending', 'Waiting for you to approve on Google…'];
};
if (isset($_GET['poll'])) {
    [$st, $msg] = $pollOnce();
    if (in_array($st, ['ok', 'denied', 'expired', 'error'], true)) $_SESSION['backups_flash'] = [$st === 'ok' ? 'ok' : 'err', $msg];
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode(['status' => $st, 'message' => $msg]); exit;
}

// ── 2. Buttons ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) $go('err', 'Security token expired — reload and try again.');
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'connect') {
        if (!bcrf_gdrive_configured()) $go('err', 'Add GDRIVE_CLIENT_ID and GDRIVE_CLIENT_SECRET to bcrf-secrets.php first.');
        try { $_SESSION['gdrive_device'] = bcrf_gdrive_device_start(); }
        catch (Throwable $e) { $go('err', $e->getMessage()); }
        header('Location: /admin/backups.php'); exit;
    }
    if ($act === 'check') {
        [$st, $msg] = $pollOnce();
        $go(in_array($st, ['ok'], true) ? 'ok' : ($st === 'pending' ? 'ok' : 'err'), $st === 'pending' ? 'Not approved yet — finish on the Google page, then check again.' : $msg);
    }
    if ($act === 'cancel_connect') { unset($_SESSION['gdrive_device']); $go('ok', 'Sign-in cancelled.'); }
    if ($act === 'test') {
        try { $tok = bcrf_gdrive_access_token(); bcrf_gdrive_folder($tok); $go('ok', 'Connection works — Drive accepted the sign-in and the backup folder is there.'); }
        catch (Throwable $e) { $go('err', $e->getMessage()); }
    }
    if ($act === 'disconnect') {
        $s = bcrf_gdrive_state(); unset($s['refresh_token'], $s['folder_id']);
        try { bcrf_gdrive_save_state($s); } catch (Throwable $e) {}
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'backups', null, 'Disconnected Google Drive');
        $go('ok', 'Disconnected. Backups stay on the server only until you connect again.');
    }
    $go('err', 'Unknown action.');
}

// ── 3. Status ────────────────────────────────────────────────────────────────
$dir = bcrf_backup_dir();
$latest = function (string $pattern) use ($dir) {
    $f = glob($dir . '/' . $pattern) ?: []; rsort($f);
    return $f ? ['name' => basename($f[0]), 'at' => filemtime($f[0]), 'mb' => filesize($f[0]) / 1048576, 'count' => count($f)] : null;
};
$db    = $latest('bcrf-db-*.sql.gz');
$files = $latest('bcrf-files-*.zip');
$state = bcrf_gdrive_state();
$age   = fn($ts) => $ts ? (floor((time() - $ts) / 3600) < 48 ? floor((time() - $ts) / 3600) . ' h ago' : floor((time() - $ts) / 86400) . ' days ago') : '';
$h     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$checks = [
    ['Database backup (nightly)', $db ? $db['name'] . ' — ' . $age($db['at']) . ', ' . number_format($db['mb'], 1) . ' MB, ' . $db['count'] . ' kept' : 'none found yet', $db && time() - $db['at'] < 36 * 3600],
    ['Files backup (weekly)', $files ? $files['name'] . ' — ' . $age($files['at']) . ', ' . number_format($files['mb'], 1) . ' MB' : 'none yet (add the weekly cron)', $files && time() - $files['at'] < 8 * 86400],
    ['Encryption password', BCRF_BACKUP_PASSWORD !== '' ? 'set (BACKUP_PASSWORD) — keep a copy in your password manager' : 'missing: add BACKUP_PASSWORD to bcrf-secrets.php', BCRF_BACKUP_PASSWORD !== ''],
    ['AES-256 zip support', bcrf_backup_can_encrypt() ? 'available' : 'NOT available on this PHP — ask Bluehost for libzip 1.2+', bcrf_backup_can_encrypt()],
    ['Google Drive login key', bcrf_gdrive_configured() ? 'set' : 'missing: add GDRIVE_CLIENT_ID / GDRIVE_CLIENT_SECRET', bcrf_gdrive_configured()],
    ['Google Drive connection', bcrf_gdrive_connected() ? 'connected ' . $h(substr((string)($state['connected_at'] ?? ''), 0, 10)) : 'not connected', bcrf_gdrive_connected()],
];
foreach (['bcrf-db-' => 'Last Drive copy: database', 'bcrf-files-' => 'Last Drive copy: files'] as $p => $label) {
    $ok = $state['last_ok'][$p] ?? null; $er = $state['last_error'][$p] ?? null;
    $okTs = $ok ? strtotime($ok['at']) : 0; $erTs = $er ? strtotime($er['at']) : 0;
    if ($er && $erTs >= $okTs) $checks[] = [$label, 'FAILED ' . $age($erTs) . ': ' . $er['msg'], false];
    elseif ($ok) $checks[] = [$label, $ok['file'] . ' — ' . $age($okTs), true];
    else $checks[] = [$label, 'none yet', null];
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backups — BCRF Admin</title><meta name="robots" content="noindex">
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f5f5f4;color:#1c1917;margin:0;padding:16px;font-size:15px;line-height:1.5}
main{max-width:720px;margin:0 auto}h1{font-size:1.4rem;margin:0 0 4px}a{color:#4a6741}
.card{background:#fff;border:1px solid #e7e5e4;border-radius:10px;padding:16px;margin:14px 0}
.row{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f0efee}.row:last-child{border-bottom:0}
.dot{flex:0 0 auto;width:22px}.lbl{font-weight:600;min-width:42%}.val{color:#44403c;word-break:break-word}
.flash{padding:12px 14px;border-radius:8px;margin:12px 0}.ok{background:#ecfdf5;border:1px solid #a7f3d0}.err{background:#fef2f2;border:1px solid #fecaca}
button,.btn{font:inherit;padding:10px 16px;border-radius:8px;border:0;cursor:pointer;margin:4px 6px 4px 0;display:inline-block;text-decoration:none}.btn.primary{color:#fff}
.primary{background:#3b2010;color:#fff}.plain{background:#e7e5e4}.danger{background:#fff;border:1px solid #fca5a5;color:#991b1b}
ol{padding-left:20px}li{margin:6px 0}code{background:#f5f5f4;padding:1px 5px;border-radius:4px;font-size:.88em;word-break:break-all}
</style></head><body><main>
<p><a href="/admin/admin.php">← Back to console</a></p>
<h1>💾 Backups</h1>
<p style="margin:0;color:#57534e">Database every night, photos/résumés/secrets every week — encrypted, kept on the server and copied to Google Drive.</p>
<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= $h($flash[1]) ?></div><?php endif; ?>

<div class="card">
<?php foreach ($checks as [$label, $val, $good]): ?>
  <div class="row"><span class="dot"><?= $good === true ? '✅' : ($good === false ? '⚠️' : '•') ?></span><span class="lbl"><?= $h($label) ?></span><span class="val"><?= $h($val) ?></span></div>
<?php endforeach; ?>
</div>

<?php if ($dev): ?>
<div class="card" style="border:2px solid #3b2010">
  <strong>Connect Google Drive</strong>
  <ol>
    <li>Open <a href="<?= $h($dev['url']) ?>" target="_blank" rel="noopener"><?= $h(preg_replace('#^https?://(www\.)?#', '', $dev['url'])) ?></a> (signed in as the Google account that should hold the backups).</li>
    <li>Enter this code: <span style="font:700 1.6rem/1.2 ui-monospace,monospace;letter-spacing:.08em;background:#f5f5f4;padding:4px 10px;border-radius:6px;display:inline-block;margin-top:4px"><?= $h($dev['user_code']) ?></span></li>
    <li>Choose your account and tap <b>Allow</b> (if Google says the app isn't verified: Advanced → Go to BCRF Backups — it's your own app).</li>
  </ol>
  <p id="dev-status" style="color:#57534e;margin:6px 0">Waiting for you to approve… this page will update by itself.</p>
  <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <button class="plain" name="action" value="check">I've approved it — check now</button>
    <button class="danger" name="action" value="cancel_connect">Cancel</button>
  </form>
  <script>
  (function () {
    var wait = <?= (int)$dev['interval'] ?> * 1000, el = document.getElementById('dev-status');
    function tick() {
      fetch('/admin/backups.php?poll=1', {credentials: 'same-origin', cache: 'no-store'})
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.status === 'pending') { el.textContent = j.message; setTimeout(tick, wait); }
          else { location.href = '/admin/backups.php'; }
        })
        .catch(function () { setTimeout(tick, wait * 2); });
    }
    setTimeout(tick, wait);
  })();
  </script>
</div>
<?php else: ?>
<div class="card">
  <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <button class="primary" name="action" value="connect" <?= bcrf_gdrive_configured() ? '' : 'disabled' ?>><?= bcrf_gdrive_connected() ? 'Reconnect Google Drive' : 'Connect Google Drive' ?></button>
    <?php if (bcrf_gdrive_connected()): ?>
      <button class="plain" name="action" value="test">Test connection</button>
      <button class="danger" name="action" value="disconnect" onclick="return confirm('Stop copying backups to Google Drive?')">Disconnect</button>
    <?php endif; ?>
  </form>
  <?php if (!bcrf_gdrive_configured()): ?><p style="color:#57534e;margin:6px 0 0">Finish the one-time setup below first.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!bcrf_gdrive_configured() || BCRF_BACKUP_PASSWORD === ''): ?>
<div class="card"><strong>One-time setup</strong> (about 10 minutes, works from a phone)
<ol>
  <li>Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">console.cloud.google.com</a> signed in as the Google account whose Drive should hold the backups. Create a project named <code>BCRF Backups</code>.</li>
  <li>APIs &amp; Services → Library → search <b>Google Drive API</b> → Enable.</li>
  <li>APIs &amp; Services → OAuth consent screen (Google Auth Platform). <b>Branding:</b> app name <code>BCRF Backups</code> and your email; leave the app domain and logo fields empty. <b>Audience:</b> External, then <b>Publish app</b> (In production) — in Testing mode Google ends the connection every 7 days. Scopes don't need adding.</li>
  <li><b>Clients</b> → Create client → type <b>TVs and Limited Input devices</b> → name <code>BCRF Backups</code> → Create. Copy the client ID and secret. (No redirect URI needed.)</li>
  <li>In File Manager, edit <code>/home2/ddailnmy/bcrf-secrets.php</code> and add inside the array:<br>
    <code>'GDRIVE_CLIENT_ID' =&gt; '…',</code><br><code>'GDRIVE_CLIENT_SECRET' =&gt; '…',</code><br>
    <code>'BACKUP_PASSWORD' =&gt; '…a long passphrase…',</code><br>
    and save the same passphrase in your password manager.</li>
  <li>Reload this page and tap <b>Connect Google Drive</b>, then enter the code it shows at google.com/device.</li>
</ol></div>
<?php endif; ?>

<div class="card"><strong>Cron jobs</strong> (cPanel → Cron Jobs)
<p style="margin:6px 0">Nightly database (already set up):<br><code>15 3 * * * /usr/local/bin/php <?= $h(realpath(__DIR__)) ?>/cron/backup_db.php &gt;&gt; <?= $h($dir) ?>/backup_db.log 2&gt;&amp;1</code></p>
<p style="margin:6px 0">Weekly files (add this one):<br><code>40 3 * * 0 /usr/local/bin/php <?= $h(realpath(__DIR__)) ?>/cron/backup_files.php &gt;&gt; <?= $h($dir) ?>/backup_files.log 2&gt;&amp;1</code></p>
<p style="margin:6px 0;color:#57534e">Restoring: see <code>docs/RESTORE.md</code> in GitHub.</p></div>
</main></body></html>
