<?php
// includes/products.php — the product editor's rules (Farm OS Phase 1, slice 10, Nov 6, 2026; 05 › Products, 06 › Inventory Editor, C-10)
// Products are rows of the existing inventory table (C-07), shared by the register, the stock pages and the website.
//   bcrf_product_list($pdo, $filters)       for the Products list
//   bcrf_product_get($pdo, $id)             one product with its tax category and stock
//   bcrf_product_save($pdo, $id|null, $in)  validate and save; returns the id. Throws InvalidArgumentException with a message.
// Rules:
//   - Type decides behaviour at sale (05): retail, produce, kitchen good (stock); fee and service (no stock). Admission
//     products belong to the Admissions category (0012) and can't be switched to or from here.
//   - Pricing: each, by weight (lb or oz; staff type the weight), or open price (staff type the price).
//   - Stock: a new stocked product (or one switched to stocked) starts with the quantity given, at the default location,
//     as an "opening" row in the stock history. After that, quantities change only through Stock or sales.
//   - The old category column (an ENUM the website used before category_id) is kept in step automatically.
//   - Every save is audited with the fields that changed. Nothing is deleted: turn a product off instead.
if (!function_exists('bcrf_product_save')) {

require_once __DIR__ . '/stock.php';

function bcrf_product_types(): array {
    return ['retail' => 'Retail item', 'produce' => 'Produce', 'kitchen_good' => 'Kitchen good (made here)',
            'fee' => 'Fee (no stock, e.g. extra party guest)', 'service' => 'Service (no stock)'];
}
function bcrf_product_type_label(string $t): string {
    return (bcrf_product_types() + ['admission' => 'Admission', 'pass' => 'Annual pass', 'gift_card' => 'Gift card', 'thrift_item' => 'Thrift item'])[$t] ?? $t;
}
function bcrf_pricing_modes(): array {
    return ['each' => 'A set price each', 'by_weight' => 'By weight (staff type the weight)', 'open_price' => 'Open price (staff type the price)'];
}
function bcrf_product_units(): array { return ['each' => 'each', 'dozen' => 'dozen', 'lb' => 'lb', 'oz' => 'oz']; }

function bcrf_product_list(PDO $pdo, array $f = []): array {
    $w = []; $a = [];
    if (($f['q'] ?? '') !== '') { $w[] = "(i.name LIKE ? OR i.sku = ?)"; $a[] = '%' . $f['q'] . '%'; $a[] = $f['q']; }
    if (!empty($f['category_id'])) { $w[] = "i.category_id = ?"; $a[] = (int)$f['category_id']; }
    if (!empty($f['type'])) { $w[] = "i.product_type = ?"; $a[] = (string)$f['type']; }
    $status = $f['status'] ?? 'active';
    if ($status === 'active') $w[] = "i.is_active = 1";
    if ($status === 'off') $w[] = "COALESCE(i.is_active, 0) = 0";
    $st = $pdo->prepare("SELECT i.id, i.name, i.sku, i.price, i.unit, i.pricing_mode, i.product_type, i.track_inventory, i.stock_quantity, i.min_stock_level,
                                i.is_active, i.image_url, COALESCE(ic.name, i.category, 'Other') AS cat, tc.name AS tax_name, tc.rate_bp
                         FROM inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id LEFT JOIN tax_categories tc ON tc.id = i.tax_category_id"
                         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY COALESCE(ic.sort_order, 999), cat, i.name LIMIT 1000");
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_product_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT i.*, ic.name AS category_name FROM inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id WHERE i.id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The old ENUM category, kept in step with the category chosen (the column is NOT NULL). */
function bcrf_product_legacy_category(PDO $pdo, ?int $categoryId, ?string $current): string {
    $enum = ['Produce', 'Bakery', 'Pantry', 'Zoo', 'Merchandise'];
    if ($categoryId) {
        $st = $pdo->prepare("SELECT name, slug FROM inventory_categories WHERE id = ?");
        $st->execute([$categoryId]);
        if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach ($enum as $e) if (strcasecmp($e, (string)$c['name']) === 0 || strcasecmp($e, (string)$c['slug']) === 0) return $e;
            if (in_array(strtolower((string)$c['slug']), ['admissions', 'admission'], true)) return 'Zoo';
        }
    }
    return in_array($current, $enum, true) ? $current : 'Merchandise';
}

function bcrf_product_next_sku(PDO $pdo): string {
    $n = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(sku, 5) AS UNSIGNED)), 0) FROM inventory WHERE sku REGEXP '^BCF-[0-9]+$'")->fetchColumn();
    return 'BCF-' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
}

/**
 * Save a product. $in keys: name, description, ingredients, image_url, image_alt, product_type, category_id, sku, price (dollars),
 * pricing_mode, unit, tax_category_id, track_inventory, starting_stock, min_stock_level, is_alert_enabled, size_value, size_unit_id, is_active.
 */
function bcrf_product_save(PDO $pdo, ?int $id, array $in, ?int $uid): int {
    $bad = fn(string $m) => throw new InvalidArgumentException($m);
    $old = $id ? bcrf_product_get($pdo, $id) : null;
    if ($id && !$old) $bad('That product no longer exists.');
    $str = fn(string $k, int $max) => mb_substr(trim(str_replace("\r", '', (string)($in[$k] ?? ''))), 0, $max);
    $num = function (string $k, bool $nullable = true) use ($in, $bad) {
        $s = trim(str_replace([',', '$'], '', (string)($in[$k] ?? '')));
        if ($s === '') return $nullable ? null : 0.0;
        if (!is_numeric($s)) $bad('Check the number in "' . str_replace('_', ' ', $k) . '".');
        return round((float)$s, 3);
    };

    $v = [];
    $v['name'] = $str('name', 255);
    if ($v['name'] === '') $bad('Give the product a name.');
    $v['description'] = $str('description', 5000) ?: null;
    $v['ingredients'] = $str('ingredients', 5000) ?: null;
    $v['image_url'] = $str('image_url', 255) ?: null;
    if ($v['image_url'] !== null && !preg_match('#^(https://|/)[^\s"<>]+$#', $v['image_url'])) $bad('The photo must be a web address starting with https:// or a site path starting with /.');
    $v['image_alt'] = $str('image_alt', 255) ?: null;

    $isAdm = $old && $old['product_type'] === 'admission';
    $isGift = $old && $old['product_type'] === 'gift_card';   // gift cards (Nov 11): type, pricing and stock are fixed
    $type = (string)($in['product_type'] ?? 'retail');
    if ($isAdm) $type = 'admission';
    elseif ($isGift) $type = 'gift_card';
    elseif (!isset(bcrf_product_types()[$type])) $bad('Pick a product type.');
    $v['product_type'] = $type;

    $cat = (int)($in['category_id'] ?? 0) ?: null;
    if ($cat) { $st = $pdo->prepare("SELECT slug FROM inventory_categories WHERE id = ?"); $st->execute([$cat]); $slug = $st->fetchColumn(); if ($slug === false) $bad('Pick a category.'); }
    if (!$isAdm && $cat && in_array(strtolower((string)$slug), ['admissions', 'admission'], true)) $bad('Admission products are set up with ticket types; pick another category.');
    $v['category_id'] = $cat;
    $v['category'] = bcrf_product_legacy_category($pdo, $cat, $old['category'] ?? null);

    $sku = $str('sku', 50);
    if ($sku !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $sku)) $bad('The SKU can only have letters, numbers, dots, dashes and underscores.');
    $v['sku'] = $sku;

    $price = $num('price', false);
    if ($price < 0 || $price > 100000) $bad('Enter a price between $0 and $100,000.');
    $v['price'] = round($price, 2);

    $mode = (string)($in['pricing_mode'] ?? 'each');
    if (!isset(bcrf_pricing_modes()[$mode])) $bad('Pick how it is priced.');
    $unit = (string)($in['unit'] ?? 'each');
    if (!isset(bcrf_product_units()[$unit])) $bad('Pick a unit.');
    if ($mode === 'by_weight' && !in_array($unit, ['lb', 'oz'], true)) $bad('Priced by weight needs the unit lb or oz.');
    if ($mode !== 'by_weight' && in_array($unit, ['lb', 'oz'], true)) $bad('lb and oz are for items priced by weight.');
    if ($mode === 'open_price') $unit = 'each';
    if ($isAdm) { $mode = 'each'; $unit = 'each'; }
    if ($isGift) { $mode = 'open_price'; $unit = 'each'; $v['price'] = 0.0; }
    $v['pricing_mode'] = $mode; $v['unit'] = $unit;

    $tax = (int)($in['tax_category_id'] ?? 0) ?: null;
    if ($tax) { $st = $pdo->prepare("SELECT COUNT(*) FROM tax_categories WHERE id = ?"); $st->execute([$tax]); if (!(int)$st->fetchColumn()) $bad('Pick a tax category.'); }
    $v['tax_category_id'] = $tax;

    $track = !empty($in['track_inventory']) && in_array($type, ['retail', 'produce', 'kitchen_good'], true);
    $v['track_inventory'] = $track ? 1 : 0;
    $min = $num('min_stock_level');
    if ($min !== null && ($min < 0 || $min > 1000000)) $bad('The minimum must be 0 or more.');
    $v['min_stock_level'] = $min;
    $v['is_alert_enabled'] = !empty($in['is_alert_enabled']) ? 1 : 0;
    $size = $num('size_value');
    if ($size !== null && ($size <= 0 || $size > 100000)) $bad('The size must be above 0.');
    $v['size_value'] = $size;
    $su = (int)($in['size_unit_id'] ?? 0) ?: null;
    if ($su) { $st = $pdo->prepare("SELECT COUNT(*) FROM measurement_units WHERE id = ?"); $st->execute([$su]); if (!(int)$st->fetchColumn()) $su = null; }
    $v['size_unit_id'] = $size === null ? null : $su;
    $v['is_active'] = !empty($in['is_active']) ? 1 : 0;
    // Roost Rewards points: 'auto' = by product type, '1' = always earns, '0' = never earns (migration 0017)
    require_once __DIR__ . '/rewards.php';
    if (bcrf_rewards_override_ready($pdo)) {
        $re = (string)($in['rewards_eligible'] ?? 'auto');
        $v['rewards_eligible'] = $isGift ? null : ($re === '1' ? 1 : ($re === '0' ? 0 : null));   // gift cards never earn points
    }
    // Vendors & cost (Phase 2 slice 2.1 / migration 0018)
    require_once __DIR__ . '/vendors.php';
    $costDollars = null;
    if (bcrf_vendors_ready($pdo)) {
        $vid = (int)($in['preferred_vendor_id'] ?? 0) ?: null;
        if ($vid) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM vendors WHERE id = ? AND archived_at IS NULL');
            $st->execute([$vid]);
            if (!(int)$st->fetchColumn()) $bad('Pick a vendor from the list.');
        }
        $v['preferred_vendor_id'] = $vid;
        $vSku = $str('vendor_sku', 80);
        $v['vendor_sku'] = $vSku !== '' ? $vSku : null;
        $pack = $num('pack_size');
        if ($pack !== null && ($pack <= 0 || $pack > 1000000)) $bad('Pack size must be above 0.');
        $v['pack_size'] = $pack;
        $costDollars = $num('avg_cost');
        if ($costDollars !== null && ($costDollars < 0 || $costDollars > 1000000)) $bad('Cost must be between $0 and $1,000,000.');
    }
    $start = $num('starting_stock');
    if ($start !== null && ($start < 0 || $start > 1000000)) $bad('Starting stock must be 0 or more.');

    $stockReady = bcrf_stock_ready($pdo);
    $pdo->beginTransaction();
    try {
        if ($v['sku'] === '') $v['sku'] = $old && $old['sku'] ? $old['sku'] : bcrf_product_next_sku($pdo);
        $st = $pdo->prepare("SELECT id FROM inventory WHERE sku = ? AND id <> ?");
        $st->execute([$v['sku'], (int)$id]);
        if ($other = $st->fetchColumn()) $bad('SKU ' . $v['sku'] . ' is already used by another product.');
        $cols = array_keys($v);
        if ($old) {
            $pdo->prepare("SELECT id FROM inventory WHERE id = ? FOR UPDATE")->execute([$id]);
            $pdo->prepare("UPDATE inventory SET " . implode(', ', array_map(fn($c) => "`$c` = ?", $cols)) . " WHERE id = ?")->execute(array_merge(array_values($v), [$id]));
        } else {
            $pdo->prepare("INSERT INTO inventory (" . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ", stock_quantity) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ", 0)")
                ->execute(array_values($v));
            $id = (int)$pdo->lastInsertId();
        }
        // Starting stock: new stocked product, or switched to stocked with no stock history at any location.
        $wasTracked = $old && (int)$old['track_inventory'] === 1;
        if ($track && !$wasTracked) {
            $hasLevels = false;
            if ($stockReady) { $st = $pdo->prepare("SELECT COUNT(*) FROM inventory_levels WHERE product_id = ?"); $st->execute([$id]); $hasLevels = (int)$st->fetchColumn() > 0; }
            if ($hasLevels) {
                $pdo->prepare("UPDATE inventory SET stock_quantity = (SELECT COALESCE(SUM(quantity), 0) FROM inventory_levels WHERE product_id = ?) WHERE id = ?")->execute([$id, $id]);
            } else {
                $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?")->execute([(float)($start ?? 0), $id]);
                if ($stockReady) bcrf_stock_lock($pdo, $id, $uid);   // writes the opening balance at the default location
            }
        }
        if (bcrf_vendors_ready($pdo) && $costDollars !== null) {
            bcrf_product_set_cost($pdo, $id, (int)round($costDollars * 100), $uid, 'manual', null, 'Set on product editor');
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        $before = []; $after = [];
        foreach ($v as $k => $val) {
            $o = $old[$k] ?? null;
            if (!$old || (string)$o !== (string)$val && !(is_numeric($o) && is_numeric($val) && (float)$o == (float)$val)) { $before[$k] = $o; $after[$k] = $val; }
        }
        if (!$old && $track) $after['starting_stock'] = (float)($start ?? 0);
        if ($after) {
            [$t, $aid, $name, $ip] = bcrf_audit_actor();
            bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => $old ? 'update' : 'create',
                'object_type' => 'inventory', 'object_id' => (string)$id, 'object_label' => $v['name'] . (isset($after['price']) && $old ? ' (price $' . number_format((float)$old['price'], 2) . ' -> $' . number_format($v['price'], 2) . ')' : ''),
                'before' => $old ? $before : null, 'after' => $after]);
        }
    }
    if (function_exists('bcrf_event')) bcrf_event($old ? 'product.updated' : 'product.created', 'inventory', $id, ['name' => $v['name']]);
    return $id;
}

}
