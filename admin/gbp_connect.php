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
require_once __DIR__ . '/../includes/admin_shell.php';   // shared Farm OS shell (Nov 11, 2026)
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/../includes/gbp.php';
require_once __DIR__ . '/includes/audit.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)
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
    if ($do === 'manual') {
        // Fallback when Google's lookup APIs are unavailable: Business Profile ID (= location ID) typed in.
        $digits = fn(string $v, string $prefix) => preg_match('#(?:^|' . $prefix . '/)(\d{5,30})$#', trim($v), $m) ? $m[1] : '';
        $raw    = trim((string)($_POST['location_id'] ?? ''));
        $locId  = $digits(preg_replace('#^accounts/\d+/#', '', $raw), 'locations');
        $acctId = $digits((string)($_POST['account_id'] ?? ''), 'accounts');
        if ($acctId === '' && preg_match('#^accounts/(\d+)/locations/#', $raw, $m)) $acctId = $m[1];
        $title  = trim(strip_tags((string)($_POST['title'] ?? ''))) ?: 'Bat Cave Road Farm';
        if ($locId === '') $go('Enter the Business Profile ID (numbers only).');
        try {
            $tried = [];
            $candidates = $acctId !== '' ? [$acctId] : array_map(fn($n) => basename($n), gbp_v4_accounts($pdo));
            foreach ($candidates as $a) {
                try { gbp_verify_ids($pdo, $a, $locId); $save_pick(['account' => "accounts/{$a}", 'location' => "locations/{$locId}", 'title' => mb_substr($title, 0, 190)]); $go('Saved. Posts will go to ' . $title . '.'); }
                catch (RuntimeException $e) { $tried[] = $e->getMessage(); }
            }
            $go($tried ? end($tried) : 'No Google account found for this sign-in. Enter the account ID too.');
        } catch (RuntimeException $e) {
            $go($e->getMessage() . ' Enter the account ID as well.');
        }
    }
    if ($do === 'retry') {
        try { gbp_retry_row($pdo, (int)($_POST['id'] ?? 0)); $go('Posted to Google.'); }
        catch (Throwable $e) { $go('Google still refused it: ' . $e->getMessage()); }
    }
    if ($do === 'check') {
        try {
            // Once a location is saved, check it with the posting API (the lookup APIs may have no quota).
            $a = gbp_auth_row($pdo);
            if (!empty($a['location_name'])) {
                gbp_verify_ids($pdo, basename((string)$a['account_name']), basename((string)$a['location_name']));
                gbp_note($pdo, true); $go('Connection works — Google accepts posts for ' . ($a['location_title'] ?: 'this location') . '.');
            }
            $n = count(gbp_list_locations($pdo)); gbp_note($pdo, true); $go("Connection works ({$n} location" . ($n === 1 ? '' : 's') . ' visible).');
        }
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
?><?php bcrf_shell_open('Google Business Profile', ['strict' => false, 'css' => ['admin-settings.css'], 'width' => 'max-w-3xl fset fs-gbp']); ?>
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
    <div style="color:#8b2018;margin-bottom:10px">Google's location lookup isn't available right now: <?= $h($locErr) ?></div>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="manual">
      <strong>Enter the IDs instead</strong>
      <p class="meta" style="margin:6px 0 10px">Business Profile ID: search the farm's name on Google (signed in) → <em>Edit profile</em> → ⋮ → <em>Business Profile settings</em> → <em>Advanced settings</em>. Leave the account ID blank and the site will try to find it.</p>
      <label class="meta">Business Profile ID<br><input name="location_id" inputmode="numeric" required style="width:100%;padding:8px;font:inherit;border:1px solid #ccc;border-radius:8px"></label><br>
      <label class="meta">Account ID (optional)<br><input name="account_id" inputmode="numeric" style="width:100%;padding:8px;font:inherit;border:1px solid #ccc;border-radius:8px"></label><br>
      <label class="meta">Name to show<br><input name="title" value="Bat Cave Road Farm" style="width:100%;padding:8px;font:inherit;border:1px solid #ccc;border-radius:8px"></label>
      <button style="margin-top:10px">Save IDs</button>
    </form>
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

<?php $failed = gbp_ready($pdo) ? gbp_failed_rows($pdo) : []; if ($failed): ?>
<div class="card">
  <strong>Didn't reach Google</strong>
  <p class="meta" style="margin:4px 0 8px">Facebook/Instagram aren't touched by a retry.</p>
  <?php foreach ($failed as $f): ?>
    <div style="border-top:1px solid #eee;padding:8px 0">
      <div class="meta"><?= $h(ucfirst($f['content_type'])) ?> #<?= (int)$f['content_id'] ?> · <?= $h($when($f['processed_at'])) ?> · <?= $h(mb_substr((string)$f['gbp_caption'], 0, 80)) ?>…</div>
      <?php if (!empty($f['last_error'])): ?><div class="meta" style="color:#8b2018"><?= $h(mb_substr((string)$f['last_error'], 0, 200)) ?></div><?php endif; ?>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="retry"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="ghost">Retry on Google</button></form>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="sub" style="margin-top:22px">Google posts use the Facebook caption without links (links in Google post text aren't clickable; the button carries it) and the post's photo (converted to JPG for Google; if Google still errors, the post goes out without the photo). You can edit the Google text in the composer before publishing.</p>
<?php bcrf_shell_close(); ?>
