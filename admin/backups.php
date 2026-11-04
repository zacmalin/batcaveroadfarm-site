<?php // admin/backups.php
/*
 * Backups status + "Connect Google Drive" (Nov 2026). Administrators only.
 * Google sends people back here after the consent screen (?code=…&state=…), so this
 * exact URL must be listed as an Authorized redirect URI on the Google OAuth client.
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

// ── 1. Coming back from Google's consent screen ──────────────────────────────
if (isset($_GET['code']) || isset($_GET['error'])) {
    $state = (string)($_GET['state'] ?? '');
    $want  = (string)($_SESSION['gdrive_oauth_state'] ?? '');
    unset($_SESSION['gdrive_oauth_state']);
    if ($want === '' || !hash_equals($want, $state)) $go('err', 'That sign-in link expired. Tap Connect again.');
    if (isset($_GET['error'])) $go('err', 'Google sign-in was cancelled (' . (string)$_GET['error'] . ').');
    try {
        bcrf_gdrive_exchange_code((string)$_GET['code']);
        $tok = bcrf_gdrive_access_token();
        bcrf_gdrive_folder($tok);
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'backups', null, 'Connected Google Drive for off-site backups');
        $go('ok', 'Google Drive connected. The "' . BCRF_GDRIVE_FOLDER . '" folder is ready; the next backup will copy there.');
    } catch (Throwable $e) {
        $go('err', 'Could not finish connecting: ' . $e->getMessage());
    }
}

// ── 2. Connect: a plain link (GET), because the admin CSP's form-action 'self' makes
//    browsers block a form POST that redirects to accounts.google.com.
if (isset($_GET['connect'])) {
    if (!bcrf_gdrive_configured()) $go('err', 'Add GDRIVE_CLIENT_ID and GDRIVE_CLIENT_SECRET to bcrf-secrets.php first.');
    $_SESSION['gdrive_oauth_state'] = bin2hex(random_bytes(16));
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => BCRF_GDRIVE_CLIENT_ID, 'redirect_uri' => BCRF_GDRIVE_REDIRECT, 'response_type' => 'code',
        'scope' => BCRF_GDRIVE_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent',
        'state' => $_SESSION['gdrive_oauth_state'], 'include_granted_scopes' => 'true',
    ]));
    exit;
}

// ── 3. Buttons ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) $go('err', 'Security token expired — reload and try again.');
    $act = (string)($_POST['action'] ?? '');
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

// ── 4. Status ────────────────────────────────────────────────────────────────
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

<div class="card">
  <?php if (bcrf_gdrive_configured()): ?><a class="btn primary" href="/admin/backups.php?connect=1"><?= bcrf_gdrive_connected() ? 'Reconnect Google Drive' : 'Connect Google Drive' ?></a><?php else: ?><span class="btn plain" style="opacity:.6">Connect Google Drive (finish setup below first)</span><?php endif; ?>
  <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <?php if (bcrf_gdrive_connected()): ?>
      <button class="plain" name="action" value="test">Test connection</button>
      <button class="danger" name="action" value="disconnect" onclick="return confirm('Stop copying backups to Google Drive?')">Disconnect</button>
    <?php endif; ?>
  </form>
</div>

<?php if (!bcrf_gdrive_configured() || BCRF_BACKUP_PASSWORD === ''): ?>
<div class="card"><strong>One-time setup</strong> (about 10 minutes, works from a phone)
<ol>
  <li>Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">console.cloud.google.com</a> signed in as the Google account whose Drive should hold the backups. Create a project named <code>BCRF Backups</code>.</li>
  <li>APIs &amp; Services → Library → search <b>Google Drive API</b> → Enable.</li>
  <li>APIs &amp; Services → OAuth consent screen → External. App name <code>BCRF Backups</code>, your email for support and developer contact. Add scope <code>.../auth/drive.file</code>. Then <b>Publish app</b> (In production) — in Testing mode Google ends the connection every 7 days.</li>
  <li>Credentials → Create credentials → OAuth client ID → <b>Web application</b>. Authorized redirect URI: <code><?= $h(BCRF_GDRIVE_REDIRECT) ?></code>. Copy the client ID and secret.</li>
  <li>In File Manager, edit <code>/home2/ddailnmy/bcrf-secrets.php</code> and add inside the array:<br>
    <code>'GDRIVE_CLIENT_ID' =&gt; '…',</code><br><code>'GDRIVE_CLIENT_SECRET' =&gt; '…',</code><br>
    <code>'BACKUP_PASSWORD' =&gt; '…a long passphrase…',</code><br>
    and save the same passphrase in your password manager.</li>
  <li>Reload this page and tap <b>Connect Google Drive</b>. Google will warn the app is unverified — tap Advanced → Go to BCRF Backups (it's your own app).</li>
</ol></div>
<?php endif; ?>

<div class="card"><strong>Cron jobs</strong> (cPanel → Cron Jobs)
<p style="margin:6px 0">Nightly database (already set up):<br><code>15 3 * * * /usr/local/bin/php <?= $h(realpath(__DIR__)) ?>/cron/backup_db.php &gt;&gt; <?= $h($dir) ?>/backup_db.log 2&gt;&amp;1</code></p>
<p style="margin:6px 0">Weekly files (add this one):<br><code>40 3 * * 0 /usr/local/bin/php <?= $h(realpath(__DIR__)) ?>/cron/backup_files.php &gt;&gt; <?= $h($dir) ?>/backup_files.log 2&gt;&amp;1</code></p>
<p style="margin:6px 0;color:#57534e">Restoring: see <code>docs/RESTORE.md</code> in GitHub.</p></div>
</main></body></html>
