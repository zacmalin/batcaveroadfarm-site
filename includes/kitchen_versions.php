<?php
// includes/kitchen_versions.php — Farm OS Phase 4 slice 2 (Nov 12, 2026; 09 › Recipes (versions), Recipe → product; migration 0051).
// Recipe versions: a saved copy of what a recipe's label says (lines with weights, ingredient list, Contains line).
// Recipes are still edited in the Full editor, and ingredient details on Label details, so instead of hooking every
// editor, each version stores a fingerprint of everything a label depends on. If the recipe now resolves to a
// different fingerprint, it has "changed since version N": the next label or batch needs a new version (and, in slice 4,
// a new approved label). Saving a version when nothing changed returns the latest one.
// Recipe → product: kitchen_products links a sellable product (inventory) to the recipe that makes it, with units per
// batch, shelf life (best-by) and the NCDA&CS "approved product type" flag. Net quantity is the product's size.
// Used by admin/kitchen_recipes.php, admin/kitchen_products.php and tests/kitchen_versions_test.php.
if (!function_exists('bcrf_kitchen_versions_ready')) {

require_once __DIR__ . '/kitchen.php';

/** Migration 0051 has run. */
function bcrf_kitchen_versions_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM recipe_versions LIMIT 0"); $pdo->query("SELECT 1 FROM kitchen_products LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/** What a label depends on, as a stable array (for the fingerprint and the snapshot). */
function bcrf_kitchen_label_basis(array $res): array {
    // Lines in a fixed order, so entering the same lines in another order isn't a change.
    $lines = array_map(fn($l) => [$l['type'], $l['type'] === 'recipe' ? $l['linked_recipe_id'] : $l['ingredient_id'], round((float)$l['qty'], 4), $l['unit_id']], $res['lines']);
    sort($lines);
    return [
        'lines' => $lines,
        'items' => array_map(fn($i) => [$i['name'], round((float)$i['grams']), $i['allergens'], $i['sub_ingredients']], $res['items']),
        'statement' => $res['statement'],
        'contains' => $res['contains'],
    ];
}

function bcrf_kitchen_fingerprint(array $res): string {
    return hash('sha256', json_encode(bcrf_kitchen_label_basis($res), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

/** The snapshot stored with a version (enough to show it later without the recipe). */
function bcrf_kitchen_snapshot(array $res): array {
    return [
        'title' => $res['recipe']['title'],
        'lines' => array_map(fn($l) => ['type' => $l['type'], 'id' => $l['type'] === 'recipe' ? $l['linked_recipe_id'] : $l['ingredient_id'],
                                        'name' => $l['name'], 'qty' => (float)$l['qty'], 'unit' => $l['unit'], 'unit_id' => $l['unit_id'],
                                        'group' => $l['group'], 'grams' => $l['grams'] === null ? null : round((float)$l['grams'], 2)], $res['lines']),
        'items' => array_map(fn($i) => ['name' => $i['name'], 'type' => $i['type'], 'id' => $i['id'], 'grams' => round((float)$i['grams'], 2), 'pct' => $i['pct'],
                                        'allergens' => $i['allergens'], 'sub_ingredients' => $i['sub_ingredients']], $res['items']),
        'total_g' => $res['total_g'],
    ];
}

/** True when a saved snapshot's ingredients declare sulfites (read from the stored allergen strings, so versions
 *  saved before sulfites existed read correctly and nothing needs a migration). */
function bcrf_kitchen_snapshot_sulfites(array $snapshot): bool {
    foreach ($snapshot['items'] ?? [] as $i) if (bcrf_allergens_has_sulfites($i['allergens'] ?? null)) return true;
    return false;
}

/** Every version of a recipe, newest first, with who saved it. */
function bcrf_kitchen_versions(PDO $pdo, int $recipeId): array {
    $st = $pdo->prepare("SELECT v.id, v.recipe_id, v.version_no, v.fingerprint, v.statement, v.contains_line, v.total_g, v.note, v.created_at, v.created_by,
                                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS by_name
                         FROM recipe_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.recipe_id = ? ORDER BY v.version_no DESC");
    $st->execute([$recipeId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** One version with its snapshot decoded. */
function bcrf_kitchen_version(PDO $pdo, int $versionId): ?array {
    $st = $pdo->prepare("SELECT v.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS by_name
                         FROM recipe_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.id = ?");
    $st->execute([$versionId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) return null;
    $v['snapshot'] = json_decode((string)$v['snapshot'], true) ?: [];
    return $v;
}

/**
 * Where a recipe stands against its versions.
 * Returns ['res' => resolved recipe, 'fingerprint', 'latest' => newest version row or null, 'current' => bool (newest matches),
 *          'changes' => what changed since the newest version (readable lines; empty when current or none saved)]
 * or null when the recipe doesn't exist.
 */
function bcrf_kitchen_version_state(PDO $pdo, int $recipeId): ?array {
    $res = bcrf_kitchen_resolve($pdo, $recipeId);
    if (!$res) return null;
    $fp = bcrf_kitchen_fingerprint($res);
    $st = $pdo->prepare("SELECT id FROM recipe_versions WHERE recipe_id = ? ORDER BY version_no DESC LIMIT 1");
    $st->execute([$recipeId]);
    $lid = (int)$st->fetchColumn();
    $latest = $lid ? bcrf_kitchen_version($pdo, $lid) : null;
    $current = $latest && hash_equals((string)$latest['fingerprint'], $fp);
    $changes = ($latest && !$current) ? bcrf_kitchen_version_diff($latest['snapshot'], bcrf_kitchen_snapshot($res), (string)$latest['contains_line'], $res['contains']) : [];
    return ['res' => $res, 'fingerprint' => $fp, 'latest' => $latest, 'current' => $current, 'changes' => $changes];
}

/** Readable differences between two snapshots: lines added, removed or changed; order, ingredient details, allergens. */
function bcrf_kitchen_version_diff(array $old, array $new, string $oldContains, string $newContains): array {
    $amount = function (array $lines): array {
        $o = [];
        foreach ($lines as $l) {
            $k = $l['type'] . ':' . $l['id'];
            $o[$k]['name'] = $l['name'];
            $o[$k]['text'][] = bcrf_kitchen_qty((float)$l['qty']) . ($l['unit'] !== '' ? ' ' . $l['unit'] : '');
        }
        foreach ($o as &$x) $x['text'] = implode(' + ', $x['text']);
        return $o;
    };
    $a = $amount($old['lines'] ?? []);
    $b = $amount($new['lines'] ?? []);
    $out = [];
    foreach ($b as $k => $x) {
        if (!isset($a[$k])) $out[] = 'Added ' . $x['text'] . ' ' . $x['name'];
        elseif ($a[$k]['text'] !== $x['text']) $out[] = $x['name'] . ': ' . $a[$k]['text'] . ' → ' . $x['text'];
    }
    foreach ($a as $k => $x) if (!isset($b[$k])) $out[] = 'Removed ' . $x['name'];
    $oi = array_column($old['items'] ?? [], null, 'name');
    $ni = array_column($new['items'] ?? [], null, 'name');
    if (array_keys($oi) !== array_keys($ni) && array_diff(array_keys($oi), array_keys($ni)) === [] && array_diff(array_keys($ni), array_keys($oi)) === []) {
        $out[] = 'Ingredient order changed';
    }
    foreach ($ni as $name => $i) {
        if (!isset($oi[$name])) continue;
        if ((string)$oi[$name]['sub_ingredients'] !== (string)$i['sub_ingredients']) $out[] = $name . ': its own ingredients changed';
        if ($oi[$name]['allergens'] !== $i['allergens']) $out[] = $name . ': allergens changed';
    }
    $renamed = array_values(array_diff(array_keys($ni), array_keys($oi)));
    if ($renamed && $out === []) $out[] = 'Name on the label changed: ' . implode(', ', $renamed);
    if ($oldContains !== $newContains) $out[] = 'Contains: ' . ($oldContains !== '' ? $oldContains : 'none') . ' → ' . ($newContains !== '' ? $newContains : 'none');
    $os = bcrf_kitchen_snapshot_sulfites($old); $ns = bcrf_kitchen_snapshot_sulfites($new);
    if ($os !== $ns) $out[] = $ns ? 'Now contains sulfites' : 'No longer contains sulfites';
    if (!$out) $out[] = 'Weights changed (an ingredient\'s grams per mL or weight of one)';
    return array_values(array_unique($out));
}

/**
 * Save the recipe as it is now as a new version, unless the newest version already matches (then that one is returned).
 * Refuses a recipe that isn't ready for a label. Returns ['version' => row, 'created' => bool].
 */
function bcrf_kitchen_save_version(PDO $pdo, int $recipeId, ?int $uid, string $note = ''): array {
    $note = trim(mb_substr(preg_replace('/\s+/', ' ', $note), 0, 255));
    $res = bcrf_kitchen_resolve($pdo, $recipeId);
    if (!$res) throw new BcrfKitchenError('That recipe no longer exists.');
    if (!$res['ready']) throw new BcrfKitchenError('This recipe isn\'t ready for a label yet: every line needs a weight and every allergen must be checked.');
    $fp = bcrf_kitchen_fingerprint($res);
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, version_no, fingerprint FROM recipe_versions WHERE recipe_id = ? ORDER BY version_no DESC LIMIT 1 FOR UPDATE");
        $st->execute([$recipeId]);
        $last = $st->fetch(PDO::FETCH_ASSOC);
        if ($last && hash_equals((string)$last['fingerprint'], $fp)) {
            if ($own) $pdo->commit();
            return ['version' => bcrf_kitchen_version($pdo, (int)$last['id']), 'created' => false];
        }
        $no = $last ? (int)$last['version_no'] + 1 : 1;
        $pdo->prepare("INSERT INTO recipe_versions (recipe_id, version_no, fingerprint, statement, contains_line, total_g, snapshot, note, created_at, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
            ->execute([$recipeId, $no, $fp, $res['statement'], mb_substr($res['contains'], 0, 500), $res['total_g'],
                       json_encode(bcrf_kitchen_snapshot($res), JSON_UNESCAPED_UNICODE), $note !== '' ? $note : null, $uid]);
        $id = (int)$pdo->lastInsertId();
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['version' => bcrf_kitchen_version($pdo, $id), 'created' => true];
}

// ── Recipe → product ──

/** Grams in one of the product's size (mass units only), or null. */
function bcrf_kitchen_size_grams($value, string $unitName): ?float {
    $v = (float)$value;
    if ($v <= 0 || $unitName === '') return null;
    $k = bcrf_kitchen_unit_key($unitName);
    $t = bcrf_kitchen_unit_table()['mass'];
    return isset($t[$k]) ? $v * $t[$k] : null;
}

/** Kitchen goods and any product linked to a recipe, with the link. */
function bcrf_kitchen_products(PDO $pdo): array {
    $rows = $pdo->query("SELECT i.id, i.name, i.sku, i.product_type, i.is_active, i.price, i.size_value, COALESCE(NULLIF(u.abbreviation, ''), u.name, '') AS size_unit,
                                kp.recipe_id, kp.units_per_batch, kp.shelf_life_days, kp.approved_type, kp.approved_note, kp.updated_at, r.title AS recipe_title
                         FROM inventory i
                         LEFT JOIN kitchen_products kp ON kp.inventory_id = i.id
                         LEFT JOIN recipes r ON r.id = kp.recipe_id
                         LEFT JOIN measurement_units u ON u.id = i.size_unit_id
                         WHERE i.product_type = 'kitchen_good' OR kp.inventory_id IS NOT NULL
                         ORDER BY i.is_active DESC, i.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['net_qty'] = bcrf_label_net_qty($r['size_value'], (string)$r['size_unit']);
    unset($r);
    return $rows;
}

/** One product with its link (null when the product doesn't exist). */
function bcrf_kitchen_product(PDO $pdo, int $inventoryId): ?array {
    $st = $pdo->prepare("SELECT i.id, i.name, i.sku, i.product_type, i.is_active, i.price, i.size_value, i.avg_cost_cents, COALESCE(NULLIF(u.abbreviation, ''), u.name, '') AS size_unit,
                                kp.recipe_id, kp.units_per_batch, kp.shelf_life_days, kp.approved_type, kp.approved_note, kp.updated_at, r.title AS recipe_title
                         FROM inventory i
                         LEFT JOIN kitchen_products kp ON kp.inventory_id = i.id
                         LEFT JOIN recipes r ON r.id = kp.recipe_id
                         LEFT JOIN measurement_units u ON u.id = i.size_unit_id
                         WHERE i.id = ?");
    $st->execute([$inventoryId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['net_qty'] = bcrf_label_net_qty($r['size_value'], (string)$r['size_unit']);
    return $r;
}

/** Products a recipe makes. */
function bcrf_kitchen_products_for_recipe(PDO $pdo, int $recipeId): array {
    $st = $pdo->prepare("SELECT i.id, i.name, i.sku, i.is_active, i.size_value, COALESCE(NULLIF(u.abbreviation, ''), u.name, '') AS size_unit, kp.units_per_batch, kp.shelf_life_days, kp.approved_type
                         FROM kitchen_products kp JOIN inventory i ON i.id = kp.inventory_id LEFT JOIN measurement_units u ON u.id = i.size_unit_id
                         WHERE kp.recipe_id = ? ORDER BY i.name");
    $st->execute([$recipeId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['net_qty'] = bcrf_label_net_qty($r['size_value'], (string)$r['size_unit']);
    unset($r);
    return $rows;
}

/** Products that could be linked: active, stock-counted, not admission or thrift. */
function bcrf_kitchen_linkable_products(PDO $pdo): array {
    return $pdo->query("SELECT i.id, i.name, i.sku FROM inventory i LEFT JOIN kitchen_products kp ON kp.inventory_id = i.id
                        WHERE kp.inventory_id IS NULL AND i.is_active = 1 AND i.track_inventory = 1
                          AND i.product_type IN ('retail', 'produce', 'kitchen_good') ORDER BY i.name")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Link a product to its recipe (or change the link). $in: recipe_id, units_per_batch, shelf_life_days, approved_type ('1'), approved_note.
 * Returns the fields that changed.
 */
function bcrf_kitchen_product_save(PDO $pdo, int $inventoryId, array $in, ?int $uid): array {
    $p = bcrf_kitchen_product($pdo, $inventoryId);
    if (!$p) throw new BcrfKitchenError('That product no longer exists.');
    $rid = (int)($in['recipe_id'] ?? 0);
    $st = $pdo->prepare("SELECT id FROM recipes WHERE id = ?");
    $st->execute([$rid]);
    if (!$rid || !$st->fetchColumn()) throw new BcrfKitchenError('Pick the recipe that makes it.');
    $units = bcrf_kitchen_num($in['units_per_batch'] ?? '', 'Units per batch', 0.01, 100000);
    if ($units === null) throw new BcrfKitchenError('Say how many units one batch makes (for example 12 jars).');
    $shelf = trim((string)($in['shelf_life_days'] ?? ''));
    if ($shelf !== '' && (!ctype_digit($shelf) || (int)$shelf < 1 || (int)$shelf > 3650)) throw new BcrfKitchenError('Shelf life is a whole number of days, 1 to 3650.');
    $shelf = $shelf === '' ? null : (int)$shelf;
    $approved = !empty($in['approved_type']) ? 1 : 0;
    $note = trim(mb_substr(preg_replace('/\s+/', ' ', (string)($in['approved_note'] ?? '')), 0, 255));
    $changes = [];
    if ($p['recipe_id'] === null) $changes[] = 'linked';
    else {
        if ((int)$p['recipe_id'] !== $rid) $changes[] = 'recipe';
        if ((float)$p['units_per_batch'] !== $units) $changes[] = 'units per batch';
        if (($p['shelf_life_days'] === null ? null : (int)$p['shelf_life_days']) !== $shelf) $changes[] = 'shelf life';
        if ((int)$p['approved_type'] !== $approved) $changes[] = $approved ? 'marked approved type' : 'approved type removed';
        if ((string)($p['approved_note'] ?? '') !== $note) $changes[] = 'note';
    }
    $pdo->prepare("INSERT INTO kitchen_products (inventory_id, recipe_id, units_per_batch, shelf_life_days, approved_type, approved_note, updated_at, updated_by)
                   VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)
                   ON DUPLICATE KEY UPDATE recipe_id = VALUES(recipe_id), units_per_batch = VALUES(units_per_batch), shelf_life_days = VALUES(shelf_life_days),
                     approved_type = VALUES(approved_type), approved_note = VALUES(approved_note), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)")
        ->execute([$inventoryId, $rid, $units, $shelf, $approved, $note !== '' ? $note : null, $uid]);
    return $changes;
}

/** Remove a product's recipe link (batches made earlier keep their own record). */
function bcrf_kitchen_product_unlink(PDO $pdo, int $inventoryId): bool {
    $st = $pdo->prepare("DELETE FROM kitchen_products WHERE inventory_id = ?");
    $st->execute([$inventoryId]);
    return $st->rowCount() > 0;
}

/** What one batch makes by weight, against what the product's size says (mass sizes only). */
function bcrf_kitchen_batch_fit(float $batchG, $units, $sizeValue, string $sizeUnit): ?array {
    $each = bcrf_kitchen_size_grams($sizeValue, $sizeUnit);
    if (!$each || $batchG <= 0 || (float)$units <= 0) return null;
    $need = (float)$units * $each;
    return ['each_g' => $each, 'need_g' => $need, 'batch_g' => $batchG, 'fits' => round($batchG / $each, 1), 'over' => $need > $batchG * 1.0001];
}

}
