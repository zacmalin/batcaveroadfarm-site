<?php
// admin/thrift.php — thrift intake and list (Farm OS Phase 2, slice 2.3, Nov 9, 2026)
//   GET  thrift.php[?status=&q=&source=]   list
//   GET  thrift.php?new=1                 intake form
//   GET  thrift.php?id=N                  one item
//   POST thrift.php  act=intake
// Permission: edit on inventory. Plain forms, strict CSP.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/thrift.php';
require_once __DIR__ . '/../includes/admin_shell.php';   // shared Farm OS shell (Nov 11, 2026)
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$m = fn($c) => '$' . number_format((float)$c, 2);


if (!bcrf_thrift_ready($pdo)) {
    bcrf_shell_open('Thrift', ['legacy' => true]); echo '<p class="flash err">Run migration 0020 in Settings › Database first.</p>',
         '<p><a href="migrations.php">Database migrations</a></p></div></main></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['thrift_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: thrift.php?new=1'); exit;
    }
    try {
        $id = bcrf_thrift_intake($pdo, $_POST, $uid);
        $_SESSION['thrift_flash'] = ['ok', 'Item taken in. It is on the register as quantity 1.'];
        header('Location: thrift.php?id=' . $id); exit;
    } catch (InvalidArgumentException | BcrfStockError $e) {
        $_SESSION['thrift_flash'] = ['err', $e->getMessage()];
        $_SESSION['thrift_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[thrift] ' . $e->getMessage());
        $_SESSION['thrift_flash'] = ['err', 'Could not save. Try again.'];
        $_SESSION['thrift_form'] = $_POST;
    }
    header('Location: thrift.php?new=1'); exit;
}

$flash = $_SESSION['thrift_flash'] ?? null; unset($_SESSION['thrift_flash']);
$typed = $_SESSION['thrift_form'] ?? null; unset($_SESSION['thrift_form']);
$cats = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_KEY_PAIR);
$locs = [];
foreach (bcrf_locations($pdo) as $l) {
    if ((int)$l['sellable'] || stripos($l['name'], 'thrift') !== false) $locs[(int)$l['id']] = $l['name'];
}
if (!$locs) foreach (bcrf_locations($pdo) as $l) $locs[(int)$l['id']] = $l['name'];
$sel = function (string $name, array $opts, $cur, string $blank = '') use ($h) {
    $o = '<select name="' . $h($name) . '">' . ($blank !== '' ? '<option value="">' . $h($blank) . '</option>' : '');
    foreach ($opts as $k => $v) $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . $h($v) . '</option>';
    return $o . '</select>';
};

bcrf_shell_open('Thrift', ['legacy' => true]);

// Detail
if (!empty($_GET['id']) && empty($_GET['new'])) {
    $p = bcrf_thrift_get($pdo, (int)$_GET['id']);
    if (!$p) {
        echo '<p class="card muted">That thrift item doesn\'t exist. <a href="thrift.php">All thrift</a></p></div></main></body></html>';
        exit;
    }
    echo '<p><a href="thrift.php">← All thrift</a> · <a href="thrift.php?new=1">Intake</a></p>';
    if ($flash) echo bcrf_shell_flash($flash);
    echo '<h1>', $h($p['name']), '</h1>';
    echo '<p class="muted">', $h($p['sku']), ' · ', $h(bcrf_thrift_sources()[$p['thrift_source']] ?? $p['thrift_source']),
         ' · ', $h(bcrf_thrift_statuses()[$p['thrift_status']] ?? $p['thrift_status']),
         ' · ', $m($p['price']), '</p>';
    if (!empty($p['image_url']) && preg_match('#^(https://|/)#', (string)$p['image_url'])) {
        echo '<p><img class="pimg" src="', $h($p['image_url']), '" alt=""></p>';
    }
    echo '<div class="card">';
    echo '<div class="lrow"><span>Condition</span><span class="r">', $h(bcrf_thrift_conditions()[$p['thrift_condition']] ?? $p['thrift_condition'] ?? '—'), '</span></div>';
    echo '<div class="lrow"><span>Category</span><span class="r">', $h($p['category_name'] ?? '—'), '</span></div>';
    echo '<div class="lrow"><span>On hand</span><span class="r">', $h(bcrf_qtxt($p['stock_quantity'])), '</span></div>';
    if ($p['thrift_source'] === 'consigned') {
        echo '<div class="lrow"><span>Consignor</span><span class="r">', $h(trim((string)$p['consignor_name']) ?: '— (set later)'), '</span></div>';
        echo '<div class="lrow"><span>Consignor share</span><span class="r">', $p['consignor_share_bp'] !== null ? $h(($p['consignor_share_bp'] / 100) . '%') : '—', '</span></div>';
        if (!empty($p['settlement_id'])) {
            echo '<div class="lrow"><span>Settlement</span><span class="r"><a href="settlements.php?id=', (int)$p['settlement_id'], '">#', (int)$p['settlement_id'], '</a></span></div>';
        } elseif (($p['thrift_status'] ?? '') === 'sold') {
            echo '<div class="lrow"><span>Settlement</span><span class="r"><a href="settlements.php">Payable</a></span></div>';
        }
    }
    if ($p['avg_cost_cents'] !== null) echo '<div class="lrow"><span>Cost</span><span class="r">', $m(((int)$p['avg_cost_cents']) / 100), '</span></div>';
    if ($p['description']) echo '<p>', $h($p['description']), '</p>';
    echo '</div>';
    echo '<p class="muted">Sell it on the <a href="register.php">register</a> (scan or search ', $h($p['sku']), '). When sold, it leaves the for-sale list automatically.</p>';
    echo '<p><a href="print_label.php?item_id=', (int)$p['id'], '">Print tag</a> · <a href="products.php?id=', (int)$p['id'], '">Product editor</a></p>';
    echo '</div></main></body></html>';
    exit;
}

// Intake form
if (!empty($_GET['new'])) {
    $d = $typed ?: ['source' => 'donated', 'condition' => 'good', 'consignor_share_pct' => '60'];
    echo '<p><a href="thrift.php">← All thrift</a></p><h1>Thrift intake</h1>';
    if ($flash) echo bcrf_shell_flash($flash);
    echo '<form class="card stackf" method="POST" action="thrift.php"><input type="hidden" name="csrf_token" value="', $h($csrf), '">';
    echo '<label>Title<input type="text" name="name" value="', $h($d['name'] ?? ''), '" maxlength="255" required autofocus></label>';
    echo '<label>Source', $sel('source', bcrf_thrift_sources(), $d['source'] ?? 'donated'), '</label>';
    echo '<label>Condition', $sel('condition', bcrf_thrift_conditions(), $d['condition'] ?? 'good'), '</label>';
    echo '<div class="two"><label>Price $<input type="number" name="price" value="', $h($d['price'] ?? ''), '" min="0" step="0.01" inputmode="decimal" required></label>',
         '<label>Location', $sel('location_id', $locs, $d['location_id'] ?? bcrf_thrift_location_id($pdo)), '</label></div>';
    echo '<label>Category', $sel('category_id', $cats, $d['category_id'] ?? '', 'Thrift (default)'), '</label>';
    echo '<label>Cost if owned $<small class="muted">(donated = $0; consigned has no farm cost)</small>',
         '<input type="number" name="cost" value="', $h($d['cost'] ?? ''), '" min="0" step="0.01" inputmode="decimal"></label>';
    echo '<div class="two"><label>Consignor customer id <small class="muted">(optional until settlements)</small>',
         '<input type="number" name="consignor_customer_id" value="', $h($d['consignor_customer_id'] ?? ''), '" min="1" step="1" inputmode="numeric"></label>',
         '<label>Consignor share %<input type="number" name="consignor_share_pct" value="', $h($d['consignor_share_pct'] ?? '60'), '" min="0" max="100" step="1"></label></div>';
    echo '<label>Description<textarea name="description" rows="2" maxlength="5000">', $h($d['description'] ?? ''), '</textarea></label>';
    echo '<label>Photo address <small class="muted">(https://… or /media/…)</small><input type="text" name="image_url" value="', $h($d['image_url'] ?? ''), '" maxlength="255" autocomplete="off"></label>';
    echo '<div class="rbar"><button class="btn primary" type="submit">Take in</button></div></form>';
    echo '<p class="muted">Creates a quantity-1 product (SKU THR-…) on the register. After a consigned item sells, pay the consignor on <a href="settlements.php">Settlements</a>.</p>';
    echo '</div></main></body></html>';
    exit;
}

// List
$f = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => in_array($_GET['status'] ?? '', ['available', 'sold', 'all', 'returned', 'withdrawn'], true) ? $_GET['status'] : 'available',
    'source' => isset(bcrf_thrift_sources()[$_GET['source'] ?? '']) ? $_GET['source'] : null,
];
$rows = bcrf_thrift_list($pdo, $f);
echo '<h1>Thrift</h1>';
if ($flash) echo bcrf_shell_flash($flash);
echo '<form class="rform" method="GET" action="thrift.php"><input type="search" name="q" value="', $h($f['q']), '" placeholder="Title or SKU" aria-label="Search">',
     $sel('status', ['available' => 'For sale', 'sold' => 'Sold', 'all' => 'All', 'returned' => 'Returned', 'withdrawn' => 'Withdrawn'], $f['status']),
     $sel('source', bcrf_thrift_sources(), $f['source'] ?? '', 'All sources'),
     '<button class="btn sm" type="submit">Show</button><a class="btn sm primary" href="thrift.php?new=1">Intake</a></form>';
if (!$rows) echo '<p class="card muted">No thrift items match. <a href="thrift.php?new=1">Take one in</a>.</p>';
else {
    echo '<div class="card hist">';
    foreach ($rows as $r) {
        echo '<a class="lrow cardlink" href="thrift.php?id=', (int)$r['id'], '"><div><b>', $h($r['name']), '</b>',
             ' <small class="muted">', $h($r['sku']), '</small>',
             '<br><small class="muted">', $h(bcrf_thrift_sources()[$r['thrift_source']] ?? ''), ' · ',
             $h(bcrf_thrift_statuses()[$r['thrift_status']] ?? ''), '</small></div>',
             '<div class="r"><b>', $m($r['price']), '</b></div></a>';
    }
    echo '</div><p class="muted">', count($rows), ' item', count($rows) === 1 ? '' : 's', '.</p>';
}
echo '</div></main></body></html>';
