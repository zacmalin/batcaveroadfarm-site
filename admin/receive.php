<?php
// admin/receive.php — receive stock with unit cost (Farm OS Phase 2, slice 2.2 + polish, Nov 9, 2026)
//   GET  receive.php[?new=1][&lines=N][&product=ID][&location=ID][&vendor=ID]
//   GET  receive.php?id=N         one receive detail
//   POST receive.php              save a multi-line receive
// Lines accept product dropdown and/or SKU scan (SKU wins when both given).
// Permission: edit on inventory. Plain forms, strict CSP (C-09) — no script.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/receiving.php';
if (is_file(__DIR__ . '/../includes/purchasing.php')) require_once __DIR__ . '/../includes/purchasing.php';
require_once __DIR__ . '/../includes/admin_shell.php';   // shared Farm OS shell (Nov 11, 2026)
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn($c) => '$' . number_format((int)$c / 100, 2);
$q3 = fn($q) => bcrf_qtxt($q);


if (!bcrf_receives_ready($pdo)) {
    bcrf_shell_open('Receive', ['legacy' => true]); echo '<p class="flash err">Run migration 0019 in Settings › Database first (and 0018 + 0015 if needed).</p>',
         '<p><a href="migrations.php">Database migrations</a></p></div></main></body></html>';
    exit;
}

/** Resolve product id from dropdown or SKU/barcode. Returns [id, error|null]. */
function bcrf_receive_resolve_product(PDO $pdo, $pidRaw, $skuRaw): array {
    $pid = (int)$pidRaw;
    $sku = trim((string)$skuRaw);
    if ($sku !== '') {
        $st = $pdo->prepare("SELECT id FROM inventory WHERE sku = ? AND track_inventory = 1 LIMIT 1");
        $st->execute([$sku]);
        $found = (int)$st->fetchColumn();
        if (!$found) return [0, 'No stocked product has SKU "' . $sku . '".'];
        return [$found, null];
    }
    if ($pid > 0) return [$pid, null];
    return [0, null]; // blank line
}

// -- POST --
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['recv_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: receive.php'); exit;
    }
    try {
        $lines = [];
        $pids = (array)($_POST['product_id'] ?? []);
        $skus = (array)($_POST['sku'] ?? []);
        $qtys = (array)($_POST['qty'] ?? []);
        $costs = (array)($_POST['unit_cost'] ?? []);
        $notes = (array)($_POST['line_note'] ?? []);
        $n = max(count($pids), count($skus), count($qtys), count($costs));
        for ($i = 0; $i < $n; $i++) {
            [$pid, $err] = bcrf_receive_resolve_product($pdo, $pids[$i] ?? 0, $skus[$i] ?? '');
            if ($err) throw new InvalidArgumentException($err);
            if ($pid <= 0) continue;
            $lines[] = [
                'product_id' => $pid,
                'qty' => $qtys[$i] ?? '',
                'unit_cost' => $costs[$i] ?? '',
                'note' => $notes[$i] ?? '',
            ];
        }
        $id = bcrf_receive_save($pdo, [
            'vendor_id' => $_POST['vendor_id'] ?? null,
            'location_id' => $_POST['location_id'] ?? null,
            'invoice_ref' => $_POST['invoice_ref'] ?? '',
            'note' => $_POST['note'] ?? '',
            'po_id' => $_POST['po_id'] ?? null,
            'lines' => $lines,
        ], $uid);
        $_SESSION['recv_flash'] = ['ok', 'Received. Stock and average cost updated.'];
        header('Location: receive.php?id=' . $id); exit;
    } catch (InvalidArgumentException | BcrfStockError $e) {
        $_SESSION['recv_flash'] = ['err', $e->getMessage()];
        $_SESSION['recv_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[receive] ' . $e->getMessage());
        $_SESSION['recv_flash'] = ['err', 'Could not save. Try again.'];
        $_SESSION['recv_form'] = $_POST;
    }
    $back = 'receive.php?new=1';
    if (!empty($_POST['_lines'])) $back .= '&lines=' . max(3, min(20, (int)$_POST['_lines']));
    if (!empty($_POST['_product'])) $back .= '&product=' . (int)$_POST['_product'];
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['recv_flash'] ?? null; unset($_SESSION['recv_flash']);
$typed = $_SESSION['recv_form'] ?? null; unset($_SESSION['recv_form']);

$locs = [];
foreach (bcrf_locations($pdo) as $l) $locs[(int)$l['id']] = $l['name'];
$vendors = bcrf_vendor_options($pdo);
$products = $pdo->query("SELECT id, name, sku, unit, avg_cost_cents, preferred_vendor_id
                         FROM inventory WHERE track_inventory = 1 AND is_active = 1 ORDER BY name LIMIT 2000")
    ->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
$prodOpts = ['' => '— pick or scan SKU —'];
foreach ($products as $p) {
    $id = (int)$p['id'];
    $byId[$id] = $p;
    $avg = $p['avg_cost_cents'] !== null ? ' · avg $' . number_format(((int)$p['avg_cost_cents']) / 100, 2) : '';
    $label = $p['name']
        . ($p['sku'] ? ' (' . $p['sku'] . ')' : '')
        . ($p['unit'] !== 'each' ? ' / ' . $p['unit'] : '')
        . $avg;
    $prodOpts[$id] = $label;
}
$sel = function (string $name, array $opts, $cur, string $blank = '') use ($h) {
    $o = '<select name="' . $h($name) . '">' . ($blank !== '' ? '<option value="">' . $h($blank) . '</option>' : '');
    foreach ($opts as $k => $v) {
        if ($k === '' && $blank !== '') continue;
        $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . $h($v) . '</option>';
    }
    return $o . '</select>';
};

bcrf_shell_open('Receive', ['legacy' => true]);

// Detail view
if (!empty($_GET['id']) && empty($_GET['new'])) {
    $r = bcrf_receive_get($pdo, (int)$_GET['id']);
    if (!$r) {
        echo '<p class="card muted">That receive doesn\'t exist. <a href="receive.php">All receives</a></p></div></main></body></html>';
        exit;
    }
    echo '<p><a href="receive.php">← All receives</a> · <a href="receive.php?new=1">New receive</a></p>';
    if ($flash) echo bcrf_shell_flash($flash);
    echo '<h1>Receive #', (int)$r['id'], '</h1>';
    echo '<p class="muted">', $h($r['business_date']), ' · ', $h($r['location_name'] ?? ''),
         $r['vendor_name'] ? ' · ' . $h($r['vendor_name']) : '',
         $r['invoice_ref'] ? ' · inv ' . $h($r['invoice_ref']) : '', '</p>';
    if ($r['note']) echo '<p>', $h($r['note']), '</p>';
    echo '<div class="card hist">';
    $sum = 0;
    foreach ($r['lines'] as $ln) {
        $sum += (int)$ln['line_cost_cents'];
        echo '<div class="lrow"><div><b>', $h($ln['product_name']), '</b>',
             $ln['sku'] ? ' <small class="muted">' . $h($ln['sku']) . '</small>' : '',
             $ln['note'] ? '<br><small class="muted">' . $h($ln['note']) . '</small>' : '',
             '</div><div class="r"><b>', $h($q3($ln['quantity'])), '</b> × ', $m($ln['unit_cost_cents']),
             '<br><small class="muted">', $m($ln['line_cost_cents']), '</small></div></div>';
    }
    echo '<div class="lrow"><b>Total</b><span class="r"><b>', $m($sum), '</b></span></div></div>';
    echo '<p class="muted">Average cost on each product was updated. Stock history shows reason Received.</p>';
    echo '</div></main></body></html>';
    exit;
}

// List + form
echo '<h1>Receive stock</h1>';
if ($flash) echo bcrf_shell_flash($flash);

$preProduct = (int)($_GET['product'] ?? 0);
if ($preProduct && !isset($byId[$preProduct])) $preProduct = 0;
$prePo = (int)($_GET['po'] ?? 0);
$poRow = null;
if ($prePo && function_exists('bcrf_po_get') && function_exists('bcrf_po_ready') && bcrf_po_ready($pdo)) {
    $poRow = bcrf_po_get($pdo, $prePo);
    if ($poRow && in_array($poRow['status'], ['cancelled', 'received'], true)) $poRow = null;
}
$lineCount = max(3, min(20, (int)($_GET['lines'] ?? ($typed ? max(3, count((array)($typed['product_id'] ?? []))) : ($poRow ? max(3, count($poRow['lines'])) : ($preProduct ? 3 : 8))))));
$d = $typed ?: [];
$defLoc = (int)($_GET['location'] ?? 0) ?: bcrf_location_default($pdo);
$defVendor = (int)($_GET['vendor'] ?? 0) ?: null;

// Prefill from PO remaining qty
if (!$typed && $poRow) {
    $defVendor = $defVendor ?: (int)$poRow['vendor_id'];
    $d['product_id'] = [];
    $d['qty'] = [];
    $d['unit_cost'] = [];
    foreach ($poRow['lines'] as $ln) {
        $remain = max(0, (float)$ln['quantity_ordered'] - (float)$ln['quantity_received']);
        if ($remain <= 0) continue;
        $d['product_id'][] = (int)$ln['product_id'];
        $d['qty'][] = bcrf_qtxt($remain);
        $cost = $ln['unit_cost_cents'] ?? $ln['avg_cost_cents'] ?? null;
        $d['unit_cost'][] = $cost !== null ? rtrim(rtrim(number_format(((int)$cost) / 100, 4, '.', ''), '0'), '.') : '';
    }
    if (!$d['product_id']) {
        // nothing left — still show empty form attached to PO
        $d['product_id'] = [];
    }
    $lineCount = max($lineCount, count($d['product_id']) + 1, 3);
}

// Prefill first line from ?product=
if (!$typed && !$poRow && $preProduct) {
    $d['product_id'] = [$preProduct];
    $p = $byId[$preProduct];
    if ($p['avg_cost_cents'] !== null) {
        $d['unit_cost'] = [rtrim(rtrim(number_format(((int)$p['avg_cost_cents']) / 100, 4, '.', ''), '0'), '.')];
    }
    if (!$defVendor && !empty($p['preferred_vendor_id'])) $defVendor = (int)$p['preferred_vendor_id'];
}

echo '<section class="card"><h2>New receive</h2>';
if ($poRow) {
    echo '<p class="muted">Against <a href="purchase_orders.php?id=', (int)$poRow['id'], '">PO #', (int)$poRow['id'], '</a> (', $h($poRow['vendor_name']), '). Remaining quantities are prefilled; adjust if the delivery differs.</p>';
}
echo '<form class="stackf" method="POST" action="receive.php" autocomplete="off">';
echo '<input type="hidden" name="csrf_token" value="', $h($csrf), '">';
echo '<input type="hidden" name="_lines" value="', $lineCount, '">';
if ($preProduct) echo '<input type="hidden" name="_product" value="', $preProduct, '">';
if ($poRow) echo '<input type="hidden" name="po_id" value="', (int)$poRow['id'], '">';

echo '<label>Location', $sel('location_id', $locs, $d['location_id'] ?? $defLoc), '</label>';
echo '<label>Vendor (optional)', $sel('vendor_id', $vendors, $d['vendor_id'] ?? $defVendor, '— none —'), '</label>';
echo '<div class="two"><label>Invoice / packing slip #<input type="text" name="invoice_ref" value="', $h($d['invoice_ref'] ?? ''), '" maxlength="80" autocomplete="off"></label>',
     '<label>Note<input type="text" name="note" value="', $h($d['note'] ?? ''), '" maxlength="255" autocomplete="off"></label></div>';

echo '<fieldset class="grp"><legend>Lines (', $lineCount, ')</legend>';
echo '<p class="muted">Scan or type a <b>SKU</b>, or pick a product. Unit cost is what you paid per unit — the average on the product is shown in the list. Leave a row blank to skip it.</p>';

for ($i = 0; $i < $lineCount; $i++) {
    $pid = $d['product_id'][$i] ?? '';
    $sku = $d['sku'][$i] ?? '';
    $qty = $d['qty'][$i] ?? '';
    $cost = $d['unit_cost'][$i] ?? '';
    $hint = '';
    if ($pid && isset($byId[(int)$pid]) && $byId[(int)$pid]['avg_cost_cents'] !== null && $cost === '') {
        $hint = 'placeholder="' . $h(rtrim(rtrim(number_format(((int)$byId[(int)$pid]['avg_cost_cents']) / 100, 4, '.', ''), '0'), '.')) . '"';
    }
    echo '<div class="card pline">';
    echo '<label>SKU / barcode <small class="muted">(scan here — overrides the product pick)</small>',
         '<input type="text" name="sku[]" value="', $h($sku), '" maxlength="50" autocomplete="off" inputmode="text"></label>';
    echo '<label>Product', $sel('product_id[]', $prodOpts, $pid), '</label>';
    echo '<div class="two"><label>Qty<input type="number" name="qty[]" value="', $h($qty), '" min="0" step="any" inputmode="decimal"></label>',
         '<label>Unit cost $<input type="number" name="unit_cost[]" value="', $h($cost), '" min="0" step="0.01" inputmode="decimal" ', $hint, '></label></div>';
    echo '</div>';
}

echo '<p class="muted">';
if ($lineCount < 20) {
    $more = min(20, $lineCount + 4);
    $q = ['new' => 1, 'lines' => $more];
    if ($preProduct) $q['product'] = $preProduct;
    if ($defVendor) $q['vendor'] = $defVendor;
    echo '<a href="receive.php?' . $h(http_build_query($q)) . '">Add more lines (', $more, ')</a> · ';
}
echo 'Or submit this receive and start another.</p></fieldset>';
echo '<div class="rbar"><button class="btn primary" type="submit">Receive</button></div></form></section>';

$rows = bcrf_receive_list($pdo, [], 30);
echo '<h2>Recent</h2>';
if (!$rows) echo '<p class="card muted">No receives yet.</p>';
else {
    echo '<div class="card hist">';
    foreach ($rows as $r) {
        echo '<a class="lrow cardlink" href="receive.php?id=', (int)$r['id'], '"><div><b>#', (int)$r['id'], '</b> · ',
             $h($r['business_date']), ' · ', $h($r['location_name'] ?? ''),
             $r['vendor_name'] ? ' · ' . $h($r['vendor_name']) : '',
             $r['invoice_ref'] ? ' · ' . $h($r['invoice_ref']) : '',
             '<br><small class="muted">', (int)$r['lines_n'], ' line', (int)$r['lines_n'] === 1 ? '' : 's', '</small></div>',
             '<div class="r"><b>', $m($r['total_cents']), '</b></div></a>';
    }
    echo '</div>';
}
echo '</div></main></body></html>';
