<?php
// admin/purchase_orders.php — PO-lite (Farm OS Phase 2, slice 2.4, Nov 9, 2026)
//   GET  purchase_orders.php[?status=&q=&vendor=]
//   GET  purchase_orders.php?new=1[&vendor=]
//   GET  purchase_orders.php?id=N
//   POST act=save | order | cancel
// Permission: edit on inventory. Plain forms, strict CSP.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/purchasing.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn($c) => '$' . number_format((int)$c / 100, 2);
$q3 = fn($q) => bcrf_qtxt($q);

$head = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data: https:; connect-src \'self\'; base-uri \'self\'; form-action \'self\'">'
      . '<meta name="robots" content="noindex"><link rel="stylesheet" href="assets/register.css?v=7"><link rel="stylesheet" href="assets/reports.css?v=4">';

if (!bcrf_po_ready($pdo)) {
    echo $head, '<title>Orders</title></head><body class="page"><main class="narrow"><p class="flash err">Run migration 0021 in Settings › Database first (and 0018 if needed).</p>',
         '<p><a href="migrations.php">Database migrations</a></p></main></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['po_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: purchase_orders.php'); exit;
    }
    $act = (string)($_POST['act'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    try {
        if ($act === 'order' && $id) {
            bcrf_po_set_status($pdo, $id, 'ordered', $uid);
            $_SESSION['po_flash'] = ['ok', 'Marked ordered.'];
            header('Location: purchase_orders.php?id=' . $id); exit;
        }
        if ($act === 'cancel' && $id) {
            bcrf_po_set_status($pdo, $id, 'cancelled', $uid);
            $_SESSION['po_flash'] = ['ok', 'Cancelled.'];
            header('Location: purchase_orders.php?id=' . $id); exit;
        }
        // save
        $lines = [];
        $pids = (array)($_POST['product_id'] ?? []);
        $qtys = (array)($_POST['qty'] ?? []);
        $costs = (array)($_POST['unit_cost'] ?? []);
        $n = max(count($pids), count($qtys), count($costs));
        for ($i = 0; $i < $n; $i++) {
            $pid = (int)($pids[$i] ?? 0);
            if ($pid <= 0) continue;
            $lines[] = ['product_id' => $pid, 'qty' => $qtys[$i] ?? '', 'unit_cost' => $costs[$i] ?? ''];
        }
        $saved = bcrf_po_save($pdo, $id, [
            'vendor_id' => $_POST['vendor_id'] ?? null,
            'po_ref' => $_POST['po_ref'] ?? '',
            'expected_date' => $_POST['expected_date'] ?? '',
            'note' => $_POST['note'] ?? '',
            'lines' => $lines,
        ], $uid);
        $_SESSION['po_flash'] = ['ok', $id ? 'Draft saved.' : 'Draft created.'];
        header('Location: purchase_orders.php?id=' . $saved); exit;
    } catch (InvalidArgumentException | BcrfStockError $e) {
        $_SESSION['po_flash'] = ['err', $e->getMessage()];
        $_SESSION['po_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[po] ' . $e->getMessage());
        $_SESSION['po_flash'] = ['err', 'Could not save. Try again.'];
        $_SESSION['po_form'] = $_POST;
    }
    header('Location: purchase_orders.php' . ($id ? '?id=' . $id : '?new=1')); exit;
}

$flash = $_SESSION['po_flash'] ?? null; unset($_SESSION['po_flash']);
$typed = $_SESSION['po_form'] ?? null; unset($_SESSION['po_form']);
$vendors = bcrf_vendor_options($pdo);
$products = $pdo->query("SELECT id, name, sku, unit, avg_cost_cents FROM inventory WHERE track_inventory = 1 AND is_active = 1 ORDER BY name LIMIT 2000")
    ->fetchAll(PDO::FETCH_ASSOC);
$prodOpts = ['' => '— product —'];
$byId = [];
foreach ($products as $p) {
    $byId[(int)$p['id']] = $p;
    $avg = $p['avg_cost_cents'] !== null ? ' · avg $' . number_format(((int)$p['avg_cost_cents']) / 100, 2) : '';
    $prodOpts[(int)$p['id']] = $p['name'] . ($p['sku'] ? ' (' . $p['sku'] . ')' : '') . $avg;
}
$sel = function (string $name, array $opts, $cur, string $blank = '') use ($h) {
    $o = '<select name="' . $h($name) . '">' . ($blank !== '' ? '<option value="">' . $h($blank) . '</option>' : '');
    foreach ($opts as $k => $v) {
        if ($k === '' && $blank !== '') continue;
        $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . $h($v) . '</option>';
    }
    return $o . '</select>';
};
$statusLabel = bcrf_po_statuses();

echo $head, '<title>Orders</title></head><body class="page"><main class="narrow wide">';
echo '<p><a href="admin.php">← Admin</a> · <a href="receive.php">Receive</a> · <a href="vendors.php">Vendors</a> · <a href="stock.php">Stock</a></p>';

// Detail
if (!empty($_GET['id']) && empty($_GET['new'])) {
    $po = bcrf_po_get($pdo, (int)$_GET['id']);
    if (!$po) {
        echo '<p class="card muted">That order doesn\'t exist. <a href="purchase_orders.php">All orders</a></p></main></body></html>';
        exit;
    }
    echo '<p><a href="purchase_orders.php">← All orders</a> · <a href="purchase_orders.php?new=1">New order</a></p>';
    if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
    echo '<h1>PO #', (int)$po['id'], '</h1>';
    echo '<p class="muted">', $h($statusLabel[$po['status']] ?? $po['status']), ' · ', $h($po['vendor_name']),
         $po['po_ref'] ? ' · ref ' . $h($po['po_ref']) : '',
         $po['expected_date'] ? ' · expect ' . $h($po['expected_date']) : '', '</p>';
    if ($po['note']) echo '<p>', $h($po['note']), '</p>';

    echo '<div class="card hist">';
    $sum = 0;
    foreach ($po['lines'] as $ln) {
        $lineCost = $ln['unit_cost_cents'] !== null ? (int)round((float)$ln['quantity_ordered'] * (int)$ln['unit_cost_cents']) : 0;
        $sum += $lineCost;
        echo '<div class="lrow"><div><b>', $h($ln['product_name']), '</b>',
             $ln['sku'] ? ' <small class="muted">' . $h($ln['sku']) . '</small>' : '',
             '<br><small class="muted">ordered ', $h($q3($ln['quantity_ordered'])),
             ' · received ', $h($q3($ln['quantity_received'])), '</small></div>',
             '<div class="r">', $ln['unit_cost_cents'] !== null ? $m($ln['unit_cost_cents']) . ' ea' : '—',
             $lineCost ? '<br><small class="muted">' . $m($lineCost) . '</small>' : '', '</div></div>';
    }
    if ($sum) echo '<div class="lrow"><b>Expected</b><span class="r"><b>', $m($sum), '</b></span></div>';
    echo '</div>';

    echo '<div class="rbar" style="gap:.5rem;flex-wrap:wrap">';
    if (in_array($po['status'], ['draft', 'ordered', 'partial'], true)) {
        echo '<a class="btn primary" href="receive.php?new=1&po=', (int)$po['id'], '&vendor=', (int)$po['vendor_id'], '">Receive against this</a>';
    }
    if ($po['status'] === 'draft') {
        echo '<form method="POST" action="purchase_orders.php" style="display:inline"><input type="hidden" name="csrf_token" value="', $h($csrf), '">',
             '<input type="hidden" name="id" value="', (int)$po['id'], '"><input type="hidden" name="act" value="order">',
             '<button class="btn" type="submit">Mark ordered</button></form>';
        echo '<a class="btn" href="purchase_orders.php?id=', (int)$po['id'], '&edit=1">Edit draft</a>';
    }
    if (in_array($po['status'], ['draft', 'ordered', 'partial'], true)) {
        echo '<form method="POST" action="purchase_orders.php" style="display:inline">',
             '<input type="hidden" name="csrf_token" value="', $h($csrf), '">',
             '<input type="hidden" name="id" value="', (int)$po['id'], '"><input type="hidden" name="act" value="cancel">',
             '<button class="btn" type="submit">Cancel order</button></form>';
    }
    echo '</div>';

    // Inline edit for draft when ?edit=1
    if ($po['status'] === 'draft' && !empty($_GET['edit'])) {
        $d = $typed ?: [
            'vendor_id' => $po['vendor_id'],
            'po_ref' => $po['po_ref'],
            'expected_date' => $po['expected_date'],
            'note' => $po['note'],
            'product_id' => array_column($po['lines'], 'product_id'),
            'qty' => array_map(fn($l) => bcrf_qtxt($l['quantity_ordered']), $po['lines']),
            'unit_cost' => array_map(fn($l) => $l['unit_cost_cents'] !== null ? rtrim(rtrim(number_format(((int)$l['unit_cost_cents']) / 100, 4, '.', ''), '0'), '.') : '', $po['lines']),
        ];
        $lineCount = max(3, count($d['product_id'] ?? []) + 1, 6);
        echo '<h2>Edit draft</h2><form class="card stackf" method="POST" action="purchase_orders.php">';
        echo '<input type="hidden" name="csrf_token" value="', $h($csrf), '"><input type="hidden" name="id" value="', (int)$po['id'], '"><input type="hidden" name="act" value="save">';
        echo '<label>Vendor', $sel('vendor_id', $vendors, $d['vendor_id'] ?? ''), '</label>';
        echo '<div class="two"><label>Our / vendor ref #<input type="text" name="po_ref" value="', $h($d['po_ref'] ?? ''), '" maxlength="80"></label>',
             '<label>Expected date<input type="date" name="expected_date" value="', $h($d['expected_date'] ?? ''), '"></label></div>';
        echo '<label>Note<input type="text" name="note" value="', $h($d['note'] ?? ''), '" maxlength="255"></label>';
        echo '<fieldset class="grp"><legend>Lines</legend>';
        for ($i = 0; $i < $lineCount; $i++) {
            echo '<div class="card" style="padding:.75rem;margin:0 0 .5rem">';
            echo '<label>Product', $sel('product_id[]', $prodOpts, $d['product_id'][$i] ?? ''), '</label>';
            echo '<div class="two"><label>Qty<input type="number" name="qty[]" value="', $h($d['qty'][$i] ?? ''), '" min="0" step="any" inputmode="decimal"></label>',
                 '<label>Unit cost $<input type="number" name="unit_cost[]" value="', $h($d['unit_cost'][$i] ?? ''), '" min="0" step="0.01" inputmode="decimal"></label></div></div>';
        }
        echo '</fieldset><div class="rbar"><button class="btn primary" type="submit">Save draft</button></div></form>';
    }
    echo '</main></body></html>';
    exit;
}

// New form
if (!empty($_GET['new'])) {
    $d = $typed ?: ['vendor_id' => (int)($_GET['vendor'] ?? 0) ?: '', 'expected_date' => ''];
    $lineCount = max(4, min(20, (int)($_GET['lines'] ?? 6)));
    echo '<p><a href="purchase_orders.php">← All orders</a></p><h1>New purchase order</h1>';
    if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
    if (!$vendors) {
        echo '<p class="card muted">Add a <a href="vendors.php?new=1">vendor</a> first.</p></main></body></html>';
        exit;
    }
    echo '<form class="card stackf" method="POST" action="purchase_orders.php"><input type="hidden" name="csrf_token" value="', $h($csrf), '"><input type="hidden" name="act" value="save">';
    echo '<label>Vendor', $sel('vendor_id', $vendors, $d['vendor_id'] ?? '', '— pick —'), '</label>';
    echo '<div class="two"><label>Our / vendor ref #<input type="text" name="po_ref" value="', $h($d['po_ref'] ?? ''), '" maxlength="80" autocomplete="off"></label>',
         '<label>Expected date<input type="date" name="expected_date" value="', $h($d['expected_date'] ?? ''), '"></label></div>';
    echo '<label>Note<input type="text" name="note" value="', $h($d['note'] ?? ''), '" maxlength="255" autocomplete="off"></label>';
    echo '<fieldset class="grp"><legend>Lines (', $lineCount, ')</legend>';
    echo '<p class="muted">Expected unit cost is optional; it becomes the default on receive.</p>';
    for ($i = 0; $i < $lineCount; $i++) {
        echo '<div class="card" style="padding:.75rem;margin:0 0 .5rem">';
        echo '<label>Product', $sel('product_id[]', $prodOpts, $d['product_id'][$i] ?? ''), '</label>';
        echo '<div class="two"><label>Qty<input type="number" name="qty[]" value="', $h($d['qty'][$i] ?? ''), '" min="0" step="any" inputmode="decimal"></label>',
             '<label>Unit cost $<input type="number" name="unit_cost[]" value="', $h($d['unit_cost'][$i] ?? ''), '" min="0" step="0.01" inputmode="decimal"></label></div></div>';
    }
    echo '</fieldset><div class="rbar"><button class="btn primary" type="submit">Save draft</button></div></form>';
    echo '<p class="muted">After saving, mark ordered when you place it, then receive against it when the delivery arrives.</p>';
    echo '</main></body></html>';
    exit;
}

// List
$f = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => in_array($_GET['status'] ?? '', ['open', 'draft', 'ordered', 'partial', 'received', 'cancelled', 'all'], true) ? $_GET['status'] : 'open',
    'vendor_id' => (int)($_GET['vendor'] ?? 0) ?: null,
];
$rows = bcrf_po_list($pdo, $f);
echo '<h1>Purchase orders</h1>';
if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
echo '<form class="rform" method="GET" action="purchase_orders.php"><input type="search" name="q" value="', $h($f['q']), '" placeholder="Ref, vendor, note" aria-label="Search">',
     $sel('status', ['open' => 'Open', 'draft' => 'Draft', 'ordered' => 'Ordered', 'partial' => 'Partial', 'received' => 'Received', 'cancelled' => 'Cancelled', 'all' => 'All'], $f['status']),
     $sel('vendor', $vendors, $f['vendor_id'] ?? '', 'All vendors'),
     '<button class="btn sm" type="submit">Show</button><a class="btn sm primary" href="purchase_orders.php?new=1">New order</a></form>';
if (!$rows) echo '<p class="card muted">No orders match. <a href="purchase_orders.php?new=1">Create one</a>.</p>';
else {
    echo '<div class="card hist">';
    foreach ($rows as $r) {
        echo '<a class="lrow cardlink" href="purchase_orders.php?id=', (int)$r['id'], '"><div><b>#', (int)$r['id'], '</b> · ',
             $h($r['vendor_name']),
             $r['po_ref'] ? ' · ' . $h($r['po_ref']) : '',
             '<br><small class="muted">', $h($statusLabel[$r['status']] ?? $r['status']),
             $r['expected_date'] ? ' · expect ' . $h($r['expected_date']) : '',
             ' · ', (int)$r['lines_n'], ' line', (int)$r['lines_n'] === 1 ? '' : 's', '</small></div>',
             '<div class="r"><b>', $m($r['ordered_cents']), '</b></div></a>';
    }
    echo '</div>';
}
echo '</main></body></html>';
