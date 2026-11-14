<?php
// admin/products.php — product editor (Farm OS Phase 1, slice 10, Nov 6, 2026; 05 › Products, 06 › Inventory Editor)
// Replaces the Full Editor for products (C-10: the Full Editor stays as an administrator fallback).
//   GET  products.php[?q=&cat=&type=&status=]   the product list
//   GET  products.php?id=N | ?new=1             edit or add a product
//   POST products.php  act=save, id, fields, csrf_token → back to the product
//   POST products.php  act=active, id, on=1|0 → the On sale switch on the list (Nov 14, 2026; JSON when ajax=1)
// Quantities are not edited here (06: never edited directly): new stocked products get a starting quantity; after that use Stock.
// Permission: edit on the inventory table (Permissions Matrix). Plain forms, no script (strict CSP, C-09).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/products.php';
require_once __DIR__ . '/../includes/admin_shell.php';   // shared Farm OS shell (Nov 11, 2026)
require_once __DIR__ . '/../includes/admin_switch.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$money = fn($d) => '$' . number_format((float)$d, 2);
$q3 = fn($q) => bcrf_qtxt($q);
if (!bcrf_sales_ready($pdo)) {
    bcrf_shell_open('Products', ['legacy' => true]); echo '<p class="flash err">Run migration 0009 in Settings › Database first.</p></div></main></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['act'] ?? '') === 'active') {
    // On sale switch on the list. Same token and permission as Save; audited; nothing else about the product changes.
    $id = (int)($_POST['id'] ?? 0);
    $back = (string)($_POST['back'] ?? '');
    $back = preg_match('#^products\.php(\?[A-Za-z0-9_=&%+.\-]*)?$#', $back) ? $back : 'products.php';
    $want = bcrf_switch_wanted();
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $msg = 'Security token expired. Reload the page and try again.';
        if (bcrf_switch_ajax()) bcrf_switch_json(false, $msg);
        $_SESSION['prod_flash'] = ['err', $msg]; header('Location: ' . $back); exit;
    }
    try {
        $r = bcrf_switch_set($pdo, 'inventory', 'is_active', $id, $want);
        if ($r === null) throw new InvalidArgumentException('That product no longer exists.');
        if ($r['was'] !== $want && function_exists('bcrf_event')) bcrf_event('product.updated', 'inventory', $id, ['name' => $r['label']]);
        $msg = $r['label'] . ($want ? ' is on sale.' : ' is off the register and website.');
        if (bcrf_switch_ajax()) bcrf_switch_json(true, $msg, $want);
        $_SESSION['prod_flash'] = ['ok', $msg];
    } catch (Throwable $e) {
        if (!$e instanceof InvalidArgumentException) error_log('[products] active: ' . $e->getMessage());
        $msg = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save. Try again.';
        if (bcrf_switch_ajax()) bcrf_switch_json(false, $msg);
        $_SESSION['prod_flash'] = ['err', $msg];
    }
    header('Location: ' . $back); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int)($_POST['id'] ?? 0) ?: null;
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['prod_flash'] = ['err', 'Security token expired; try again.'];
        header('Location: products.php' . ($id ? '?id=' . $id : '?new=1')); exit;
    }
    try {
        $saved = bcrf_product_save($pdo, $id, $_POST, $uid);
        $_SESSION['prod_flash'] = ['ok', $id ? 'Saved.' : 'Product added.'];
        header('Location: products.php?id=' . $saved); exit;
    } catch (InvalidArgumentException $e) {
        $_SESSION['prod_flash'] = ['err', $e->getMessage()];
        $_SESSION['prod_form'] = $_POST;   // keep what was typed
    } catch (Throwable $e) {
        error_log('[products] save: ' . $e->getMessage());
        $_SESSION['prod_flash'] = ['err', 'Could not save. Try again.'];
        $_SESSION['prod_form'] = $_POST;
    }
    header('Location: products.php' . ($id ? '?id=' . $id : '?new=1')); exit;
}

$flash = $_SESSION['prod_flash'] ?? null; unset($_SESSION['prod_flash']);
$typed = $_SESSION['prod_form'] ?? null; unset($_SESSION['prod_form']);
$cats = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_KEY_PAIR);
$taxes = [];
foreach (bcrf_tax_categories($pdo) as $t) $taxes[$t['id']] = $t['name'] . ' (' . rtrim(rtrim(number_format($t['rate_bp'] / 100, 2, '.', ''), '0'), '.') . '%)';
$units = []; try { $units = $pdo->query("SELECT id, name FROM measurement_units ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}
$sel = function (string $name, array $opts, $cur, string $blank = '', array $attr = []) use ($h) {
    $o = '<select name="' . $h($name) . '"' . implode('', array_map(fn($k, $v) => ' ' . $k . '="' . $h($v) . '"', array_keys($attr), $attr)) . '>' . ($blank !== '' ? '<option value="">' . $h($blank) . '</option>' : '');
    foreach ($opts as $k => $v) $o .= '<option value="' . $h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . $h($v) . '</option>';
    return $o . '</select>';
};
$editing = isset($_GET['id']) || isset($_GET['new']);

bcrf_shell_open('Products', ['legacy' => true]);

if (!$editing) {
    $f = ['q' => trim((string)($_GET['q'] ?? '')), 'category_id' => (int)($_GET['cat'] ?? 0) ?: null,
          'type' => isset(bcrf_product_types()[$_GET['type'] ?? ''] ) || ($_GET['type'] ?? '') === 'admission' ? $_GET['type'] : null,
          'status' => in_array($_GET['status'] ?? '', ['active', 'off', 'all'], true) ? $_GET['status'] : 'active'];
    $rows = bcrf_product_list($pdo, $f);
    echo '<h1>Products</h1>';
    if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
    echo '<form class="rform" method="GET" action="products.php"><input type="search" name="q" value="', $h($f['q']), '" placeholder="Name or SKU (scan)" aria-label="Search">',
         $sel('cat', $cats, $f['category_id'], 'All categories', ['aria-label' => 'Category']),
         $sel('type', bcrf_product_types() + ['admission' => 'Admission'], $f['type'], 'All types', ['aria-label' => 'Type']),
         $sel('status', ['active' => 'On sale', 'off' => 'Turned off', 'all' => 'All'], $f['status'], '', ['aria-label' => 'Status']),
         '<button class="btn sm" type="submit">Show</button><a class="btn sm primary" href="products.php?new=1">Add a product</a></form>';
    if (!$rows) echo '<p class="card muted">No products match.</p>';
    else {
        $backQs = 'products.php' . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . (string)$_SERVER['QUERY_STRING'] : '');
        echo '<div class="card hist">';
        foreach ($rows as $r) {
            $priceTxt = $r['pricing_mode'] === 'open_price' ? 'open price' : $money($r['price']) . ($r['unit'] !== 'each' ? ' / ' . $r['unit'] : '');
            $on = (int)$r['is_active'] === 1;
            echo '<div class="lrow ui-swrow', $on ? '' : ' is-off', '" data-switch-row><a class="cardlink ui-swmain" href="products.php?id=', (int)$r['id'], '"><div><b>', $h($r['name']), '</b>',
                 '<br><small class="muted">', $h($r['cat']), ' · ', $h(bcrf_product_type_label($r['product_type'])), $r['sku'] ? ' · ' . $h($r['sku']) : '',
                 ' · ' . $h($r['tax_name'] ?: 'General tax'), '</small></div><div class="r"><b>', $h($priceTxt), '</b><br><small class="muted">',
                 (int)$r['track_inventory'] ? $h($q3($r['stock_quantity'])) . ' on hand' : 'not stocked', '</small></div></a>',
                 bcrf_switch(['action' => 'products.php', 'csrf' => $csrf, 'fields' => ['act' => 'active', 'id' => (int)$r['id'], 'back' => $backQs],
                              'on' => $on, 'label' => $r['name'] . ' on sale']),
                 '</div>';
        }
        echo '</div><p class="muted">', count($rows), ' product', count($rows) === 1 ? '' : 's', '. Nothing is deleted: flip a product\'s switch off to take it off the register and website.</p>';
    }
    echo '</div></main></body></html>'; exit;
}

$id = (int)($_GET['id'] ?? 0);
$p = $id ? bcrf_product_get($pdo, $id) : null;
if ($id && !$p) { http_response_code(404); echo '<p class="card muted">That product doesn\'t exist. <a href="products.php">All products</a></p></div></main></body></html>'; exit; }
$d = $typed ?: ($p ?: ['name' => '', 'product_type' => 'retail', 'pricing_mode' => 'each', 'unit' => 'each', 'price' => '', 'track_inventory' => 1,
                       'is_active' => 1, 'is_alert_enabled' => 1, 'min_stock_level' => '1', 'category_id' => '', 'tax_category_id' => '']);
if ($typed && $p) { foreach (['track_inventory', 'is_active', 'is_alert_enabled'] as $k) $d[$k] = isset($typed[$k]) ? 1 : 0; }
$isAdm = $p && $p['product_type'] === 'admission';
$chk = fn(string $k) => !empty($d[$k]) ? ' checked' : '';
$val = fn(string $k) => $h($d[$k] ?? '');
$num = fn(string $k) => isset($d[$k]) && $d[$k] !== null && $d[$k] !== '' ? $h(is_numeric($d[$k]) ? rtrim(rtrim(number_format((float)$d[$k], 3, '.', ''), '0'), '.') : $d[$k]) : '';

echo '<p><a href="products.php">← All products</a></p><h1>', $p ? $h($p['name']) : 'Add a product', '</h1>';
if ($flash) echo '<p class="flash', $flash[0] === 'err' ? ' err' : '', '">', $h($flash[1]), '</p>';
echo '<form class="card stackf" method="POST" action="products.php"><input type="hidden" name="csrf_token" value="', $h($csrf), '"><input type="hidden" name="id" value="', $id ?: '', '">';
echo '<label>Name<input type="text" name="name" value="', $val('name'), '" maxlength="255" required></label>';
if ($isAdm) echo '<p class="muted">Admission product: its ticket type, check-ins and capacity come from the Admissions category. Price, tax, name and photo can be changed here.</p><input type="hidden" name="product_type" value="admission">';
elseif ($p && $p['product_type'] === 'gift_card') echo '<p class="muted">Gift card product: staff type the amount on the register, it issues a gift card, and it keeps no stock. Keep its tax category on Exempt (tax is charged when the card is spent). Name and photo can be changed here; to stop selling gift cards on the register, untick On sale.</p><input type="hidden" name="product_type" value="gift_card">';
elseif (!$p || $p['product_type'] !== 'gift_card') echo '<label>Type', $sel('product_type', bcrf_product_types(), $d['product_type'] ?? 'retail'), '</label>';
echo '<label>Category', $sel('category_id', $cats, $d['category_id'] ?? '', '—'), '</label>',
     '<label>SKU / barcode <small class="muted">(blank: one is made, like BCF-012)</small><input type="text" name="sku" value="', $val('sku'), '" maxlength="50" autocomplete="off"></label>';
echo '<fieldset class="grp"><legend>Price</legend>';
if (!$isAdm) echo '<label>How it is priced', $sel('pricing_mode', bcrf_pricing_modes(), $d['pricing_mode'] ?? 'each'), '</label>';
echo '<label>Price <small class="muted">(for weight: the price per lb or oz; for open price: a suggested price, or 0)</small><input type="number" name="price" value="', $num('price'), '" min="0" step="0.01" inputmode="decimal" required></label>';
if (!$isAdm) echo '<label>Unit', $sel('unit', bcrf_product_units(), $d['unit'] ?? 'each'), '</label>';
echo '<label>Tax category', $sel('tax_category_id', $taxes, $d['tax_category_id'] ?? '', 'General (default)'), '</label><p class="muted"><a href="register.php?view=tax">Tax rates</a> are set per category.</p></fieldset>';
require_once __DIR__ . '/../includes/rewards.php';
if (bcrf_rewards_override_ready($pdo)) {   // Roost Rewards points (migration 0017)
    $reCur = array_key_exists('rewards_eligible', $d) && $d['rewards_eligible'] !== null && $d['rewards_eligible'] !== '' && $d['rewards_eligible'] !== 'auto' ? (string)(int)$d['rewards_eligible'] : 'auto';
    $byType = bcrf_rewards_product_earns((string)($d['product_type'] ?? 'retail'), null) ? 'earns points' : 'no points';
    echo '<fieldset class="grp"><legend>Roost Rewards</legend><label>Points', $sel('rewards_eligible', ['auto' => 'Default for its type (' . $byType . ')', '1' => 'Always earns points', '0' => 'Never earns points'], $reCur),
         '</label><p class="muted">Retail, produce, kitchen goods, admission and thrift earn 5 points per dollar by default; passes, gift cards, fees and services don\'t.</p></fieldset>';
}
if (!$isAdm) {
    echo '<fieldset class="grp"><legend>Stock</legend>',
         '<label class="check"><input type="checkbox" name="track_inventory" value="1"', $chk('track_inventory'), '> Count this item\'s stock (retail, produce and kitchen goods)</label>';
    if (!$p || !(int)$p['track_inventory']) {
        echo '<label>Starting stock <small class="muted">(when you turn counting on; goes to the default location)</small><input type="number" name="starting_stock" value="', $h($typed['starting_stock'] ?? ''), '" min="0" step="any" inputmode="decimal"></label>';
    } else {
        echo '<p class="muted">On hand: <b>', $h($q3($p['stock_quantity'])), '</b>. Change it in <a href="stock.php?tab=item&amp;id=', (int)$p['id'], '">Stock</a> (received, spoiled, moved, counted).</p>';
    }
    echo '<label>Minimum <small class="muted">(low-stock alert at or below this)</small><input type="number" name="min_stock_level" value="', $num('min_stock_level'), '" min="0" step="any" inputmode="decimal"></label>',
         '<label class="check"><input type="checkbox" name="is_alert_enabled" value="1"', $chk('is_alert_enabled'), '> Include in the daily low-stock email</label></fieldset>';
}
require_once __DIR__ . '/../includes/vendors.php';
if (bcrf_vendors_ready($pdo)) {
    $vendors = bcrf_vendor_options($pdo);
    $costShow = '';
    if (isset($d['avg_cost_cents']) && $d['avg_cost_cents'] !== null && $d['avg_cost_cents'] !== '') {
        $costShow = rtrim(rtrim(number_format(((int)$d['avg_cost_cents']) / 100, 4, '.', ''), '0'), '.');
    } elseif (isset($typed['avg_cost'])) {
        $costShow = $h($typed['avg_cost']);
    }
    echo '<fieldset class="grp"><legend>Vendor & cost</legend>',
         '<label>Preferred vendor', $sel('preferred_vendor_id', $vendors, $d['preferred_vendor_id'] ?? '', '— none —'), '</label>',
         '<p class="muted"><a href="vendors.php">Manage vendors</a></p>',
         '<label>Vendor SKU / part #<input type="text" name="vendor_sku" value="', $val('vendor_sku'), '" maxlength="80" autocomplete="off"></label>',
         '<label>Pack size <small class="muted">(units per case from this vendor)</small><input type="number" name="pack_size" value="', $num('pack_size'), '" min="0" step="any" inputmode="decimal"></label>',
         '<label>Average cost <small class="muted">(per unit; moving average after you start receiving)</small><input type="number" name="avg_cost" value="', $costShow, '" min="0" step="0.01" inputmode="decimal" placeholder="leave blank to keep"></label>',
         '<p class="muted">Cost is stored in cents and copied onto each sale line for margin reports. Receiving (next slice) updates the average automatically.</p></fieldset>';
}
echo '<fieldset class="grp"><legend>Website and labels</legend>',
     '<label>Description<textarea name="description" rows="3" maxlength="5000">', $val('description'), '</textarea></label>',
     '<label>Photo address <small class="muted">(https://… or /media/…; copy it from the Media vault)</small><input type="text" name="image_url" value="', $val('image_url'), '" maxlength="255" autocomplete="off"></label>';
if (!empty($d['image_url']) && preg_match('#^(https://|/)#', (string)$d['image_url'])) echo '<img class="pimg" src="', $val('image_url'), '" alt="">';
echo '<label>Photo description <small class="muted">(for screen readers)</small><input type="text" name="image_alt" value="', $val('image_alt'), '" maxlength="255"></label>',
     '<div class="two"><label>Size<input type="number" name="size_value" value="', $num('size_value'), '" min="0" step="any" inputmode="decimal"></label><label>Size unit', $sel('size_unit_id', $units, $d['size_unit_id'] ?? '', '—'), '</label></div>',
     '<label>Ingredients <small class="muted">(kitchen goods; labels list them heaviest first)</small><textarea name="ingredients" rows="2" maxlength="5000">', $val('ingredients'), '</textarea></label></fieldset>';
echo '<label class="check"><input type="checkbox" name="is_active" value="1"', $chk('is_active'), '> On sale (shows on the register, and on the website when in stock)</label>',
     '<div class="rbar"><button class="btn primary" type="submit">', $p ? 'Save' : 'Add product', '</button>',
     $p ? '<a class="btn" href="print_label.php?item_id=' . (int)$p['id'] . '">Print label</a>' : '', '</div></form>';
if ($p && in_array(($_SESSION['user_role'] ?? ''), ['administrator'], true)) echo '<p class="muted">Other fields: <a href="admin.php?view=schema&amp;table=inventory">Full editor</a> (administrators).</p>';
echo '</div></main></body></html>';
