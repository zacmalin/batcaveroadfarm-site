<?php // admin/pinterest_connect.php
/*
 * Settings › Pinterest (Part B, Nov 2026). Administrators only. This address is the OAuth redirect URI registered
 * in the Pinterest app: https://admin.batcaveroadfarm.com/pinterest_connect.php
 *   1. Connect: sign in on Pinterest with the farm's business account; Pinterest sends code + state back here.
 *   2. Boards: sync the account's boards and pick one for recipes and one for animals.
 *   3. Auto-post: per content type, off by default. When on, a recipe or animal's FIRST publication posts a Pin.
 * Environment: Sandbox while the app has Trial access (Pins only visible to you), Production after Standard access.
 * Library: includes/adapter_pinterest.php (sign-in, tokens, API), includes/pinterest_send.php (posting).
 */
require_once '../config.php';
require_once __DIR__ . '/../includes/admin_shell.php';   // shared Farm OS shell (Nov 11, 2026)
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/../includes/pinterest_send.php';
require_once __DIR__ . '/includes/audit.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf  = $_SESSION['csrf_token'];
$flash = $_SESSION['pin_flash'] ?? null; unset($_SESSION['pin_flash']);
$uid   = (int)($_SESSION['user_id'] ?? 0) ?: null;
$go    = function (string $msg) { $_SESSION['pin_flash'] = $msg; header('Location: /admin/pinterest_connect.php'); exit; };
$log   = function (string $label, array $after = []) use ($pdo, $uid) {
    bcrf_audit_event($pdo, ['actor_id' => $uid, 'actor_label' => (string)($_SESSION['username'] ?? ''), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'action' => 'update', 'object_type' => 'pinterest_auth', 'object_id' => '1', 'object_label' => $label, 'after' => $after ?: null]);
};
$tables = bcrf_pinapi_tables($pdo);
$keys   = bcrf_pinapi_configured();

// Pick the suggested boards by name when none is chosen yet
$autoPick = function () use ($pdo): void {
    $boards = bcrf_pinapi_boards($pdo);
    $names  = bcrf_pin_board_names($pdo);
    foreach (['recipes', 'animals'] as $k) {
        $cur = bcrf_pin_setting($pdo, 'pinterest_board_id_' . $k);
        if ($cur !== '' && isset($boards[$cur])) continue;
        foreach ($boards as $id => $n) {
            if (strcasecmp(trim($n), $names[$k]) === 0) { bcrf_pin_setting_save($pdo, 'pinterest_board_id_' . $k, (string)$id); break; }
        }
    }
};

// ── 1. Start sign-in ──
if ($tables && $keys && isset($_GET['start'])) {
    $_SESSION['pin_state'] = bin2hex(random_bytes(16));
    header('Location: ' . bcrf_pinapi_auth_url($_SESSION['pin_state']));
    exit;
}

// ── 2. Pinterest sends the person back here ──
if ($tables && $keys && (isset($_GET['code']) || isset($_GET['error']))) {
    $state = (string)($_GET['state'] ?? '');
    $want  = (string)($_SESSION['pin_state'] ?? '');
    unset($_SESSION['pin_state']);
    if (isset($_GET['error'])) $go('Pinterest sign-in was cancelled (' . mb_substr((string)$_GET['error'], 0, 80) . ').');
    if ($want === '' || !hash_equals($want, $state)) $go('That sign-in link expired. Tap Connect Pinterest again.');
    try {
        $env = bcrf_pinapi_env($pdo);
        $a   = bcrf_pinapi_exchange_code($pdo, (string)$_GET['code'], $env, $uid);
        $log('Connected Pinterest' . (!empty($a['username']) ? ' (' . $a['username'] . ')' : '') . ', ' . $env);
        try { $b = bcrf_pinapi_sync_boards($pdo); $autoPick(); $note = ' Found ' . count($b) . ' board' . (count($b) === 1 ? '' : 's') . '.'; }
        catch (Throwable $e) { $note = ' Boards could not be read yet: ' . $e->getMessage(); }
        $go('Connected to Pinterest' . (!empty($a['username']) ? ' as ' . $a['username'] : '') . ' (' . $env . ').' . $note);
    } catch (Throwable $e) {
        $go('Connecting failed: ' . $e->getMessage());
    }
}

// ── 3. Settings actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tables) {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) $go('Security token expired. Try again.');
    $do = (string)($_POST['do'] ?? '');
    $connected = !empty(bcrf_pinapi_auth($pdo)['refresh_token_enc']);
    if ($do === 'env') {
        if ($connected) $go('Disconnect first, then choose the environment and connect again (sign-ins belong to one environment).');
        $env = ($_POST['env'] ?? '') === 'production' ? 'production' : 'sandbox';
        bcrf_pin_setting_save($pdo, 'pinterest_env', $env);
        $log('Pinterest environment: ' . $env);
        $go('Environment set to ' . ($env === 'production' ? 'Production' : 'Sandbox') . '. Now tap Connect Pinterest.');
    }
    if ($do === 'check') {
        try {
            $r = bcrf_pinapi_request($pdo, 'GET', '/user_account');
            if ($r['status'] !== 200) $go('Connection problem: ' . bcrf_pinapi_error($r));
            $pdo->prepare("UPDATE pinterest_auth SET username = ?, account_type = ? WHERE id = 1")
                ->execute([mb_substr((string)($r['json']['username'] ?? ''), 0, 120), mb_substr((string)($r['json']['account_type'] ?? ''), 0, 40)]);
            $go('Connection works: signed in as ' . ($r['json']['username'] ?? '?') . ' (' . strtolower((string)($r['json']['account_type'] ?? '')) . ').');
        } catch (Throwable $e) { $go('Connection problem: ' . $e->getMessage()); }
    }
    if ($do === 'sync') {
        try { $b = bcrf_pinapi_sync_boards($pdo); $autoPick(); $go('Synced ' . count($b) . ' board' . (count($b) === 1 ? '' : 's') . '.'); }
        catch (Throwable $e) { $go('Board sync failed: ' . $e->getMessage()); }
    }
    if ($do === 'create_board') {
        $k = ($_POST['key'] ?? '') === 'animals' ? 'animals' : 'recipes';
        $name = bcrf_pin_board_names($pdo)[$k];
        $desc = $k === 'recipes' ? 'Farmhouse recipes from Bat Cave Road Farm in Old Fort, NC.' : 'Meet the animals at the Bat Cave Road Farm petting zoo in Old Fort, NC.';
        try {
            if (in_array($name, bcrf_pinapi_boards($pdo), true)) $go('A board called ' . $name . ' already exists.');
            $id = bcrf_pinapi_create_board($pdo, $name, $desc);
            if (bcrf_pin_setting($pdo, 'pinterest_board_id_' . $k) === '') bcrf_pin_setting_save($pdo, 'pinterest_board_id_' . $k, $id);
            $log('Created Pinterest board ' . $name);
            $go('Created the board ' . $name . '.');
        } catch (Throwable $e) { $go('Could not create the board: ' . $e->getMessage()); }
    }
    if ($do === 'settings') {
        $boards = bcrf_pinapi_boards($pdo);
        $after = [];
        foreach (['recipes', 'animals'] as $k) {
            $v = (string)($_POST['board_' . $k] ?? '');
            if ($v !== '' && !isset($boards[$v])) $go('That board is not on the connected account. Sync boards and try again.');
            bcrf_pin_setting_save($pdo, 'pinterest_board_id_' . $k, $v);
            $after['board_' . $k] = $v === '' ? '' : $boards[$v];
        }
        foreach (['recipe' => 'recipes', 'animal' => 'animals'] as $t => $k) {
            $on = !empty($_POST['autopost_' . $t]) ? '1' : '0';
            if ($on === '1' && bcrf_pin_setting($pdo, 'pinterest_board_id_' . $k) === '') $go('Pick a board for ' . $k . ' before turning on auto-posting.');
            bcrf_pin_setting_save($pdo, 'pinterest_autopost_' . $t, $on);
            $after['autopost_' . $t] = $on;
        }
        $log('Pinterest settings saved', $after);
        $go('Saved.');
    }
    if ($do === 'disconnect') {
        $pdo->exec("DELETE FROM pinterest_auth WHERE id = 1");
        foreach (['recipe', 'animal'] as $t) bcrf_pin_setting_save($pdo, 'pinterest_autopost_' . $t, '0');
        $log('Disconnected Pinterest');
        $go('Disconnected, and auto-posting switched off. Approved Pins wait until you connect again. To remove the app from the account too: Pinterest › Settings › Security and logins › Apps.');
    }
}

$a       = $tables ? bcrf_pinapi_auth($pdo) : [];
$signed  = !empty($a['refresh_token_enc']);
$env     = $tables ? bcrf_pinapi_env($pdo) : 'sandbox';
$boards  = $signed ? bcrf_pinapi_boards($pdo) : [];
$names   = $tables ? bcrf_pin_board_names($pdo) : [];
$pick    = ['recipes' => $tables ? bcrf_pin_setting($pdo, 'pinterest_board_id_recipes') : '', 'animals' => $tables ? bcrf_pin_setting($pdo, 'pinterest_board_id_animals') : ''];
$auto    = ['recipe' => $tables && bcrf_pin_setting($pdo, 'pinterest_autopost_recipe') === '1', 'animal' => $tables && bcrf_pin_setting($pdo, 'pinterest_autopost_animal') === '1'];
$when    = function (?string $utc): string { if (!$utc) return '—'; $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone(date_default_timezone_get())); return $d->format('M j, g:i A'); };
$h       = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$ok      = $signed && !(int)($a['needs_reconnect'] ?? 0);
?><?php bcrf_shell_open('Pinterest', ['strict' => false, 'css' => ['admin-settings.css'], 'width' => 'max-w-3xl fset fs-pin']); ?>
<p><a href="/admin/admin.php?view=social&amp;tab=pinterest">← Pinterest drafts</a></p>
<h1>Pinterest</h1>
<p class="sub">Connect the farm's Pinterest business account so approved Pins post on their own, and choose which board each kind of Pin goes to.</p>
<?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>

<?php if (!$tables): ?>
  <div class="card">Not set up yet. In <a href="/admin/migrations.php">Settings › Database</a>, run migration <code>0008_pinterest_connection</code>.</div>
<?php elseif (!$keys): ?>
  <div class="card">Add <code>PINTEREST_APP_ID</code> and <code>PINTEREST_APP_SECRET</code> to <code>bcrf-secrets.php</code> first.</div>
<?php else: ?>

<div class="card">
  <h2>Connection
    <span class="pill <?= $ok ? 'on' : ($signed ? 'bad' : 'off') ?>"><?= $ok ? 'Connected' : ($signed ? 'Reconnect needed' : 'Not connected') ?></span>
    <span class="pill <?= $env === 'production' ? 'on' : 'warn' ?>"><?= $env === 'production' ? 'Production' : 'Sandbox' ?></span></h2>
  <div class="meta">
    Account: <?= $h($a['username'] ?? '—') ?><?= !empty($a['account_type']) ? ' (' . $h(strtolower($a['account_type'])) . ')' : '' ?><br>
    Connected: <?= $h($when($a['connected_at'] ?? null)) ?> · last successful call: <?= $h($when($a['last_ok_at'] ?? null)) ?><br>
    Sign-in renews automatically<?= !empty($a['refresh_expires_at']) ? ' (current renewal good until ' . $h($when($a['refresh_expires_at'])) . ')' : '' ?>.
    <?php if (!empty($a['last_error'])): ?><br><span style="color:#8b2018">Last problem: <?= $h($a['last_error']) ?></span><?php endif; ?>
  </div>
  <div style="margin-top:10px">
    <a class="btn" href="/admin/pinterest_connect.php?start=1"><?= $signed ? 'Reconnect Pinterest' : 'Connect Pinterest' ?></a>
    <?php if ($signed): ?>
      <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="check"><button class="ghost">Check connection</button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('Disconnect Pinterest? Auto-posting will be switched off.')"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="disconnect"><button class="danger">Disconnect</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$signed): ?>
    <form method="post" class="meta" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="env">
      <strong>Environment</strong><br>
      <label><input type="radio" name="env" value="sandbox"<?= $env === 'sandbox' ? ' checked' : '' ?>> Sandbox: while the app has Trial access. Pins are visible only to you.</label><br>
      <label><input type="radio" name="env" value="production"<?= $env === 'production' ? ' checked' : '' ?>> Production: after Pinterest grants Standard access. Pins are public.</label><br>
      <button class="ghost">Save environment</button>
    </form>
  <?php else: ?>
    <p class="meta" style="margin:10px 0 0">To switch between Sandbox and Production, disconnect, choose the environment, then connect again.</p>
  <?php endif; ?>
</div>

<?php if ($signed): ?>
<div class="card">
  <h2>Boards and auto-posting</h2>
  <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="sync"><button class="ghost">Sync boards from Pinterest</button></form>
  <?php foreach (['recipes', 'animals'] as $k): if (in_array($names[$k] ?? '', $boards, true)) continue; ?>
    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="create_board"><input type="hidden" name="key" value="<?= $k ?>"><button class="ghost">Create “<?= $h($names[$k] ?? '') ?>”</button></form>
  <?php endforeach; ?>
  <?php if ($env === 'sandbox'): ?><p class="meta" style="margin:6px 0 0">Sandbox has its own boards, separate from the ones you see on Pinterest: create them here.</p><?php endif; ?>
  <?php if (!$boards): ?>
    <p class="meta">No boards found yet. Create <strong><?= $h($names['recipes'] ?? 'Farmhouse Recipes') ?></strong> and <strong><?= $h($names['animals'] ?? 'Meet Our Farm Animals') ?></strong> with the buttons above (or on Pinterest, in Production), then sync.</p>
  <?php else: ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="settings">
      <?php foreach (['recipes' => '🍴 Recipes go to', 'animals' => '🐐 Animals go to'] as $k => $lbl): ?>
        <label class="row meta"><?= $lbl ?> (suggested: <?= $h($names[$k] ?? '') ?>)<br>
          <select name="board_<?= $k ?>">
            <option value="">— choose a board —</option>
            <?php foreach ($boards as $id => $n): ?><option value="<?= $h($id) ?>"<?= $pick[$k] === (string)$id ? ' selected' : '' ?>><?= $h($n) ?></option><?php endforeach; ?>
          </select></label>
        <?php if ($pick[$k] !== '' && !isset($boards[$pick[$k]])): ?><div class="meta" style="color:#8b2018">The saved board is no longer on this account. Pick again.</div><?php endif; ?>
      <?php endforeach; ?>
      <div class="meta" style="margin-top:14px"><strong>Auto-post new content</strong> (only a record's first publication; edits and bulk drafts never post on their own)</div>
      <label class="row meta"><input type="checkbox" name="autopost_recipe" value="1"<?= $auto['recipe'] ? ' checked' : '' ?>> Post a Pin when a recipe is first published</label>
      <label class="row meta"><input type="checkbox" name="autopost_animal" value="1"<?= $auto['animal'] ? ' checked' : '' ?>> Post a Pin when an animal is first made public</label>
      <button>Save</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card meta">
  Redirect URI registered in the Pinterest app: <code><?= $h(bcrf_pinapi_redirect_uri()) ?></code><br>
  Access requested: <?= $h(str_replace(',', ', ', BCRF_PINAPI_SCOPES)) ?>. Sign-in tokens are stored encrypted and never shown.
</div>
<?php endif; ?>
<?php bcrf_shell_close(); ?>
