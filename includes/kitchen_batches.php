<?php
// includes/kitchen_batches.php — Farm OS Phase 4 slice 3: production batches and lot codes (Nov 13, 2026; 09 › Production batches;
// migration 0053, admin/kitchen_batches.php).
//   A batch: a linked product (Kitchen › Products) made from its recipe's current saved version, N batches, units made,
//   made-on date, best-by (made-on + the product's shelf life), a lot code (yymmdd-SKU-n, n = that product's nth batch
//   that day), and where the finished goods went (Farmstand by default). Finished goods are added to stock when the
//   product is a stocked item. Ticking "take ingredients out of stock" removes, for each recipe line whose ingredient is
//   linked to a stocked product with a weight size, (grams used ÷ grams in one of that product, to 2 decimals) from the Kitchen (or
//   wherever it is kept). Every ingredient's grams are kept with the batch either way, for tracing.
//   A recipe that changed since its last saved version can't be batched: save a new version first (so labels match).
//   Voiding a batch reverses its stock changes; the batch and its lot code stay on record.
//   Once migration 0054 has run (slice 4), a product also needs an approved label (Kitchen › Labels); the batch keeps its id.
// Used by admin/kitchen_batches.php, admin/kitchen_ingredients.php and tests/kitchen_batches_test.php.
if (!function_exists('bcrf_kitchen_batches_ready')) {

require_once __DIR__ . '/kitchen_versions.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/kitchen_labels.php';

/** Migration 0053 has run. */
function bcrf_kitchen_batches_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM kitchen_batches LIMIT 0"); $pdo->query("SELECT 1 FROM kitchen_batch_ingredients LIMIT 0");
          return (bool)$pdo->query("SHOW COLUMNS FROM ingredients LIKE 'inventory_id'")->fetch(); }
    catch (Throwable $e) { return false; }
}

/** The SKU part of a lot code: letters and digits only, upper case (BCF-004 → BCF004); P{id} when there's no SKU. */
function bcrf_kitchen_lot_part(?string $sku, int $inventoryId): string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$sku) ?? '');
    return $s !== '' ? substr($s, 0, 16) : 'P' . $inventoryId;
}

/** Stocked products an ingredient can be linked to (for taking ingredients out of stock). */
function bcrf_kitchen_stock_products(PDO $pdo): array {
    return $pdo->query("SELECT i.id, i.name, i.sku, i.size_value, COALESCE(NULLIF(u.abbreviation, ''), u.name, '') AS size_unit
                        FROM inventory i LEFT JOIN measurement_units u ON u.id = i.size_unit_id
                        WHERE i.track_inventory = 1 ORDER BY i.name")->fetchAll(PDO::FETCH_ASSOC);
}

/** Link an ingredient to the stocked product it comes out of (null = none). Returns true if it changed. */
function bcrf_kitchen_ingredient_stock_set(PDO $pdo, int $ingredientId, ?int $inventoryId): bool {
    if ($inventoryId) {
        $st = $pdo->prepare("SELECT track_inventory FROM inventory WHERE id = ?");
        $st->execute([$inventoryId]);
        $t = $st->fetchColumn();
        if ($t === false) throw new BcrfKitchenError('That stock product no longer exists.');
        if ((int)$t !== 1) throw new BcrfKitchenError('Pick a product that counts stock.');
    }
    $st = $pdo->prepare("SELECT inventory_id FROM ingredients WHERE id = ?");
    $st->execute([$ingredientId]);
    $old = $st->fetchColumn();
    if ($old === false) throw new BcrfKitchenError('That ingredient no longer exists.');
    if (($old === null ? null : (int)$old) === $inventoryId) return false;
    $pdo->prepare("UPDATE ingredients SET inventory_id = ? WHERE id = ?")->execute([$inventoryId, $ingredientId]);
    return true;
}

/** Location to take an ingredient product from: Kitchen if it has some there, else where it has the most, else the default. */
function bcrf_kitchen_take_location(PDO $pdo, array $levels): int {
    $k = (int)$pdo->query("SELECT id FROM locations WHERE code = 'kitchen' AND archived_at IS NULL LIMIT 1")->fetchColumn();
    if ($k && ($levels[$k] ?? 0) > 0) return $k;
    $best = 0; $bestQ = 0;
    foreach ($levels as $loc => $q) if ($q > $bestQ) { $best = (int)$loc; $bestQ = $q; }
    return $best ?: bcrf_location_default($pdo);
}

/**
 * What recording a batch would do, without doing it.
 * Returns product, version state, blockers (can't record), warnings, units_default, best_by_days, and lines:
 * each ingredient/recipe line with grams for these batches and, where it can be taken out of stock, stock_id, stock_name, qty.
 */
function bcrf_kitchen_batch_plan(PDO $pdo, int $inventoryId, float $batches = 1.0): array {
    $p = bcrf_kitchen_product($pdo, $inventoryId);
    if (!$p) throw new BcrfKitchenError('That product no longer exists.');
    $blockers = []; $warnings = []; $lines = []; $vs = null;
    if ($p['recipe_id'] === null) {
        $blockers[] = 'Link this product to its recipe in Kitchen › Products first.';
    } else {
        $vs = bcrf_kitchen_version_state($pdo, (int)$p['recipe_id']);
        if (!$vs) $blockers[] = 'Its recipe no longer exists.';
        elseif (!$vs['res']['ready']) $blockers[] = 'The recipe isn\'t ready for a label yet (weights or allergens missing). Fix it in Recipes › Label check.';
        elseif (!$vs['latest']) $blockers[] = 'Save the recipe as a version first (Recipes › Label check), so the label matches what was made.';
        elseif (!$vs['current']) $blockers[] = 'The recipe changed since version ' . (int)$vs['latest']['version_no'] . '. Save a new version first (Recipes › Label check), so the label matches what was made.';
    }
    $label = null;
    if (!$blockers && bcrf_kitchen_labels_ready($pdo)) {
        $label = bcrf_kitchen_label_state($pdo, $inventoryId)['approved'];
        if (!$label) $blockers[] = 'This product has no approved label for the recipe as it is now. Draft one and have an Owner approve it in Kitchen › Labels.';
    }
    if (!$blockers) {
        if (!(int)$p['approved_type']) $warnings[] = 'NCDA&CS hasn\'t been confirmed for this product type yet (Kitchen › Products).';
        if ($p['shelf_life_days'] === null) $warnings[] = 'No shelf life on this product, so the batch gets no best-by date.';
        $st = $pdo->prepare("SELECT track_inventory FROM inventory WHERE id = ?");
        $st->execute([$inventoryId]);
        if ((int)$st->fetchColumn() !== 1) $warnings[] = 'This product doesn\'t count stock, so finished jars aren\'t added to stock.';
        $stockIds = [];
        $ingIds = array_values(array_filter(array_map(fn($l) => $l['ingredient_id'], $vs['res']['lines'])));
        if ($ingIds) {
            $in = implode(',', array_map('intval', $ingIds));
            foreach ($pdo->query("SELECT g.id, g.inventory_id, i.name, i.track_inventory, i.size_value, COALESCE(NULLIF(u.abbreviation, ''), u.name, '') AS size_unit
                                  FROM ingredients g LEFT JOIN inventory i ON i.id = g.inventory_id LEFT JOIN measurement_units u ON u.id = i.size_unit_id
                                  WHERE g.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) $stockIds[(int)$r['id']] = $r;
        }
        foreach ($vs['res']['lines'] as $l) {
            $g = $l['grams'] !== null ? round((float)$l['grams'] * $batches, 2) : null;
            $row = ['ingredient_id' => $l['ingredient_id'], 'linked_recipe_id' => $l['linked_recipe_id'], 'name' => $l['name'], 'grams' => $g,
                    'stock_id' => null, 'stock_name' => '', 'qty' => null, 'why_not' => ''];
            $s = $l['ingredient_id'] ? ($stockIds[$l['ingredient_id']] ?? null) : null;
            if ($l['linked_recipe_id']) {
                $row['why_not'] = 'Made from a recipe; its own ingredients aren\'t taken out of stock';
            } elseif (!$s || !$s['inventory_id']) {
                $row['why_not'] = 'Not linked to a stock product';
            } elseif ((int)$s['track_inventory'] !== 1) {
                $row['why_not'] = '"' . $s['name'] . '" doesn\'t count stock';
            } else {
                $each = bcrf_kitchen_size_grams($s['size_value'], (string)$s['size_unit']);
                if (!$each) $row['why_not'] = '"' . $s['name'] . '" has no weight size (e.g. 4 lb) in Products';
                else { $row['stock_id'] = (int)$s['inventory_id']; $row['stock_name'] = (string)$s['name']; $row['qty'] = round($g / $each, 2); }   // 2 decimals: inventory.stock_quantity keeps 2
            }
            $lines[] = $row;
        }
    }
    return ['product' => $p, 'state' => $vs, 'label' => $label, 'blockers' => $blockers, 'warnings' => $warnings, 'lines' => $lines,
            'units_default' => $p['units_per_batch'] !== null ? round((float)$p['units_per_batch'] * $batches, 2) : null];
}

/** Next lot code for a product on a date (call inside the batch transaction). */
function bcrf_kitchen_next_lot(PDO $pdo, string $part, string $madeOn): string {
    $prefix = date('ymd', strtotime($madeOn)) . '-' . $part . '-';
    $st = $pdo->prepare("SELECT lot_code FROM kitchen_batches WHERE lot_code LIKE ? FOR UPDATE");
    $st->execute([str_replace(['%', '_'], ['\%', '\_'], $prefix) . '%']);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) { $tail = substr($c, strlen($prefix)); if (ctype_digit($tail)) $n = max($n, (int)$tail); }
    return $prefix . ($n + 1);
}

/**
 * Record a batch. $in: batches, units_made (blank = batches × units per batch), made_on (Y-m-d, farm date), location_id,
 * take_ingredients (bool), notes. Returns the batch row (bcrf_kitchen_batch()).
 */
function bcrf_kitchen_batch_record(PDO $pdo, int $inventoryId, array $in, ?int $uid): array {
    $batches = bcrf_kitchen_num($in['batches'] ?? '1', 'Batches', 0.01, 1000);
    if ($batches === null) throw new BcrfKitchenError('Say how many batches were made.');
    $plan = bcrf_kitchen_batch_plan($pdo, $inventoryId, $batches);
    if ($plan['blockers']) throw new BcrfKitchenError($plan['blockers'][0]);
    $p = $plan['product'];
    $units = trim((string)($in['units_made'] ?? '')) === '' ? $plan['units_default'] : bcrf_kitchen_num($in['units_made'], 'Units made', 0.01, 1000000);
    if (!$units || $units <= 0) throw new BcrfKitchenError('Say how many units were made.');
    $madeOn = trim((string)($in['made_on'] ?? ''));
    $d = DateTime::createFromFormat('!Y-m-d', $madeOn);
    if (!$d || $d->format('Y-m-d') !== $madeOn) throw new BcrfKitchenError('Pick the day it was made.');
    $today = new DateTime('today');
    if ($d > $today) throw new BcrfKitchenError('The made-on date can\'t be in the future.');
    if ($d < (clone $today)->modify('-60 days')) throw new BcrfKitchenError('The made-on date is more than 60 days ago; check it.');
    $bestBy = $p['shelf_life_days'] !== null ? (clone $d)->modify('+' . (int)$p['shelf_life_days'] . ' days')->format('Y-m-d') : null;
    $locId = (int)($in['location_id'] ?? 0) ?: bcrf_location_default($pdo);
    $loc = bcrf_location($pdo, $locId);
    if (!$loc || $loc['archived_at'] !== null) throw new BcrfKitchenError('Pick where the finished goods went.');
    $take = !empty($in['take_ingredients']);
    $notes = trim(mb_substr(preg_replace('/[ \t]+/', ' ', (string)($in['notes'] ?? '')), 0, 500));
    $version = $plan['state']['latest'];

    // Products whose stock changes, locked in id order (one lock order for everything that moves stock).
    $takeQty = [];
    if ($take) foreach ($plan['lines'] as $l) if ($l['stock_id'] && $l['qty'] > 0) $takeQty[$l['stock_id']] = ($takeQty[$l['stock_id']] ?? 0) + $l['qty'];
    $st = $pdo->prepare("SELECT track_inventory FROM inventory WHERE id = ?");
    $st->execute([$inventoryId]);
    $addStock = (int)$st->fetchColumn() === 1 && bcrf_stock_ready($pdo);
    $lockIds = array_keys($takeQty);
    if ($addStock) $lockIds[] = $inventoryId;
    $lockIds = array_values(array_unique($lockIds));
    sort($lockIds);

    $pdo->beginTransaction();
    try {
        $locked = [];
        foreach ($lockIds as $pid) $locked[$pid] = bcrf_stock_lock($pdo, $pid, $uid);
        $lot = bcrf_kitchen_next_lot($pdo, bcrf_kitchen_lot_part($p['sku'], $inventoryId), $madeOn);
        $pdo->prepare("INSERT INTO kitchen_batches (lot_code, inventory_id, recipe_id, recipe_version_id, batches, units_made, made_on, best_by,
                              location_id, stock_added, ingredients_taken, notes, created_at, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
            ->execute([$lot, $inventoryId, (int)$p['recipe_id'], (int)$version['id'], $batches, $units, $madeOn, $bestBy,
                       $locId, $addStock ? 1 : 0, $take && $takeQty ? 1 : 0, $notes !== '' ? $notes : null, $uid]);
        $bid = (int)$pdo->lastInsertId();
        if ($plan['label']) $pdo->prepare("UPDATE kitchen_batches SET label_id = ? WHERE id = ?")->execute([(int)$plan['label']['id'], $bid]);
        $note = 'Lot ' . $lot;
        if ($addStock) bcrf_stock_move($pdo, $inventoryId, $locId, (float)$units, 'produced', 'kitchen_batch', $bid, $note, $uid, 'strict', $locked[$inventoryId]);
        $takenAt = [];
        foreach ($takeQty as $pid => $q) {
            $from = bcrf_kitchen_take_location($pdo, $locked[$pid]['lv']);
            $r = bcrf_stock_move($pdo, $pid, $from, -$q, 'batch_use', 'kitchen_batch', $bid, $note, $uid, 'clamp', $locked[$pid]);
            $takenAt[$pid] = ['loc' => $from, 'taken' => -$r['change']];
        }
        // Each line's grams; the stock taken is shown on the first line using that product.
        $ins = $pdo->prepare("INSERT INTO kitchen_batch_ingredients (batch_id, ingredient_id, linked_recipe_id, name, grams, inventory_id, qty_taken, location_id)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($plan['lines'] as $l) {
            $sid = $take && $l['stock_id'] && isset($takenAt[$l['stock_id']]) ? $l['stock_id'] : null;
            $q = null; $lid = null;
            if ($sid) { $q = $takenAt[$sid]['taken']; $lid = $takenAt[$sid]['loc']; unset($takenAt[$sid]); }
            $ins->execute([$bid, $l['ingredient_id'], $l['linked_recipe_id'], mb_substr($l['name'], 0, 150), $l['grams'], $q !== null ? $sid : ($l['stock_id'] ?: null), $q, $lid]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return bcrf_kitchen_batch($pdo, $bid);
}

/** Void a batch: take its finished goods back out of stock and put its ingredients back. The record stays. */
function bcrf_kitchen_batch_void(PDO $pdo, int $batchId, string $reason, ?int $uid): array {
    $reason = trim(mb_substr(preg_replace('/\s+/', ' ', $reason), 0, 255));
    if ($reason === '') throw new BcrfKitchenError('Say why the batch is being voided.');
    $b = bcrf_kitchen_batch($pdo, $batchId);
    if (!$b) throw new BcrfKitchenError('That batch no longer exists.');
    if ($b['voided_at'] !== null) throw new BcrfKitchenError('That batch is already voided.');
    $back = [];
    foreach ($b['ingredients'] as $r) if ($r['qty_taken'] !== null && (float)$r['qty_taken'] > 0) $back[] = $r;
    $lockIds = array_map(fn($r) => (int)$r['inventory_id'], $back);
    if ((int)$b['stock_added']) $lockIds[] = (int)$b['inventory_id'];
    $lockIds = array_values(array_unique($lockIds));
    sort($lockIds);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT voided_at FROM kitchen_batches WHERE id = ? FOR UPDATE");
        $st->execute([$batchId]);
        if ($st->fetchColumn() !== null) throw new BcrfKitchenError('That batch is already voided.');
        $locked = [];
        foreach ($lockIds as $pid) $locked[$pid] = bcrf_stock_lock($pdo, $pid, $uid);
        $note = 'Lot ' . $b['lot_code'] . ' voided';
        if ((int)$b['stock_added']) {
            try {
                $r = bcrf_stock_move($pdo, (int)$b['inventory_id'], (int)$b['location_id'], -(float)$b['units_made'], 'batch_void', 'kitchen_batch', $batchId, $note, $uid, 'strict', $locked[(int)$b['inventory_id']]);
                $locked[(int)$b['inventory_id']]['lv'][(int)$b['location_id']] = bcrf_qm($r['after']);
            } catch (BcrfStockError $e) {
                throw new BcrfKitchenError('Some of this batch has already been sold or moved (' . $e->getMessage() . ') Fix the stock on the Stock page instead of voiding.');
            }
        }
        foreach ($back as $r) {
            $pid = (int)$r['inventory_id'];
            $res = bcrf_stock_move($pdo, $pid, (int)$r['location_id'], (float)$r['qty_taken'], 'batch_void', 'kitchen_batch', $batchId, $note, $uid, 'strict', $locked[$pid]);
            $locked[$pid]['lv'][(int)$r['location_id']] = bcrf_qm($res['after']);
        }
        $pdo->prepare("UPDATE kitchen_batches SET voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ?")->execute([$uid, $reason, $batchId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return bcrf_kitchen_batch($pdo, $batchId);
}

/** One batch with its ingredient rows, product, recipe, version and location names. */
function bcrf_kitchen_batch(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT b.*, i.name AS product_name, i.sku, r.title AS recipe_title, v.version_no, l.name AS location_name,
                                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS by_name,
                                TRIM(CONCAT(COALESCE(w.first_name, ''), ' ', COALESCE(w.last_name, ''))) AS voided_by_name
                         FROM kitchen_batches b
                         LEFT JOIN inventory i ON i.id = b.inventory_id
                         LEFT JOIN recipes r ON r.id = b.recipe_id
                         LEFT JOIN recipe_versions v ON v.id = b.recipe_version_id
                         LEFT JOIN locations l ON l.id = b.location_id
                         LEFT JOIN users u ON u.id = b.created_by
                         LEFT JOIN users w ON w.id = b.voided_by
                         WHERE b.id = ?");
    $st->execute([$id]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) return null;
    $st = $pdo->prepare("SELECT bi.*, i.name AS stock_name, l.name AS location_name FROM kitchen_batch_ingredients bi
                         LEFT JOIN inventory i ON i.id = bi.inventory_id LEFT JOIN locations l ON l.id = bi.location_id
                         WHERE bi.batch_id = ? ORDER BY bi.id");
    $st->execute([$id]);
    $b['ingredients'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $b;
}

/** Batches, newest first. $f: q (lot code or product name), inventory_id, limit. */
function bcrf_kitchen_batches(PDO $pdo, array $f = []): array {
    $w = []; $a = [];
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(b.lot_code LIKE ? OR i.name LIKE ?)'; $a[] = '%' . $q . '%'; $a[] = '%' . $q . '%'; }
    if (!empty($f['inventory_id'])) { $w[] = 'b.inventory_id = ?'; $a[] = (int)$f['inventory_id']; }
    $lim = max(1, min(500, (int)($f['limit'] ?? 100)));
    $st = $pdo->prepare("SELECT b.id, b.lot_code, b.inventory_id, b.units_made, b.batches, b.made_on, b.best_by, b.voided_at, i.name AS product_name, l.name AS location_name
                         FROM kitchen_batches b LEFT JOIN inventory i ON i.id = b.inventory_id LEFT JOIN locations l ON l.id = b.location_id"
                       . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY b.made_on DESC, b.id DESC LIMIT $lim");
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Linked products (the ones that can be batched), by name. */
function bcrf_kitchen_batchable(PDO $pdo): array {
    return $pdo->query("SELECT i.id, i.name, i.sku, kp.units_per_batch, r.title AS recipe_title FROM kitchen_products kp
                        JOIN inventory i ON i.id = kp.inventory_id LEFT JOIN recipes r ON r.id = kp.recipe_id ORDER BY i.name")->fetchAll(PDO::FETCH_ASSOC);
}

/** A quantity for people: up to 2 decimals, no trailing zeros. */
function bcrf_kitchen_q($q): string {
    $s = rtrim(rtrim(number_format((float)$q, 2, '.', ''), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

}
