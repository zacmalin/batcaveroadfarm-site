<?php // admin/gbp_connect.php
/*
 * Google Business Profile connection (Nov 2026). Administrators only.
 * Sign in once with the Google account that manages the profile, pick the farm's location,
 * and the social queue (admin/cron/send_social.php) can post "What's new" updates there.
 * Google returns to https://api.batcaveroadfarm.com/gbp-callback.php, which forwards code + state here
 * (ModSecurity on this host blocks Google's original reply).
 * Library: includes/gbp.php
 */
require_once '../config.php';
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/../includes/gbp.php';
require_once __DIR__ . '/includes/audit.php';
date_default_timezone_set('America/New_York');
gbp_schema($pdo);

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf  = $_SESSION['csrf_token'];
$flash = $_SESSION['gbp_flash'] ?? null; unset($_SESSION['gbp_flash']);
$go = function (string $msg) { $_SESSION['gbp_flash'] = $msg; header('Location: /admin/gbp_connect.php'); exit; };
$save_pick = function (array $loc) use ($pdo) {
    $pdo->prepare("UPDATE gbp_auth SET account_name = ?, location_name = ?, location_title = ?, last_error = NULL WHERE id = 1")
        ->execute([$loc['account'], $loc['location'], $loc['title']]);
    erp_audit($pdo, 'update', 'gbp_auth', 1, 'Google Business Profile location: ' . $loc['title']);
};

if (!gbp_configured()) {
    $flash = 'Add GBP_CLIENT_ID and GBP_CLIENT_SECRET to bcrf-secrets.php first (see the top of includes/gbp.php).';
}

// ── 1. Start sign-in ──
if (gbp_configured() && isset($_GET['start'])) {
    $_SESSION['gbp_state'] = bin2hex(random_bytes(16));
    header('Location: ' . gbp_auth_url($_SESSION['gbp_state']));
    exit;
}

// ── 2. Google sends the person back here ──
if (gbp_configured() && (isset($_GET['code']) || isset($_GET['error']))) {
    $state = (string)($_GET['state'] ?? '');
    $want  = (string)($_SESSION['gbp_state'] ?? '');
    unset($_SESSION['gbp_state']);
    if (isset($_GET['error'])) $go('Google sign-in was cancelled (' . (string)$_GET['error'] . ').');
    if ($want === '' || !hash_equals($want, $state)) $go('Sign-in link expired. Tap Connect Google again.');
    try {
        $tok = gbp_exchange_code((string)$_GET['code']);
        if (empty($tok['refresh_token'])) $go('Google did not return a long-term sign-in. Remove the app at myaccount.google.com/permissions, then connect again.');
        $email = '';
        if (!empty($tok['id_token']) && count($parts = explode('.', (string)$tok['id_token'])) === 3) {
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $email  = (string)($claims['email'] ?? '');
        }
        $pdo->prepare("REPLACE INTO gbp_auth (id, refresh_token_enc, google_email, connected_at) VALUES (1, ?, ?, NOW())")
            ->execute([gbp_encrypt((string)$tok['refresh_token']), $email]);
        erp_audit($pdo, 'update', 'gbp_auth', 1, 'Connected Google Business Profile' . ($email ? " ({$email})" : ''));
        $locs = gbp_list_locations($pdo);
        if (count($locs) === 1) { $save_pick($locs[0]); $go('Connected. Posts will go to ' . $locs[0]['title'] . '.'); }
        if (!$locs) $go('Signed in, but that Google account manages no Business Profile locations. Disconnect and sign in with the account that owns the profile.');
        $go('Signed in. Pick the location to post to.');
    } catch (Throwable $e) {
        $go($e->getMessage());
    }
}

// ── 3. Pick location / disconnect ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) $go('Security token expired. Try again.');
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'pick') {
        try {
            foreach (gbp_list_locations($pdo) as $loc) {
                if ($loc['location'] === (string)($_POST['location'] ?? '')) { $save_pick($loc); $go('Posts will go to ' . $loc['title'] . '.'); }
            }
            $go('That location is no longer available to this Google account.');
        } catch (Throwable $e) { $go($e->getMessage()); }
    }
    if ($do === 'disconnect') {
        $a = gbp_auth_row($pdo);
        $rt = !empty($a['refresh_token_enc']) ? gbp_decrypt((string)$a['refresh_token_enc']) : null;
        if ($rt) gbp_http('POST', 'https://oauth2.googleapis.com/revoke', ['token' => $rt], null, true);
        $pdo->exec("DELETE FROM gbp_auth WHERE id = 1");
        erp_audit($pdo, 'update', 'gbp_auth', 1, 'Disconnected Google Business Profile');
        $go('Disconnected. Queued posts will skip Google until you connect again.');
    }
    if ($do === 'check') {
        try { $n = count(gbp_list_locations($pdo)); gbp_note($pdo, true); $go("Connection works ({$n} location" . ($n === 1 ? '' : 's') . ' visible).'); }
        catch (Throwable $e) { $go('Connection problem: ' . $e->getMessage()); }
    }
}

$a      = gbp_auth_row($pdo);
$signed = !empty($a['refresh_token_enc']);
$locs   = [];
$locErr = '';
if ($signed && empty($a['location_name'])) {
    try { $locs = gbp_list_locations($pdo); } catch (Throwable $e) { $locErr = $e->getMessage(); }
}
$when = fn(?string $d) => $d ? date('M j, g:i A', strtotime($d)) : '—';
$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Google Business Profile · BCRF Admin</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f1ea;color:#2b2118}
.wrap{max-width:760px;margin:0 auto;padding:18px 14px 60px}
a{color:#4a6741}h1{font-size:1.35rem;margin:10px 0 4px}.sub{color:#6b5a48;font-size:.92rem;margin:0 0 16px}
.card{background:#fff;border:1px solid #e3d8c6;border-radius:12px;padding:14px 16px;margin:12px 0}
.flash{background:#e8f2e3;border:1px solid #b7d1aa;border-radius:10px;padding:10px 14px}
.err{background:#fbe9e7;border-color:#e6b4ad}
.meta{font-size:.88rem;color:#6b5a48;line-height:1.7}
.pill{display:inline-block;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:6px}
.on{background:#e3f0dc;color:#2f5a22}.off{background:#eee;color:#666}
.btn,button{display:inline-block;font:inherit;font-weight:700;padding:9px 15px;border-radius:8px;border:1px solid #4a6741;background:#4a6741;color:#fff;cursor:pointer;text-decoration:none;margin:4px 6px 4px 0}
button.ghost{background:#fff;color:#4a6741}button.danger{background:#fff;color:#8b2018;border-color:#8b2018}
label.loc{display:block;padding:8px 0;border-bottom:1px solid #eee}code{background:#f1ebe0;padding:1px 5px;border-radius:4px}
</style></head><body><div class="wrap">
<p><a href="/admin/admin.php?view=social">← Social Queue</a></p>
<h1>Google Business Profile</h1>
<p class="sub">When this is connected, posts you approve at publish time also go to the farm's Google profile as a “What's new” update with a <em>Learn more</em> button.</p>
<?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>

<div class="card">
  <div><strong>Status</strong>
    <span class="pill <?= gbp_ready($pdo) ? 'on' : 'off' ?>"><?= gbp_ready($pdo) ? 'Connected' : ($signed ? 'Pick a location' : 'Not connected') ?></span></div>
  <div class="meta">
    Google account: <?= $h($a['google_email'] ?? '—') ?><br>
    Location: <?= $h($a['location_title'] ?? '—') ?><br>
    Connected: <?= $h($when($a['connected_at'] ?? null)) ?> · last successful call: <?= $h($when($a['last_ok_at'] ?? null)) ?>
    <?php if (!empty($a['last_error'])): ?><br><span style="color:#8b2018">Last error: <?= $h($a['last_error']) ?></span><?php endif; ?>
  </div>
  <div style="margin-top:10px">
    <?php if (gbp_configured()): ?>
      <a class="btn" href="/admin/gbp_connect.php?start=1"><?= $signed ? 'Reconnect Google' : 'Connect Google' ?></a>
    <?php endif; ?>
    <?php if ($signed): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="check"><button class="ghost">Check connection</button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('Disconnect Google Business Profile?')"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="disconnect"><button class="danger">Disconnect</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($signed && empty($a['location_name'])): ?>
<div class="card<?= $locErr ? ' err' : '' ?>">
  <?php if ($locErr): ?>
    <?= $h($locErr) ?>
  <?php elseif (!$locs): ?>
    This Google account doesn't manage any Business Profile locations.
  <?php else: ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="pick">
      <strong>Post to which location?</strong>
      <?php foreach ($locs as $i => $l): ?>
        <label class="loc"><input type="radio" name="location" value="<?= $h($l['location']) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= $h($l['title']) ?><br><span class="meta"><?= $h($l['address']) ?></span></label>
      <?php endforeach; ?>
      <button style="margin-top:10px">Use this location</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<p class="sub" style="margin-top:22px">Google posts use the Facebook caption without links (links in Google post text aren't clickable; the button carries it) and the post's photo. You can edit the Google text in the composer before publishing.</p>
</div></body></html>
