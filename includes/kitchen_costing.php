<?php
// includes/kitchen_costing.php — Farm OS Phase 4 slice 6: recipe and product costing, margin flag (Nov 13, 2026; 09 › Costing;
// migration 0056, admin/kitchen_costing.php).
//   Ingredient cost per gram comes from its linked stock product's moving average cost (inventory.avg_cost_cents, set by
//   receiving) divided by that product's size in grams; if there is none, from a manual cost on the ingredient ("$4.29 for
//   5 lb"). A line made from another recipe costs what that recipe costs per gram.
//   Product cost per unit = recipe cost ÷ units per batch + packaging per unit (jars, lids, labels: kitchen_packaging, each
//   from a stock product's average cost or a manual cost). Margin = (price − cost) ÷ price. Below the alert (site_settings
//   kitchen_margin_alert, default 40%) is flagged on Kitchen › Costing and on the dashboard. Because it reads current
//   average costs, a purchase that raises a cost shows up the next time either page loads.
//   A batch keeps what it cost when it was recorded (kitchen_batches.cost_cents, unit_cost_cents).
// Used by admin/kitchen_costing.php, includes/kitchen_batches.php, admin/views/dashboard.php and tests/kitchen_costing_test.php.
if (!function_exists('bcrf_kitchen_costing_ready')) {

require_once __DIR__ . '/kitchen_versions.php';

/** Migration 0056 has run. */
function bcrf_kitchen_costing_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM kitchen_packaging LIMIT 0"); $pdo->query("SELECT cost_cents FROM ingredients LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/** Margin alert threshold, percent. */
function bcrf_kitchen_margin_alert(PDO $pdo): float {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'kitchen_margin_alert'");
        $st->execute();
        $v = $st->fetchColumn();
        return ($v !== false && is_numeric($v)) ? (float)$v : 40.0;
    } catch (Throwable $e) { return 40.0; }
}

function bcrf_kitchen_margin_alert_save(PDO $pdo, $value): float {
    $v = trim((string)$value);
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 95) throw new BcrfKitchenError('The margin alert must be a percent from 0 to 95.');
    $v = round((float)$v, 1);
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('kitchen_margin_alert', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([(string)$v]);
    return $v;
}

/** "$1.23" from cents (float allowed). */
function bcrf_kitchen_money(?float $cents): string {
    if ($cents === null) return '—';
    return ($cents < 0 ? '−' : '') . '$' . number_format(abs($cents) / 100, 2);
}

/** Dollars text ("4.29", "$4.29") to cents, or null when blank. Throws on nonsense. */
function bcrf_kitchen_cents_in($v, string $what): ?int {
    $v = trim(str_replace(['$', ','], '', (string)$v));
    if ($v === '') return null;
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100000) throw new BcrfKitchenError($what . ' must be an amount in dollars, like 4.29.');
    return (int)round((float)$v * 100);
}

/**
 * Cost per gram (cents) for each ingredient id given. Returns id => ['cpg' => float|null, 'source' => 'stock'|'manual'|'',
 * 'why' => text when unknown, 'stock_name', 'manual' => "$4.29 for 5 lb" or ''].
 */
function bcrf_kitchen_ingredient_costs(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $units = bcrf_kitchen_units($pdo);
    $out = [];
    foreach ($pdo->query("SELECT g.id, g.inventory_id, g.cost_cents, g.cost_qty, g.cost_unit_id, g.density_g_per_ml, g.weight_per_unit_g,
                                 i.name AS stock_name, i.avg_cost_cents, i.size_value, COALESCE(NULLIF(u.abbreviation, ''), u.name, '') AS size_unit
                          FROM ingredients g LEFT JOIN inventory i ON i.id = g.inventory_id LEFT JOIN measurement_units u ON u.id = i.size_unit_id
                          WHERE g.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o = ['cpg' => null, 'source' => '', 'why' => '', 'stock_name' => (string)($r['stock_name'] ?? ''), 'manual' => ''];
        if ($r['cost_cents'] !== null && (float)$r['cost_qty'] > 0) {
            $u = $r['cost_unit_id'] ? ($units[(int)$r['cost_unit_id']] ?? null) : null;
            $o['manual'] = bcrf_kitchen_money((float)$r['cost_cents']) . ' for ' . bcrf_kitchen_qty((float)$r['cost_qty']) . ($u ? ' ' . ($u['abbr'] !== '' ? $u['abbr'] : $u['name']) : '');
        }
        if ($r['inventory_id'] && $r['avg_cost_cents'] !== null) {
            $g = bcrf_kitchen_size_grams($r['size_value'], (string)$r['size_unit']);
            if ($g) { $o['cpg'] = (float)$r['avg_cost_cents'] / $g; $o['source'] = 'stock'; }
        }
        if ($o['cpg'] === null && $o['manual'] !== '') {
            $lg = bcrf_kitchen_line_grams($pdo, (float)$r['cost_qty'], $r['cost_unit_id'] !== null ? (int)$r['cost_unit_id'] : null,
                                          $r['density_g_per_ml'] !== null ? (float)$r['density_g_per_ml'] : null, $r['weight_per_unit_g'] !== null ? (float)$r['weight_per_unit_g'] : null);
            if ($lg['grams']) { $o['cpg'] = (float)$r['cost_cents'] / $lg['grams']; $o['source'] = 'manual'; }
            else $o['why'] = 'The manual cost\'s amount can\'t be turned into grams (' . bcrf_kitchen_problem_text($lg['problem']) . ').';
        }
        if ($o['cpg'] === null && $o['why'] === '') {
            if ($r['inventory_id'] && $r['avg_cost_cents'] === null) $o['why'] = '"' . $o['stock_name'] . '" has no average cost yet (receive it, or enter a manual cost).';
            elseif ($r['inventory_id']) $o['why'] = '"' . $o['stock_name'] . '" has no weight size, so its cost per gram is unknown. Enter a manual cost.';
            else $o['why'] = 'No cost yet. Enter what you pay for it.';
        }
        $out[(int)$r['id']] = $o;
    }
    return $out;
}

/**
 * What a recipe costs, from its current lines. Returns ['lines' => [name, ingredient_id, linked_recipe_id, grams, cpg, source,
 * cost (cents, float|null), why], 'total' (cents, the lines that have a cost), 'total_g', 'missing' (line names), 'complete'].
 * null when the recipe doesn't exist.
 */
function bcrf_kitchen_recipe_cost(PDO $pdo, int $recipeId, array $seen = []): ?array {
    $res = bcrf_kitchen_resolve($pdo, $recipeId);
    if (!$res) return null;
    $seen[$recipeId] = true;
    $costs = bcrf_kitchen_ingredient_costs($pdo, array_column($res['lines'], 'ingredient_id'));
    $lines = []; $total = 0.0; $missing = [];
    foreach ($res['lines'] as $l) {
        $row = ['name' => $l['name'], 'ingredient_id' => $l['ingredient_id'], 'linked_recipe_id' => $l['linked_recipe_id'], 'grams' => $l['grams'],
                'cpg' => null, 'source' => '', 'cost' => null, 'why' => ''];
        if ($l['grams'] === null) {
            $row['why'] = 'No weight for this line: ' . ($l['problem_text'] ?: 'fix it in Recipes › Label check') . '.';
        } elseif ($l['ingredient_id']) {
            $c = $costs[$l['ingredient_id']] ?? null;
            if ($c && $c['cpg'] !== null) { $row['cpg'] = $c['cpg']; $row['source'] = $c['source']; }
            else $row['why'] = $c['why'] ?? 'No cost yet.';
        } elseif ($l['linked_recipe_id']) {
            $sub = isset($seen[$l['linked_recipe_id']]) ? null : bcrf_kitchen_recipe_cost($pdo, (int)$l['linked_recipe_id'], $seen);
            if ($sub && $sub['complete'] && $sub['total_g'] > 0) { $row['cpg'] = $sub['total'] / $sub['total_g']; $row['source'] = 'recipe'; }
            else $row['why'] = 'Its recipe isn\'t fully costed yet.';
        }
        if ($row['cpg'] !== null) { $row['cost'] = $row['cpg'] * (float)$l['grams']; $total += $row['cost']; }
        else $missing[] = $l['name'];
        $lines[] = $row;
    }
    return ['lines' => $lines, 'total' => $total, 'total_g' => (float)$res['total_g'], 'missing' => $missing, 'complete' => !$missing && $lines !== []];
}

/** Packaging items for a product, with each one's cost (cents per item, float|null). */
function bcrf_kitchen_packaging(PDO $pdo, int $inventoryId): array {
    $st = $pdo->prepare("SELECT k.*, i.name AS stock_name, i.avg_cost_cents FROM kitchen_packaging k LEFT JOIN inventory i ON i.id = k.packaging_inventory_id
                         WHERE k.inventory_id = ? ORDER BY k.id");
    $st->execute([$inventoryId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $each = null; $source = '';
        if ($r['packaging_inventory_id'] && $r['avg_cost_cents'] !== null) { $each = (float)$r['avg_cost_cents']; $source = 'stock'; }
        elseif ($r['cost_cents'] !== null) { $each = (float)$r['cost_cents']; $source = 'manual'; }
        $r['each'] = $each; $r['source'] = $source;
        $r['per_unit'] = $each !== null ? $each * (float)$r['qty_per_unit'] : null;
        $r['why'] = $each === null ? ($r['packaging_inventory_id'] ? '"' . $r['stock_name'] . '" has no average cost yet.' : 'No cost.') : '';
        $out[] = $r;
    }
    return $out;
}

/** Add a packaging item. $in: name, packaging_inventory_id, cost (dollars), qty_per_unit. */
function bcrf_kitchen_packaging_add(PDO $pdo, int $inventoryId, array $in): int {
    if (!bcrf_kitchen_product($pdo, $inventoryId)) throw new BcrfKitchenError('That product no longer exists.');
    $pid = (int)($in['packaging_inventory_id'] ?? 0) ?: null;
    $name = trim(mb_substr(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')), 0, 100));
    if ($pid) {
        $st = $pdo->prepare("SELECT name FROM inventory WHERE id = ?");
        $st->execute([$pid]);
        $pn = $st->fetchColumn();
        if ($pn === false) throw new BcrfKitchenError('That stock product no longer exists.');
        if ($pid === $inventoryId) throw new BcrfKitchenError('A product can\'t be its own packaging.');
        if ($name === '') $name = (string)$pn;
    }
    if ($name === '') throw new BcrfKitchenError('Name the packaging (e.g. 8 oz jar), or pick a stock product.');
    $cost = bcrf_kitchen_cents_in($in['cost'] ?? '', 'Cost each');
    if (!$pid && $cost === null) throw new BcrfKitchenError('Enter what one costs, or pick a stock product that has an average cost.');
    $q = trim((string)($in['qty_per_unit'] ?? '1'));
    if ($q === '') $q = '1';
    if (!is_numeric($q) || (float)$q <= 0 || (float)$q > 1000) throw new BcrfKitchenError('How many per unit must be a number above 0.');
    $pdo->prepare("INSERT INTO kitchen_packaging (inventory_id, name, packaging_inventory_id, cost_cents, qty_per_unit, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())")
        ->execute([$inventoryId, $name, $pid, $cost, round((float)$q, 3)]);
    return (int)$pdo->lastInsertId();
}

function bcrf_kitchen_packaging_remove(PDO $pdo, int $id, int $inventoryId): bool {
    $st = $pdo->prepare("DELETE FROM kitchen_packaging WHERE id = ? AND inventory_id = ?");
    $st->execute([$id, $inventoryId]);
    return $st->rowCount() === 1;
}

/** Set (or clear, with a blank price) an ingredient's manual cost: $price for $qty of unit $unitId. */
function bcrf_kitchen_ingredient_cost_save(PDO $pdo, int $ingredientId, $price, $qty, $unitId, ?int $uid): bool {
    $st = $pdo->prepare("SELECT id, density_g_per_ml, weight_per_unit_g FROM ingredients WHERE id = ?");
    $st->execute([$ingredientId]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new BcrfKitchenError('That ingredient no longer exists.');
    $cents = bcrf_kitchen_cents_in($price, 'Price');
    if ($cents === null) {
        $pdo->prepare("UPDATE ingredients SET cost_cents = NULL, cost_qty = NULL, cost_unit_id = NULL, cost_updated_at = UTC_TIMESTAMP(), cost_updated_by = ? WHERE id = ?")->execute([$uid, $ingredientId]);
        return true;
    }
    $q = trim((string)$qty);
    if (!is_numeric($q) || (float)$q <= 0 || (float)$q > 100000) throw new BcrfKitchenError('Say how much that price buys (e.g. 5).');
    $u = (int)$unitId ?: null;
    if ($u && !isset(bcrf_kitchen_units($pdo)[$u])) throw new BcrfKitchenError('Pick a unit from the list.');
    $lg = bcrf_kitchen_line_grams($pdo, (float)$q, $u, $g['density_g_per_ml'] !== null ? (float)$g['density_g_per_ml'] : null, $g['weight_per_unit_g'] !== null ? (float)$g['weight_per_unit_g'] : null);
    if (!$lg['grams']) throw new BcrfKitchenError('That amount can\'t be turned into grams: ' . bcrf_kitchen_problem_text($lg['problem']) . ' Use a weight (lb, oz, g) or fix the ingredient in Kitchen › Ingredients.');
    $pdo->prepare("UPDATE ingredients SET cost_cents = ?, cost_qty = ?, cost_unit_id = ?, cost_updated_at = UTC_TIMESTAMP(), cost_updated_by = ? WHERE id = ?")
        ->execute([$cents, round((float)$q, 3), $u, $uid, $ingredientId]);
    return true;
}

/**
 * A product's cost and margin. Returns ['product', 'recipe' (recipe cost or null), 'packaging', 'units', 'recipe_per_unit',
 * 'pack_per_unit', 'unit_cost' (cents, null if unknown), 'price' (cents), 'margin' (percent or null), 'status', 'why', 'alert'].
 * status: ok | low | incomplete | no_units | no_price | no_recipe.
 */
function bcrf_kitchen_product_cost(PDO $pdo, int $inventoryId, ?float $alert = null): array {
    $p = bcrf_kitchen_product($pdo, $inventoryId);
    if (!$p) throw new BcrfKitchenError('That product no longer exists.');
    $alert = $alert ?? bcrf_kitchen_margin_alert($pdo);
    $rc = $p['recipe_id'] !== null ? bcrf_kitchen_recipe_cost($pdo, (int)$p['recipe_id']) : null;
    $pack = bcrf_kitchen_packaging($pdo, $inventoryId);
    $units = $p['units_per_batch'] !== null ? (float)$p['units_per_batch'] : 0.0;
    $price = $p['price'] !== null ? (int)round((float)$p['price'] * 100) : 0;
    $packUnit = 0.0; $packMissing = [];
    foreach ($pack as $k) { if ($k['per_unit'] === null) $packMissing[] = $k['name']; else $packUnit += $k['per_unit']; }
    $out = ['product' => $p, 'recipe' => $rc, 'packaging' => $pack, 'units' => $units, 'recipe_per_unit' => null, 'pack_per_unit' => $packUnit,
            'unit_cost' => null, 'price' => $price, 'margin' => null, 'status' => 'ok', 'why' => '', 'alert' => $alert];
    if (!$rc) { $out['status'] = 'no_recipe'; $out['why'] = 'Link this product to its recipe in Kitchen › Products.'; return $out; }
    if ($units <= 0) { $out['status'] = 'no_units'; $out['why'] = 'Set how many units one batch makes in Kitchen › Products.'; return $out; }
    $out['recipe_per_unit'] = $rc['total'] / $units;
    if (!$rc['complete'] || $packMissing) {
        $out['status'] = 'incomplete';
        $out['why'] = 'Missing costs: ' . implode(', ', array_slice(array_merge($rc['missing'], $packMissing), 0, 5)) . (count($rc['missing']) + count($packMissing) > 5 ? ', …' : '') . '.';
        return $out;
    }
    $out['unit_cost'] = $out['recipe_per_unit'] + $packUnit;
    if ($price <= 0) { $out['status'] = 'no_price'; $out['why'] = 'The product has no price.'; return $out; }
    $out['margin'] = ($price - $out['unit_cost']) / $price * 100;
    if ($out['margin'] < $alert) { $out['status'] = 'low'; $out['why'] = 'Margin ' . number_format($out['margin'], 1) . '% is below the ' . rtrim(rtrim(number_format($alert, 1), '0'), '.') . '% alert.'; }
    return $out;
}

/** Every linked product's cost, lowest margin first (unknowns after). */
function bcrf_kitchen_cost_overview(PDO $pdo): array {
    $alert = bcrf_kitchen_margin_alert($pdo);
    $out = [];
    foreach ($pdo->query("SELECT inventory_id FROM kitchen_products ORDER BY inventory_id")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        try { $out[] = bcrf_kitchen_product_cost($pdo, (int)$pid, $alert); } catch (BcrfKitchenError $e) {}
    }
    usort($out, fn($a, $b) => [$a['margin'] === null, $a['margin'] ?? 0, $a['product']['name']] <=> [$b['margin'] === null, $b['margin'] ?? 0, $b['product']['name']]);
    return $out;
}

/** What a batch cost: recipe cost × batches + packaging × units. Null unless every cost is known. Returns [total, per unit] in whole cents. */
function bcrf_kitchen_batch_cost(PDO $pdo, int $inventoryId, float $batches, float $units): ?array {
    if (!bcrf_kitchen_costing_ready($pdo) || $units <= 0) return null;
    try { $c = bcrf_kitchen_product_cost($pdo, $inventoryId); } catch (Throwable $e) { return null; }
    if (!$c['recipe'] || !$c['recipe']['complete'] || in_array($c['status'], ['incomplete', 'no_recipe'], true)) return null;
    if (array_filter($c['packaging'], fn($k) => $k['per_unit'] === null)) return null;
    $total = $c['recipe']['total'] * $batches + $c['pack_per_unit'] * $units;
    return [(int)round($total), (int)round($total / $units)];
}

}
