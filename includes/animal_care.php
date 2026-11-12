<?php
// includes/animal_care.php — Farm OS Phase 5 slice 2: care and feeding (Nov 12, 2026; migration 0041; 08 › Care and feeding).
//   Logging    one entry covers one animal, several, or everyone in an enclosure ("fed all goats in the Goat Barn").
//              An enclosure entry covers the animals in it at that moment (resting and quarantined ones too; animals
//              that have left the farm never). Anyone who can see Animals can log care.
//   Feeding    a feeding type asks for a feed product and amount. If the product is a stocked item, the amount comes
//              out of stock at the default location ("Used on the farm", source animal_care) and never blocks the entry:
//              short stock is noted on the ledger instead. The cost is the amount times the product's average cost at
//              that moment, kept on the entry for animal expenses later.
//   Voiding    entries are never deleted. Voiding one (with a reason) puts its feed back into stock.
// Times are UTC in the database; money is integer cents.
if (!function_exists('bcrf_care_ready')) {

require_once __DIR__ . '/animals.php';
require_once __DIR__ . '/stock.php';

class BcrfCareError extends RuntimeException {}

function bcrf_care_ready(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM animal_care_types LIMIT 0");
        $pdo->query("SELECT 1 FROM animal_care_logs LIMIT 0");
        $pdo->query("SELECT 1 FROM animal_care_log_animals LIMIT 0");
        return bcrf_animals_ready($pdo);
    } catch (Throwable $e) { return false; }
}

// ── Care types ──────────────────────────────────────────────────────────────────────────────────

function bcrf_care_types(PDO $pdo, bool $all = false): array {
    $rows = $pdo->query("SELECT * FROM animal_care_types" . ($all ? '' : ' WHERE active = 1') . " ORDER BY active DESC, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) foreach (['id', 'is_feeding', 'quick', 'active', 'sort_order'] as $k) $r[$k] = (int)$r[$k];
    return $rows;
}

function bcrf_care_type(PDO $pdo, int $id): ?array {
    foreach (bcrf_care_types($pdo, true) as $t) if ($t['id'] === $id) return $t;
    return null;
}

/** Create (id 0) or update a care type. Returns its id. */
function bcrf_care_type_save(PDO $pdo, int $id, string $name, bool $feeding, bool $quick, bool $active, int $sort = 0): int {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 60) throw new BcrfCareError('Give the care type a name (up to 60 characters).');
    $st = $pdo->prepare("SELECT id FROM animal_care_types WHERE name = ? AND id <> ?");
    $st->execute([$name, $id]);
    if ($st->fetchColumn()) throw new BcrfCareError('There is already a care type called ' . $name . '.');
    if ($id) {
        $pdo->prepare("UPDATE animal_care_types SET name = ?, is_feeding = ?, quick = ?, active = ?, sort_order = ? WHERE id = ?")
            ->execute([$name, $feeding ? 1 : 0, $quick ? 1 : 0, $active ? 1 : 0, $sort, $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO animal_care_types (name, is_feeding, quick, active, sort_order) VALUES (?, ?, ?, ?, ?)")
        ->execute([$name, $feeding ? 1 : 0, $quick ? 1 : 0, $active ? 1 : 0, $sort]);
    return (int)$pdo->lastInsertId();
}

// ── Feed products ───────────────────────────────────────────────────────────────────────────────

/** Inventory ids offered as feed. */
function bcrf_care_feed_ids(PDO $pdo): array {
    $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'animal_feed_products'");
    $st->execute();
    return array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$st->fetchColumn())))));
}

function bcrf_care_set_feed_ids(PDO $pdo, array $ids): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id FROM inventory WHERE id IN ($in)");
        $st->execute($ids);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);
    }
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('animal_feed_products', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([implode(',', $ids)]);
}

/** The feed products with what's on hand, for the picker. */
function bcrf_care_feed_products(PDO $pdo): array {
    $ids = bcrf_care_feed_ids($pdo);
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $hasUnit = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'unit'")->fetchColumn() > 0;
    $st = $pdo->prepare("SELECT id, name, stock_quantity, track_inventory" . ($hasUnit ? ', unit' : ", '' AS unit") . " FROM inventory WHERE id IN ($in) ORDER BY name");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Every product, for choosing which ones are feed (name and id only). */
function bcrf_care_all_products(PDO $pdo): array {
    return $pdo->query("SELECT id, name FROM inventory ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

/** Inventory categories for the feed picker's dropdown: [id => name]. */
function bcrf_care_categories(PDO $pdo): array {
    try { return $pdo->query("SELECT id, name FROM inventory_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_KEY_PAIR); }
    catch (Throwable $e) { return []; }
}

/** The category the feed picker opens on: the one named "Feed" (null if there isn't one). */
function bcrf_care_feed_category(PDO $pdo): ?int {
    foreach (bcrf_care_categories($pdo) as $id => $name) if (strcasecmp(trim((string)$name), 'Feed') === 0) return (int)$id;
    return null;
}

/**
 * Products for the feed picker (Nov 12, 2026). Ticked feed products always come first, whatever the filter, so a
 * filter can never hide (and so untick) one. Then unticked products in category $cat (null = all) whose name or SKU
 * contains $q, up to $limit. Returns ['ticked' => [...], 'others' => [...], 'more' => bool]; each row id, name, category.
 */
function bcrf_care_pick_products(PDO $pdo, ?int $cat, string $q, int $limit = 300): array {
    $feed = bcrf_care_feed_ids($pdo);
    $sel = "SELECT i.id, i.name, c.name AS category FROM inventory i LEFT JOIN inventory_categories c ON c.id = i.category_id";
    $ticked = [];
    if ($feed) {
        $st = $pdo->prepare("$sel WHERE i.id IN (" . implode(',', array_fill(0, count($feed), '?')) . ") ORDER BY i.name");
        $st->execute($feed);
        $ticked = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $w = []; $a = [];
    if ($feed) { $w[] = 'i.id NOT IN (' . implode(',', array_fill(0, count($feed), '?')) . ')'; $a = $feed; }
    if ($cat !== null) { $w[] = 'i.category_id = ?'; $a[] = $cat; }
    $q = trim($q);
    if ($q !== '') { $w[] = '(i.name LIKE ? OR i.sku LIKE ?)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $a[] = $like; $a[] = $like; }
    $st = $pdo->prepare("$sel" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY i.name LIMIT " . ($limit + 1));
    $st->execute($a);
    $others = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($others) > $limit;
    return ['ticked' => $ticked, 'others' => array_slice($others, 0, $limit), 'more' => $more];
}

/** Save the picker: only products that were on screen ($shown) change; ticked ones ($ticked) become feed. */
function bcrf_care_update_feed_ids(PDO $pdo, array $shown, array $ticked): void {
    $shown = array_map('intval', $shown);
    $keep = array_filter(bcrf_care_feed_ids($pdo), fn($id) => !in_array($id, $shown, true));
    bcrf_care_set_feed_ids($pdo, array_merge($keep, array_intersect(array_map('intval', $ticked), $shown)));
}

// ── Logging ─────────────────────────────────────────────────────────────────────────────────────

/**
 * Log care. $in: type (care type id), animals (ids) and/or enclosure (id), note, feed_product, feed_qty.
 * Returns ['id' => log id, 'animals' => n, 'stock_note' => text or ''].
 */
function bcrf_care_log(PDO $pdo, array $in, ?int $uid = null): array {
    $type = bcrf_care_type($pdo, (int)($in['type'] ?? 0));
    if (!$type || !$type['active']) throw new BcrfCareError('Pick what you did.');
    $enc = (int)($in['enclosure'] ?? 0);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['animals'] ?? [])))));
    if ($enc) {
        $e = bcrf_enclosure_get($pdo, $enc);
        if (!$e) throw new BcrfCareError('That enclosure doesn\'t exist.');
        $st = $pdo->prepare("SELECT id FROM animals WHERE enclosure_id = ? AND status NOT IN ('sold','transferred','adopted','deceased')");
        $st->execute([$enc]);
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)))));
        if (!$ids) throw new BcrfCareError('Nobody is in ' . $e['name'] . ' right now.');
    }
    if (!$ids) throw new BcrfCareError('Pick the animals, or an enclosure.');
    $in2 = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, status FROM animals WHERE id IN ($in2)");
    $st->execute($ids);
    $found = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($found) !== count($ids)) throw new BcrfCareError('One of those animals is not on the roster.');
    foreach ($found as $a) if (bcrf_animal_is_leaving((string)$a['status'])) throw new BcrfCareError($a['name'] . ' has left the farm.');
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 500);

    $feedId = null; $qty = null; $cost = null;
    if ($type['is_feeding']) {
        $feedId = (int)($in['feed_product'] ?? 0) ?: null;
        $rawQty = trim(str_replace(',', '', (string)($in['feed_qty'] ?? '')));
        if ($feedId) {
            if (!in_array($feedId, bcrf_care_feed_ids($pdo), true)) throw new BcrfCareError('Pick a feed from the list.');
            if ($rawQty === '' || !is_numeric($rawQty) || (float)$rawQty <= 0) throw new BcrfCareError('How much feed? Enter an amount above 0.');
            $qty = round((float)$rawQty, 3);
            if ($qty > 10000) throw new BcrfCareError('That amount looks wrong (over 10,000).');
        } elseif ($rawQty !== '') {
            throw new BcrfCareError('Pick which feed the amount is for.');
        }
    }

    $stockNote = '';
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO animal_care_logs (care_type_id, enclosure_id, logged_at, logged_by, note, feed_product_id, feed_qty) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)")
            ->execute([$type['id'], $enc ?: null, $uid, $note !== '' ? $note : null, $feedId, $qty]);
        $logId = (int)$pdo->lastInsertId();
        $add = $pdo->prepare("INSERT INTO animal_care_log_animals (log_id, animal_id) VALUES (?, ?)");
        foreach ($ids as $aid) $add->execute([$logId, $aid]);
        if ($feedId) {
            $st = $pdo->prepare("SELECT name, track_inventory, avg_cost_cents FROM inventory WHERE id = ?");
            $st->execute([$feedId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new BcrfCareError('That feed is no longer a product.');
            if ($p['avg_cost_cents'] !== null) $cost = (int)round((int)$p['avg_cost_cents'] * $qty);
            $adjId = null;
            if ((int)$p['track_inventory'] === 1 && bcrf_stock_ready($pdo)) {
                $r = bcrf_stock_move($pdo, $feedId, bcrf_location_default($pdo), -$qty, 'internal_use', 'animal_care', $logId,
                                     $type['name'] . ' (' . count($ids) . ' animal' . (count($ids) > 1 ? 's' : '') . ')', $uid, 'clamp');
                $adjId = $r['id'] ?: null;
                if ($r['short'] > 0) $stockNote = 'Stock for ' . $p['name'] . ' was short by ' . bcrf_qtxt($r['short']) . '; count it when you can.';
            }
            $pdo->prepare("UPDATE animal_care_logs SET feed_cost_cents = ?, stock_adjustment_id = ? WHERE id = ?")->execute([$cost, $adjId, $logId]);
        }
        if ($own) $pdo->commit();
    } catch (BcrfStockError $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw new BcrfCareError($e->getMessage());
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['id' => $logId, 'animals' => count($ids), 'stock_note' => $stockNote];
}

/** Void an entry; its feed goes back into stock at the location it came from. */
function bcrf_care_void(PDO $pdo, int $logId, string $reason, ?int $uid = null): void {
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new BcrfCareError('Say why you are voiding it.');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM animal_care_logs WHERE id = ? FOR UPDATE");
        $st->execute([$logId]);
        $log = $st->fetch(PDO::FETCH_ASSOC);
        if (!$log) throw new BcrfCareError('That entry doesn\'t exist.');
        if ($log['voided_at']) throw new BcrfCareError('It is already voided.');
        $pdo->prepare("UPDATE animal_care_logs SET voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ?")->execute([$uid, $reason, $logId]);
        if ($log['stock_adjustment_id']) {
            $st = $pdo->prepare("SELECT product_id, location_id, quantity_change FROM inventory_adjustments WHERE id = ?");
            $st->execute([(int)$log['stock_adjustment_id']]);
            $adj = $st->fetch(PDO::FETCH_ASSOC);
            if ($adj && (float)$adj['quantity_change'] < 0) {
                bcrf_stock_move($pdo, (int)$adj['product_id'], (int)$adj['location_id'], -(float)$adj['quantity_change'], 'correction', 'animal_care', $logId,
                                'Care entry voided: ' . $reason, $uid, 'strict');
            }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Recent entries, newest first. $animalId limits to one animal; $since (UTC Y-m-d H:i:s) to recent ones.
 * Each row has type_name, is_feeding, enclosure_name, feed_name, unit, by_name and animals (list of names).
 */
function bcrf_care_recent(PDO $pdo, int $animalId = 0, int $limit = 50, bool $withVoided = true): array {
    $where = []; $args = [];
    if ($animalId) { $where[] = 'l.id IN (SELECT log_id FROM animal_care_log_animals WHERE animal_id = ?)'; $args[] = $animalId; }
    if (!$withVoided) $where[] = 'l.voided_at IS NULL';
    $limit = max(1, min(500, $limit));
    $st = $pdo->prepare("SELECT l.*, t.name AS type_name, t.is_feeding, e.name AS enclosure_name, i.name AS feed_name,
                                CONCAT(u.first_name, ' ', u.last_name) AS by_name
                           FROM animal_care_logs l
                           JOIN animal_care_types t ON t.id = l.care_type_id
                           LEFT JOIN enclosures e ON e.id = l.enclosure_id
                           LEFT JOIN inventory i ON i.id = l.feed_product_id
                           LEFT JOIN users u ON u.id = l.logged_by"
                       . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY l.logged_at DESC, l.id DESC LIMIT $limit");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT la.log_id, a.name FROM animal_care_log_animals la JOIN animals a ON a.id = la.animal_id WHERE la.log_id IN ($in) ORDER BY a.name");
    $st->execute($ids);
    $names = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $names[(int)$r['log_id']][] = $r['name'];
    foreach ($rows as &$r) $r['animals'] = $names[(int)$r['id']] ?? [];
    return $rows;
}

/** When each active care type was last logged for an animal (for the animal's Care tab). */
function bcrf_care_last_by_type(PDO $pdo, int $animalId): array {
    $st = $pdo->prepare("SELECT l.care_type_id, MAX(l.logged_at) AS last_at FROM animal_care_logs l
                           JOIN animal_care_log_animals la ON la.log_id = l.id
                          WHERE la.animal_id = ? AND l.voided_at IS NULL GROUP BY l.care_type_id");
    $st->execute([$animalId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['care_type_id']] = $r['last_at'];
    return $out;
}

}
