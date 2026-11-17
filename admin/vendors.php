<?php
// admin/vendors.php — vendor list and editor (Farm OS Phase 2, slice 2.1, Nov 9, 2026)
// Permission: edit on inventory (same as Products). Plain forms, strict CSP (C-09).
// Nov 11, 2026: runs inside the shared Farm OS shell (includes/admin_shell.php) with the Hiring-screen look;
// the Products & Inventory tabs replace the old "← Admin · Products · Stock" links.
// Nov 14, 2026: Website and Address boxes (migration 0065), with Call / Open / Map buttons next to Phone, Website and Address.
// Nov 14, 2026 (later): Email button, Papers (insurance certificate, W-9, contracts with an expiry date; includes/documents.php),
// dated notes, linked tasks and a Changes list (includes/record_tools.php).
// Nov 15, 2026: list rows are contact cards (Call with the number, Email, Website, Map buttons; ui-c* in admin-ui.css),
// the editor buttons got the same icons, and phone boxes format as (828) 555-0100 (js/min.phone.js).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/vendors.php';
require_once __DIR__ . '/../includes/admin_shell.php';
require_once __DIR__ . '/../includes/admin_switch.php';   // Active switch on the list (Nov 14, 2026)
require_once __DIR__ . '/../includes/record_tools.php';    // notes, tasks, changes (Nov 14, 2026)
require_once __DIR__ . '/../includes/documents.php';       // papers: insurance certificate, W-9, contracts (Nov 14, 2026)
require_once __DIR__ . '/../includes/list_sort.php';       // Sort box on the list (Nov 15, 2026)
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

// Small line icons for the contact buttons (inline SVG: no extra file, allowed by the strict CSP).
$vIcon = function (string $k): string {
    $p = [
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/>',
        'mail'  => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'web'   => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'map'   => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
    ][$k] ?? '';
    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
};

// ── LIST ──────────────────────────────────────────────────────────────────────
if (!$editing) {
    $f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => in_array($_GET['status'] ?? '', ['active', 'off', 'all'], true) ? $_GET['status'] : 'active'];
    $rows = bcrf_vendor_list($pdo, $f);
    $sortOpts = ['default' => ['Name A to Z'], 'za' => ['Name Z to A', 'name', 'desc'], 'newest' => ['Newest first', 'id', 'desc'], 'oldest' => ['Oldest first', 'id', 'asc']];
    $sort = bcrf_list_sort_pick($pdo, 'vendors', $sortOpts);
    $rows = bcrf_list_sort_rows($rows, $sortOpts, $sort);
    bcrf_shell_open('Vendors', ['toast' => $toast, 'width' => 'max-w-3xl']);
    ?>
  <div class="flex flex-wrap items-end justify-between gap-3 mb-1">
    <h1 class="text-2xl font-bold text-stone-800">Vendors</h1>
    <a href="vendors.php?new=1" class="<?= $btnMain ?>"><span aria-hidden="true">＋</span> Add a vendor</a>
  </div>
  <p class="text-sm text-stone-500 mb-4">Feed stores, farm suppliers and thrift sources. A product's preferred vendor is picked from the active ones here.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>

  <form method="GET" action="vendors.php" class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm mb-4 flex flex-wrap gap-2 items-end">
    <div class="flex-1 min-w-[12rem]">
      <label for="vq" class="<?= $label ?>">Search</label>
      <input id="vq" type="search" name="q" value="<?= $h($f['q']) ?>" placeholder="Name, contact, account #" class="<?= $input ?>">
    </div>
    <div class="w-32"><label class="<?= $label ?>" for="vs">Show</label><?= str_replace('<select ', '<select id="vs" ', $sel('status', ['active' => 'Active', 'off' => 'Inactive', 'all' => 'All'], $f['status'])) ?></div>
    <div class="w-36"><label class="<?= $label ?>" for="vsort">Sort</label><?= bcrf_list_sort_select($sortOpts, $sort, ['id' => 'vsort', 'class' => $input]) ?></div>
    <button type="submit" class="<?= $btnSec ?>"><span aria-hidden="true">✓</span> Apply filters</button>
    <?php if ($f['q'] !== '' || $f['status'] !== 'active' || $sort !== 'default'): ?><a href="vendors.php" class="<?= $btnSec ?>"><span aria-hidden="true">↺</span> Reset</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="bg-white border border-stone-200 rounded-lg p-6 text-center text-sm text-stone-500">
      <?= $f['q'] !== '' || $f['status'] !== 'active' ? 'No vendors match.' : 'No vendors yet. Add feed stores, farm suppliers, and thrift sources here.' ?>
    </div>
  <?php else: ?>
    <div class="space-y-2">
      <?php foreach ($rows as $r):
          $meta = trim(($r['contact_name'] ?? '') . ' · ' . ($r['account_number'] ? 'Acct ' . $r['account_number'] : '') . ' · ' . ($r['payment_terms'] ?? ''), ' ·');
          $btns = [];
          $tel = bcrf_vendor_tel($r['phone'] ?? '');
          if ($tel !== '') $btns[] = ['tel:' . $tel, 'phone', bcrf_vendor_phone_pretty($r['phone']), 'Call ' . $r['name'], false];
          elseif (trim((string)($r['phone'] ?? '')) !== '') $btns[] = ['', 'phone', (string)$r['phone'], '', false];
          if (!empty($r['email']) && filter_var((string)$r['email'], FILTER_VALIDATE_EMAIL)) $btns[] = ['mailto:' . $r['email'], 'mail', (string)$r['email'], 'Email ' . $r['name'], false];
          if (!empty($r['website'])) { try { $u = bcrf_vendor_web_url((string)$r['website']); $btns[] = [$u, 'web', bcrf_vendor_web_label($u), 'Open ' . $r['name'] . ' website in a new tab', true]; } catch (InvalidArgumentException $e) {} }
          if (!empty($r['address'])) $btns[] = [bcrf_vendor_map_url($r['address']), 'map', 'Map', 'Open ' . $r['name'] . ' address in maps', true]; ?>
      <div class="ui-swrow ui-crow bg-white border border-stone-200 rounded-lg p-3 shadow-sm<?= (int)$r['is_active'] ? '' : ' is-off' ?>" data-switch-row>
        <div class="ui-cmain">
          <a href="vendors.php?id=<?= (int)$r['id'] ?>" class="ui-cname">
            <span class="ui-cname-t"><?= $h($r['name']) ?></span>
            <?php if ($meta !== ''): ?><span class="ui-cmeta"><?= $h($meta) ?></span><?php endif; ?>
          </a>
          <?php if ($btns): ?>
          <div class="ui-cbtns">
            <?php foreach ($btns as [$href, $icon, $text, $aria, $newTab]):
                if ($href === '') { echo '<span class="ui-cbtn is-plain">', $vIcon($icon), '<span>', $h($text), '</span></span>'; continue; } ?>
            <a href="<?= $h($href) ?>" class="ui-cbtn" aria-label="<?= $h($aria) ?>"<?= $newTab ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= $vIcon($icon) ?><span><?= $h($text) ?></span></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
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

// Call / Open / Map buttons use the SAVED values, so they always match what's on file (shown once there's something saved).
$hasWeb = bcrf_vendors_has_web($pdo);
$telHref = bcrf_vendor_tel($v['phone'] ?? '');
$webHref = '';
if ($hasWeb && !empty($v['website'])) { try { $webHref = bcrf_vendor_web_url((string)$v['website']); } catch (InvalidArgumentException $e) { $webHref = ''; } }
$mapHref = $hasWeb ? bcrf_vendor_map_url($v['address'] ?? '') : '';
$btnIcon = 'shrink-0 inline-flex items-center justify-center gap-1.5 px-3 rounded text-sm font-semibold border border-stone-300 text-stone-700 bg-white hover:bg-stone-100 ui-cicon';
$withBtn = function (string $fieldHtml, string $href, string $text, string $aria, bool $newTab = false) use ($h, $btnIcon, $vIcon) {
    if ($href === '') return $fieldHtml;
    // Put the button beside the input, under the same label.
    $btn = '<a href="' . $h($href) . '" class="' . $btnIcon . '" aria-label="' . $h(trim($aria)) . '"' . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $vIcon(['Call' => 'phone', 'Email' => 'mail', 'Open' => 'web', 'Map' => 'map'][$text] ?? '') . $h($text) . '</a>';
    $in = strpos($fieldHtml, '<input ');
    $end = strrpos($fieldHtml, '</div>');
    return substr($fieldHtml, 0, $in) . '<div class="flex gap-2 items-stretch">' . substr($fieldHtml, $in, $end - $in) . $btn . '</div></div>';
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
      <?= $withBtn($field('email', 'Email', 'email', 190, ' autocomplete="off"'), (!empty($v['email']) && filter_var((string)$v['email'], FILTER_VALIDATE_EMAIL)) ? 'mailto:' . $v['email'] : '', 'Email', 'Email ' . ($v['name'] ?? '')) ?>
      <?= $withBtn($field('phone', 'Phone', 'tel', 40, ' autocomplete="off"'), $telHref !== '' ? 'tel:' . $telHref : '', 'Call', 'Call ' . ($v['name'] ?? '')) ?>
    </div>
    <?php if ($hasWeb): ?>
    <?= $withBtn($field('website', 'Website', 'text', 255, ' autocomplete="off" inputmode="url" placeholder="tractorsupply.com"'), $webHref, 'Open', 'Open ' . ($v['name'] ?? '') . ' website in a new tab', true) ?>
    <?= $withBtn($field('address', 'Address', 'text', 255, ' autocomplete="off" placeholder="Street, town, state ZIP"'), $mapHref, 'Map', 'Open ' . ($v['name'] ?? '') . ' address in maps', true) ?>
    <?php else: ?>
    <p class="text-xs text-stone-500">Website and Address boxes appear after migration 0065 runs in <a href="/admin/migrations.php" class="text-amber-800 underline">Settings › Database</a>.</p>
    <?php endif; ?>
    <div class="grid gap-3 sm:grid-cols-2">
      <?= $field('account_number', 'Account number', 'text', 80, ' autocomplete="off"') ?>
      <?= $field('payment_terms', 'Payment terms', 'text', 80, '', '(e.g. Net 30, COD)') ?>
    </div>
    <div>
      <label for="f_notes" class="<?= $label ?>">Pinned note <span class="font-normal text-stone-400">always on top. Dated notes go in Notes below.</span></label>
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
<?php if ($v): ?>
  <div class="mt-4"></div>
  <?= bcrf_doc_box_html($pdo, 'vendor', $id, '/admin/vendors.php?id=' . $id, $csrf) ?>
  <?= bcrf_record_notes_box_html($pdo, 'vendor', $id, '/admin/vendors.php?id=' . $id, $csrf) ?>
  <?= bcrf_record_tasks_box_html($pdo, 'vendor', $id) ?>
  <?= bcrf_record_changes_html($pdo, 'vendor', $id) ?>
<?php endif;
bcrf_shell_close();
