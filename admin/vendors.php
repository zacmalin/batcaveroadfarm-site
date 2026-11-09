<?php
// admin/vendors.php — vendor list and editor (Farm OS Phase 2, slice 2.1, Nov 9, 2026)
// Permission: edit on inventory (same as Products). Plain forms, strict CSP (C-09).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/vendors.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data: https:; connect-src \'self\'; base-uri \'self\'; form-action \'self\'">'
      . '<meta name="robots" content="noindex"><link rel="stylesheet" href="assets/register.css?v=7"><link rel="stylesheet" href="assets/reports.css?v=4">';

if (!bcrf_vendors_ready($pdo)) {
    echo $head, '<title>Vendors</title></head><body class="page"><main class="narrow"><p class="flash err">Run migration 0018 in Settings › Database first.</p><p><a href="migrations.php">Database migrations</a></p></main></body></html>';
    exit;
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
$sel = function (string $name, array $opts, $cur, string $blank = '') use ($h) {
    $o = '<select name="' . $h($name) . '">' . ($blank !== '' ? '<option value="">' . $h($blank) . '</option>' : '');
    foreach ($opts as $k => $v) $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . $h($v) . '</option>';
    return $o . '</select>';
};
$editing = isset($_GET['id']) || isset($_GET['new']);

echo $head, '<title>Vendors</title></head><body class="page"><main class="narrow wide">';
echo '<p><a href="admin.php">← Admin</a> · <a href="products.php">Products</a> · <a href="stock.php">Stock</a></p>';

if (!$editing) {
    $f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => in_array($_GET['status'] ?? '', ['active', 'off', 'all'], true) ? $_GET['status'] : 'active'];
    $rows = bcrf_vendor_list($pdo, $f);
    echo '<h1>Vendors</h1>';
    if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
    echo '<form class="rform" method="GET" action="vendors.php"><input type="search" name="q" value="', $h($f['q']), '" placeholder="Name, contact, account #" aria-label="Search">',
         $sel('status', ['active' => 'Active', 'off' => 'Inactive', 'all' => 'All'], $f['status']),
         '<button class="btn sm" type="submit">Show</button><a class="btn sm primary" href="vendors.php?new=1">Add a vendor</a></form>';
    if (!$rows) echo '<p class="card muted">No vendors yet. Add feed stores, farm suppliers, and thrift sources here.</p>';
    else {
        echo '<div class="card hist">';
        foreach ($rows as $r) {
            echo '<a class="lrow cardlink" href="vendors.php?id=', (int)$r['id'], '"><div><b>', $h($r['name']), '</b>', (int)$r['is_active'] ? '' : ' <small class="off">off</small>',
                 '<br><small class="muted">', $h(trim(($r['contact_name'] ?? '') . ' · ' . ($r['phone'] ?? '') . ' · ' . ($r['account_number'] ?? ''), ' · ')), '</small></div>',
                 '<div class="r"><small class="muted">', $h($r['payment_terms'] ?? ''), '</small></div></a>';
        }
        echo '</div><p class="muted">', count($rows), ' vendor', count($rows) === 1 ? '' : 's', '.</p>';
    }
    echo '</main></body></html>'; exit;
}

$id = (int)($_GET['id'] ?? 0);
$v = $id ? bcrf_vendor_get($pdo, $id) : null;
if ($id && !$v) { http_response_code(404); echo '<p class="card muted">That vendor doesn\'t exist. <a href="vendors.php">All vendors</a></p></main></body></html>'; exit; }
$d = $typed ?: ($v ?: ['name' => '', 'is_active' => 1]);
if ($typed && $v) $d['is_active'] = isset($typed['is_active']) ? 1 : 0;
$val = fn(string $k) => $h($d[$k] ?? '');
$chk = !empty($d['is_active']) ? ' checked' : '';

echo '<p><a href="vendors.php">← All vendors</a></p><h1>', $v ? $h($v['name']) : 'Add a vendor', '</h1>';
if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
echo '<form class="card stackf" method="POST" action="vendors.php"><input type="hidden" name="csrf_token" value="', $h($csrf), '"><input type="hidden" name="id" value="', $id ?: '', '">';
echo '<label>Name<input type="text" name="name" value="', $val('name'), '" maxlength="120" required></label>',
     '<label>Contact name<input type="text" name="contact_name" value="', $val('contact_name'), '" maxlength="120"></label>',
     '<div class="two"><label>Email<input type="email" name="email" value="', $val('email'), '" maxlength="190" autocomplete="off"></label>',
     '<label>Phone<input type="text" name="phone" value="', $val('phone'), '" maxlength="40" autocomplete="off"></label></div>',
     '<div class="two"><label>Account number<input type="text" name="account_number" value="', $val('account_number'), '" maxlength="80" autocomplete="off"></label>',
     '<label>Payment terms <small class="muted">(e.g. Net 30, COD)</small><input type="text" name="payment_terms" value="', $val('payment_terms'), '" maxlength="80"></label></div>',
     '<label>Notes<textarea name="notes" rows="3" maxlength="5000">', $val('notes'), '</textarea></label>',
     '<label class="check"><input type="checkbox" name="is_active" value="1"', $chk, '> Active (shown when picking a preferred vendor on a product)</label>',
     '<div class="rbar"><button class="btn primary" type="submit">', $v ? 'Save' : 'Add vendor', '</button></div></form>';
echo '</main></body></html>';
