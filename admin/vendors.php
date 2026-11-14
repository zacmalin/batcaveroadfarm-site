<?php
// admin/vendors.php — vendor list and editor (Farm OS Phase 2, slice 2.1, Nov 9, 2026)
// Permission: edit on inventory (same as Products). Plain forms, strict CSP (C-09).
// Nov 11, 2026: runs inside the shared Farm OS shell (includes/admin_shell.php) with the Hiring-screen look;
// the Products & Inventory tabs replace the old "← Admin · Products · Stock" links.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/vendors.php';
require_once __DIR__ . '/../includes/admin_shell.php';
require_once __DIR__ . '/../includes/admin_switch.php';   // Active switch on the list (Nov 14, 2026)
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_vendors_ready($pdo)) {
    bcrf_shell_open('Vendors');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Vendors</h1>',
         bcrf_shell_notice('warning', 'Run migration 0018 in Settings › Database first.'),
         '<a href="/admin/migrations.php" class="text-sm text-amber-800 underline">Open Settings › Database</a>';
    bcrf_shell_close();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'active') {
    // Active switch on the list (JSON when ajax=1). Same token and permission as Save; audited.
    $id = (int)($_POST['id'] ?? 0);
    $back = (string)($_POST['back'] ?? '');
    $back = preg_match('#^vendors\.php(\?[A-Za-z0-9_=&%+.\-]*)?$#', $back) ? $back : 'vendors.php';
    $want = bcrf_switch_wanted();
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Security token expired. Reload the page and try again.';
        if (bcrf_switch_ajax()) bcrf_switch_json(false, $msg);
        $_SESSION['vend_flash'] = ['err', $msg]; header('Location: ' . $back); exit;
    }
    try {
        $r = bcrf_switch_set($pdo, 'vendors', 'is_active', $id, $want, 'name', ['updated_by' => $uid], true);
        $msg = $r === null ? 'That vendor no longer exists.' : $r['label'] . ($want ? ' is active.' : ' is inactive.');
        if (bcrf_switch_ajax()) bcrf_switch_json($r !== null, $msg, $r === null ? null : $want);
        $_SESSION['vend_flash'] = [$r === null ? 'err' : 'ok', $msg];
    } catch (Throwable $e) {
        error_log('[vendors] active: ' . $e->getMessage());
        if (bcrf_switch_ajax()) bcrf_switch_json(false, 'Could not save. Try again.');
        $_SESSION['vend_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int)($_POST['id'] ?? 0) ?: null;
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['vend_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: vendors.php' . ($id ? '?id=' . $id : '?new=1')); exit;
    }
    try {
        $saved = bcrf_vendor_save($pdo, $id, $_POST, $uid);
        $_SESSION['vend_flash'] = ['ok', $id ? 'Saved.' : 'Vendor added.'];
        header('Location: vendors.php?id=' . $saved); exit;
    } catch (InvalidArgumentException $e) {
        $_SESSION['vend_flash'] = ['err', $e->getMessage()];
        $_SESSION['vend_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[vendors] save: ' . $e->getMessage());
        $_SESSION['vend_flash'] = ['err', 'Could not save. Try again.'];
        $_SESSION['vend_form'] = $_POST;
    }
    header('Location: vendors.php' . ($id ? '?id=' . $id : '?new=1')); exit;
}

$flash = $_SESSION['vend_flash'] ?? null; unset($_SESSION['vend_flash']);
$typed = $_SESSION['vend_form'] ?? null; unset($_SESSION['vend_form']);
$editing = isset($_GET['id']) || isset($_GET['new']);

// Hiring-screen styles (admin/views/jobs/*)
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-1.5 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-1.5 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100';
$sel = function (string $name, array $opts, $cur) use ($h, $input) {
    $o = '<select name="' . $h($name) . '" class="' . $input . '" aria-label="Status">';
    foreach ($opts as $k => $v) $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . $h($v) . '</option>';
    return $o . '</select>';
};
// Success shows as the console toast; problems stay on the page next to the form.
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

// ── LIST ──────────────────────────────────────────────────────────────────────
if (!$editing) {
    $f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => in_array($_GET['status'] ?? '', ['active', 'off', 'all'], true) ? $_GET['status'] : 'active'];
    $rows = bcrf_vendor_list($pdo, $f);
    bcrf_shell_open('Vendors', ['toast' => $toast, 'width' => 'max-w-3xl']);
    ?>
  <div class="flex flex-wrap items-end justify-between gap-3 mb-1">
    <h1 class="text-2xl font-bold text-stone-800">Vendors</h1>
    <a href="vendors.php?new=1" class="<?= $btnMain ?>">Add a vendor</a>
  </div>
  <p class="text-sm text-stone-500 mb-4">Feed stores, farm suppliers and thrift sources. A product's preferred vendor is picked from the active ones here.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <form method="GET" action="vendors.php" class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm mb-4 flex flex-wrap gap-2 items-end">
    <div class="flex-1 min-w-[12rem]">
      <label for="vq" class="<?= $label ?>">Search</label>
      <input id="vq" type="search" name="q" value="<?= $h($f['q']) ?>" placeholder="Name, contact, account #" class="<?= $input ?>">
    </div>
    <div class="w-32"><label class="<?= $label ?>" for="vs">Show</label><?= str_replace('<select ', '<select id="vs" ', $sel('status', ['active' => 'Active', 'off' => 'Inactive', 'all' => 'All'], $f['status'])) ?></div>
    <button type="submit" class="<?= $btnSec ?>">Show</button>
  </form>

  <?php if (!$rows): ?>
    <div class="bg-white border border-stone-200 rounded-lg p-6 text-center text-sm text-stone-500">
      <?= $f['q'] !== '' || $f['status'] !== 'active' ? 'No vendors match.' : 'No vendors yet. Add feed stores, farm suppliers, and thrift sources here.' ?>
    </div>
  <?php else: ?>
    <div class="space-y-2">
      <?php foreach ($rows as $r):
          $meta = trim(($r['contact_name'] ?? '') . ' · ' . ($r['phone'] ?? '') . ' · ' . ($r['account_number'] ?? ''), ' ·'); ?>
      <div class="ui-swrow bg-white border border-stone-200 rounded-lg p-3 shadow-sm<?= (int)$r['is_active'] ? '' : ' is-off' ?>" data-switch-row>
      <a href="vendors.php?id=<?= (int)$r['id'] ?>" class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <div class="text-base font-semibold text-stone-800 truncate"><?= $h($r['name']) ?></div>
          <?php if ($meta !== ''): ?><div class="text-xs text-stone-500 mt-0.5 truncate"><?= $h($meta) ?></div><?php endif; ?>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <?php if (!empty($r['payment_terms'])): ?><span class="text-xs text-stone-500"><?= $h($r['payment_terms']) ?></span><?php endif; ?>
        </div>
      </a>
      <?= bcrf_switch(['action' => 'vendors.php', 'csrf' => $csrf, 'fields' => ['act' => 'active', 'id' => (int)$r['id'], 'back' => 'vendors.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '')],
                       'on' => (int)$r['is_active'] === 1, 'label' => $r['name'] . ' active', 'on_text' => 'Active', 'off_text' => 'Off']) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-stone-400 mt-3"><?= count($rows) ?> vendor<?= count($rows) === 1 ? '' : 's' ?>.</p>
  <?php endif;
    bcrf_shell_close();
    exit;
}

// ── EDITOR ────────────────────────────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
$v = $id ? bcrf_vendor_get($pdo, $id) : null;
if ($id && !$v) {
    http_response_code(404);
    bcrf_shell_open('Vendor not found', ['width' => 'max-w-2xl']);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Vendor not found</h1>',
         bcrf_shell_notice('info', "That vendor doesn't exist."),
         '<a href="vendors.php" class="text-sm text-amber-800 underline">All vendors</a>';
    bcrf_shell_close();
    exit;
}
$d = $typed ?: ($v ?: ['name' => '', 'is_active' => 1]);
if ($typed && $v) $d['is_active'] = isset($typed['is_active']) ? 1 : 0;
$val = fn(string $k) => $h($d[$k] ?? '');
$field = function (string $name, string $text, string $type = 'text', int $max = 120, string $extra = '', string $hint = '') use ($val, $input, $label) {
    return '<div><label for="f_' . $name . '" class="' . $label . '">' . $text . ($hint !== '' ? ' <span class="font-normal text-stone-400">' . $hint . '</span>' : '') . '</label>'
         . '<input id="f_' . $name . '" type="' . $type . '" name="' . $name . '" value="' . $val($name) . '" maxlength="' . $max . '" class="' . $input . '"' . $extra . '></div>';
};

bcrf_shell_open($v ? $v['name'] . ' — Vendors' : 'Add a vendor', ['toast' => $toast, 'width' => 'max-w-2xl']);
?>
  <a href="vendors.php" class="inline-block text-sm text-stone-500 hover:text-stone-800 mb-2">← All vendors</a>
  <h1 class="text-2xl font-bold text-stone-800 mb-4"><?= $v ? $h($v['name']) : 'Add a vendor' ?></h1>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <form method="POST" action="vendors.php" class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-3">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="id" value="<?= $id ?: '' ?>">
    <?= $field('name', 'Name', 'text', 120, ' required') ?>
    <?= $field('contact_name', 'Contact name') ?>
    <div class="grid gap-3 sm:grid-cols-2">
      <?= $field('email', 'Email', 'email', 190, ' autocomplete="off"') ?>
      <?= $field('phone', 'Phone', 'text', 40, ' autocomplete="off"') ?>
    </div>
    <div class="grid gap-3 sm:grid-cols-2">
      <?= $field('account_number', 'Account number', 'text', 80, ' autocomplete="off"') ?>
      <?= $field('payment_terms', 'Payment terms', 'text', 80, '', '(e.g. Net 30, COD)') ?>
    </div>
    <div>
      <label for="f_notes" class="<?= $label ?>">Notes</label>
      <textarea id="f_notes" name="notes" rows="3" maxlength="5000" class="<?= $input ?>"><?= $val('notes') ?></textarea>
    </div>
    <label class="flex items-center gap-2 text-sm text-stone-600">
      <input type="checkbox" name="is_active" value="1"<?= !empty($d['is_active']) ? ' checked' : '' ?>>
      Active (shown when picking a preferred vendor on a product)
    </label>
    <div class="flex gap-2 pt-1">
      <button type="submit" class="<?= $btnMain ?>"><?= $v ? 'Save' : 'Add vendor' ?></button>
      <a href="vendors.php" class="<?= $btnSec ?>">Cancel</a>
    </div>
  </form>
<?php
bcrf_shell_close();
