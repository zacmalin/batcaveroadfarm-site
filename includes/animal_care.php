<?php
// includes/animal_care.php — Farm OS Phase 5 slice 2: care and feeding (Nov 12, 2026; migration 0041; 08 › Care and feeding).
//   Logging    one entry covers one animal, several, or everyone in an enclosure ("fed all goats in the Goat Barn").
//              An enclosure entry covers the animals in it at that moment (resting and quarantined ones too; animals
//              that have left the farm never). Anyone who can see Animals can log care.
//   Feeding    a feeding type asks for a feed product and amount. If the product is a stocked item, the amount comes
//              out of stock where the feed is kept (bcrf_stock_home_location: the location holding the most, else the Feed
//              Room; was the default location until Nov 19, 2026) ("Used on the farm", source animal_care) and never blocks the entry:
//              short stock is noted on the ledger instead. The cost is the amount times the product's average cost at
//              that moment, kept on the entry for animal expenses later.
//   Voiding    entries are never deleted. Voiding one (with a reason) puts its feed back into stock.
//   Feed units (Nov 15, 2026; migration 0071) stock and cost stay in the product's own unit (a 50 lb bag), but a feeding
//              can be entered in lb, oz, bags or a named scoop (animal_feed_measures, e.g. "2 pint scoop" = 4 lb). The
//              bag weight is the product's Size (size_value + size_unit_id), or its unit when it is sold by lb or oz.
//              The amount typed is kept (feed_entered_qty/unit) with its weight (feed_lb); feed_qty is the share of a bag.
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

/**
 * The feed products with what's on hand, for the picker. Each row also has unit_g (grams in one stock unit, or null when
 * the bag weight isn't set), stock_text ("3 bags + 12 lb"), units (what an amount can be entered in) and last_unit.
 */
function bcrf_care_feed_products(PDO $pdo): array {
    $ids = bcrf_care_feed_ids($pdo);
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT i.id, i.name, i.stock_quantity, i.track_inventory, i.unit, i.size_value, COALESCE(NULLIF(u.abbreviation, ''), u.name, '') AS size_unit
                           FROM inventory i LEFT JOIN measurement_units u ON u.id = i.size_unit_id WHERE i.id IN ($in) ORDER BY i.name");
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $ready = bcrf_feed_units_ready($pdo);
    $measures = $ready ? bcrf_feed_measures($pdo) : [];
    $last = [];
    if ($ready) {
        $q = $pdo->prepare("SELECT l.feed_product_id, l.feed_entered_unit FROM animal_care_logs l
                             JOIN (SELECT feed_product_id, MAX(id) AS id FROM animal_care_logs WHERE feed_product_id IN ($in) AND feed_entered_unit IS NOT NULL GROUP BY feed_product_id) m ON m.id = l.id");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $last[(int)$r['feed_product_id']] = (string)$r['feed_entered_unit'];
    }
    foreach ($rows as &$r) {
        $r['unit_g'] = $ready ? bcrf_feed_unit_grams($r) : null;
        $r['stock_text'] = bcrf_feed_stock_text((float)$r['stock_quantity'], $r);
        $r['units'] = $r['unit_g'] ? bcrf_feed_entry_units($r, $measures) : [];
        $r['last_unit'] = $last[(int)$r['id']] ?? '';
    }
    unset($r);
    return $rows;
}

// -- Feed units (Nov 15, 2026; migration 0071) --

function bcrf_feed_units_ready(PDO $pdo): bool {
    static $cache = [];
    $k = spl_object_id($pdo);
    if (isset($cache[$k])) return $cache[$k];
    try { $pdo->query("SELECT 1 FROM animal_feed_measures LIMIT 0"); $pdo->query("SELECT feed_lb FROM animal_care_logs LIMIT 0"); return $cache[$k] = true; }
    catch (Throwable $e) { return $cache[$k] = false; }
}

/** Grams in one of a weight unit ("lb", "Pounds", "oz", "kg", "g"), or null if it isn't a weight. */
function bcrf_feed_g(string $unit): ?float {
    $u = strtolower(trim(str_replace('.', '', $unit)));
    if (strlen($u) > 2 && str_ends_with($u, 's')) $u = substr($u, 0, -1);
    return ['lb' => 453.59237, 'pound' => 453.59237, 'oz' => 28.349523, 'ounce' => 28.349523, 'kg' => 1000.0, 'kilogram' => 1000.0,
            'g' => 1.0, 'gram' => 1.0][$u] ?? null;
}

/** Grams in one stock unit of a product (one bag): its unit when sold by weight, else its Size. Null when unknown. */
function bcrf_feed_unit_grams(array $p): ?float {
    $g = bcrf_feed_g((string)($p['unit'] ?? ''));
    if ($g) return $g;
    $v = (float)($p['size_value'] ?? 0);
    $sg = bcrf_feed_g((string)($p['size_unit'] ?? ''));
    return ($v > 0 && $sg) ? $v * $sg : null;
}

/** What one stock unit is called: "lb" for a product sold by the pound, otherwise "bag". */
function bcrf_feed_stock_word(array $p, float $n = 1): string {
    $u = (string)($p['unit'] ?? '');
    if (bcrf_feed_g($u)) return $u;
    return abs($n - 1) < 0.0005 ? 'bag' : 'bags';
}

/** A weight in lb as words: "6 lb", "6.5 lb", "8 oz" under a pound. */
function bcrf_feed_weight_text(float $lb): string {
    if ($lb > 0 && $lb < 1) return bcrf_qtxt(round($lb * 16, 1)) . ' oz';
    return bcrf_qtxt(round($lb, 2)) . ' lb';
}

/** Stock on hand as words: "3 bags + 12 lb" when the bag weight is known, else "3 bag" style. */
function bcrf_feed_stock_text(float $qty, array $p): string {
    $ug = bcrf_feed_unit_grams($p);
    $unit = (string)($p['unit'] ?? '');
    if (!$ug || bcrf_feed_g($unit)) return bcrf_qtxt($qty) . ($unit !== '' && $unit !== 'each' ? ' ' . $unit : '');
    if ($qty <= 0) return '0 bags';
    $bags = (int)floor($qty + 0.0005);
    $restLb = round(($qty - $bags) * $ug / 453.59237, 1);
    $bagLb = $ug / 453.59237;
    if ($restLb >= $bagLb - 0.05) { $bags++; $restLb = 0; }
    $parts = [];
    if ($bags > 0) $parts[] = $bags . ' ' . ($bags === 1 ? 'bag' : 'bags');
    if ($restLb > 0) $parts[] = bcrf_feed_weight_text($restLb);
    return $parts ? implode(' + ', $parts) : '0 bags';
}

/** Scoops and other measures in use: [id, product_id (null = every feed), product_name, name, weight, weight_unit, g]. */
function bcrf_feed_measures(PDO $pdo): array {
    if (!bcrf_feed_units_ready($pdo)) return [];
    $rows = $pdo->query("SELECT m.id, m.product_id, i.name AS product_name, m.name, m.weight, m.weight_unit FROM animal_feed_measures m
                           LEFT JOIN inventory i ON i.id = m.product_id WHERE m.active = 1 ORDER BY m.product_id IS NOT NULL, i.name, m.sort_order, m.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['product_id'] = $r['product_id'] !== null ? (int)$r['product_id'] : null;
        $r['g'] = (float)$r['weight'] * (bcrf_feed_g((string)$r['weight_unit']) ?? 0);
    }
    unset($r);
    return $rows;
}

/** Add (id 0) or change a measure. $productId null = for every feed. Returns its id. */
function bcrf_feed_measure_save(PDO $pdo, int $id, string $name, $weight, string $unit, ?int $productId): int {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '' || mb_strlen($name) > 40) throw new BcrfCareError('Give the scoop a name (up to 40 characters), like "2 pint scoop".');
    $w = trim(str_replace(',', '', (string)$weight));
    if ($w === '' || !is_numeric($w) || (float)$w <= 0 || (float)$w > 1000) throw new BcrfCareError('How much does a full ' . $name . ' weigh? Enter a weight above 0.');
    if (!in_array($unit, ['lb', 'oz'], true)) throw new BcrfCareError('Pick lb or oz for the weight.');
    if ($productId) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE id = ?"); $st->execute([$productId]);
        if (!(int)$st->fetchColumn()) throw new BcrfCareError('That feed is no longer a product.');
    } else $productId = null;
    $st = $pdo->prepare("SELECT id FROM animal_feed_measures WHERE active = 1 AND name = ? AND id <> ? AND " . ($productId ? 'product_id = ?' : 'product_id IS NULL'));
    $st->execute($productId ? [$name, $id, $productId] : [$name, $id]);
    if ($st->fetchColumn()) throw new BcrfCareError('There is already a measure called ' . $name . '.');
    if ($id) {
        $pdo->prepare("UPDATE animal_feed_measures SET name = ?, weight = ?, weight_unit = ?, product_id = ? WHERE id = ? AND active = 1")
            ->execute([$name, round((float)$w, 3), $unit, $productId, $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO animal_feed_measures (product_id, name, weight, weight_unit, active, sort_order, created_at) VALUES (?, ?, ?, ?, 1, 0, UTC_TIMESTAMP())")
        ->execute([$productId, $name, round((float)$w, 3), $unit]);
    return (int)$pdo->lastInsertId();
}

/** Stop offering a measure. Past feedings keep what was typed, so nothing is lost. */
function bcrf_feed_measure_remove(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE animal_feed_measures SET active = 0 WHERE id = ?")->execute([$id]);
}

/** The id of the "lb" row in measurement_units, adding one if there isn't any. */
function bcrf_feed_lb_unit_id(PDO $pdo): int {
    $id = $pdo->query("SELECT id FROM measurement_units WHERE LOWER(abbreviation) IN ('lb','lbs') OR LOWER(name) IN ('pound','pounds','lb','lbs') ORDER BY id LIMIT 1")->fetchColumn();
    if ($id) return (int)$id;
    $pdo->exec("INSERT INTO measurement_units (name, system_type, abbreviation, unit_type) VALUES ('Pound', 'US', 'lb', 'Weight')");
    return (int)$pdo->lastInsertId();
}

/** Set a feed's bag weight in lb (the product's Size). Blank clears it. */
function bcrf_feed_bag_save(PDO $pdo, int $productId, $lb): void {
    $v = trim(str_replace(',', '', (string)$lb));
    if ($v === '') { $pdo->prepare("UPDATE inventory SET size_value = NULL, size_unit_id = NULL WHERE id = ?")->execute([$productId]); return; }
    if (!is_numeric($v) || (float)$v <= 0 || (float)$v > 5000) throw new BcrfCareError('Enter the bag weight in pounds, like 50.');
    $pdo->prepare("UPDATE inventory SET size_value = ?, size_unit_id = ? WHERE id = ?")->execute([round((float)$v, 2), bcrf_feed_lb_unit_id($pdo), $productId]);
}

/**
 * What an amount of this feed can be entered in, for the log form: list of [key, label, g]. Its own scoops first, then
 * the scoops for every feed, then lb, oz and bags. Keys: "m<id>", "lb", "oz", "bag".
 */
function bcrf_feed_entry_units(array $p, array $measures): array {
    $ug = bcrf_feed_unit_grams($p);
    if (!$ug) return [];
    $out = [];
    foreach ([true, false] as $own) foreach ($measures as $m) {
        if ($own ? $m['product_id'] !== (int)$p['id'] : $m['product_id'] !== null) continue;
        if ($m['g'] > 0) $out[] = ['key' => 'm' . $m['id'], 'label' => $m['name'] . ' (' . bcrf_feed_weight_text($m['g'] / 453.59237) . ')', 'g' => $m['g'], 'name' => $m['name']];
    }
    $out[] = ['key' => 'lb', 'label' => 'lb', 'g' => 453.59237, 'name' => 'lb'];
    $out[] = ['key' => 'oz', 'label' => 'oz', 'g' => 28.349523, 'name' => 'oz'];
    if (!bcrf_feed_g((string)($p['unit'] ?? ''))) $out[] = ['key' => 'bag', 'label' => 'bags (' . bcrf_feed_weight_text($ug / 453.59237) . ' each)', 'g' => $ug, 'name' => 'bag'];
    return $out;
}

/** How a feeding reads: "2 × 2 pint scoop (8 lb) of Goat feed", "6 lb of Goat feed", or the old "0.12 of Goat feed". */
function bcrf_feed_amount_text(array $r): string {
    $name = (string)($r['feed_name'] ?? '');
    $eu = (string)($r['feed_entered_unit'] ?? '');
    $eq = isset($r['feed_entered_qty']) && $r['feed_entered_qty'] !== null ? (float)$r['feed_entered_qty'] : null;
    $lb = isset($r['feed_lb']) && $r['feed_lb'] !== null ? (float)$r['feed_lb'] : null;
    if ($eu === '' || $eq === null) return bcrf_qtxt((float)$r['feed_qty']) . ' of ' . $name;
    if ($eu === 'lb' || $eu === 'oz') $t = bcrf_qtxt($eq) . ' ' . $eu;
    elseif ($eu === 'bag') $t = bcrf_qtxt($eq) . ' ' . (abs($eq - 1) < 0.0005 ? 'bag' : 'bags') . ($lb !== null ? ' (' . bcrf_feed_weight_text($lb) . ')' : '');
    else $t = bcrf_qtxt($eq) . ' × ' . $eu . ($lb !== null ? ' (' . bcrf_feed_weight_text($lb) . ')' : '');
    return $t . ' of ' . $name;
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
 * Log care. $in: type (care type id), animals (ids) and/or enclosure (id), note, feed_product, feed_qty and feed_unit
 * ("lb", "oz", "bag" or "m<measure id>"; blank = feed_qty is in the product's own unit, as before Nov 15, 2026).
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

    $feedId = null; $qty = null; $cost = null; $exact = null;
    $enteredQty = null; $enteredUnit = null; $feedLb = null;
    if ($type['is_feeding']) {
        $feedId = (int)($in['feed_product'] ?? 0) ?: null;
        $rawQty = trim(str_replace(',', '', (string)($in['feed_qty'] ?? '')));
        if ($feedId) {
            if (!in_array($feedId, bcrf_care_feed_ids($pdo), true)) throw new BcrfCareError('Pick a feed from the list.');
            if ($rawQty === '' || !is_numeric($rawQty) || (float)$rawQty <= 0) throw new BcrfCareError('How much feed? Enter an amount above 0.');
            $qty = round((float)$rawQty, 3);
            if ($qty > 10000) throw new BcrfCareError('That amount looks wrong (over 10,000).');
            $exact = $qty;
            // Feed units (Nov 15, 2026): an amount in lb, oz, bags or a scoop becomes a share of the stock unit (a bag).
            $unitKey = trim((string)($in['feed_unit'] ?? ''));
            if ($unitKey !== '' && bcrf_feed_units_ready($pdo)) {
                $fp = null;
                foreach (bcrf_care_feed_products($pdo) as $row) if ((int)$row['id'] === $feedId) $fp = $row;
                if (!$fp || !$fp['unit_g']) throw new BcrfCareError('Set the bag weight for this feed in Animal care › Setup first, or ask a manager to.');
                $u = null;
                foreach ($fp['units'] as $cand) if ($cand['key'] === $unitKey) $u = $cand;
                if (!$u) throw new BcrfCareError('Pick lb, oz, bags or one of the scoops.');
                $grams = $qty * $u['g'];
                $exact = $grams / $fp['unit_g'];
                $qty = max(0.001, round($exact, 3));
                $enteredQty = round((float)$rawQty, 3);
                $enteredUnit = mb_substr((string)$u['name'], 0, 40);
                $feedLb = round($grams / 453.59237, 3);
            }
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
        if ($enteredUnit !== null) $pdo->prepare("UPDATE animal_care_logs SET feed_entered_qty = ?, feed_entered_unit = ?, feed_lb = ? WHERE id = ?")
                                       ->execute([$enteredQty, $enteredUnit, $feedLb, $logId]);
        $add = $pdo->prepare("INSERT INTO animal_care_log_animals (log_id, animal_id) VALUES (?, ?)");
        foreach ($ids as $aid) $add->execute([$logId, $aid]);
        if ($feedId) {
            $st = $pdo->prepare("SELECT name, track_inventory, avg_cost_cents FROM inventory WHERE id = ?");
            $st->execute([$feedId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new BcrfCareError('That feed is no longer a product.');
            if ($p['avg_cost_cents'] !== null) $cost = (int)round((int)$p['avg_cost_cents'] * $exact);
            $adjId = null;
            if ((int)$p['track_inventory'] === 1 && bcrf_stock_ready($pdo)) {
                $L = bcrf_stock_lock($pdo, $feedId, $uid);   // lock (and repair any drift) first, then pick where it's kept
                $r = bcrf_stock_move($pdo, $feedId, bcrf_stock_home_location($pdo, $feedId), -$qty, 'internal_use', 'animal_care', $logId,
                                     $type['name'] . ' (' . count($ids) . ' animal' . (count($ids) > 1 ? 's' : '') . ')', $uid, 'clamp', $L);
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

/** Save all feeds from one form atomically, reusing the existing per-feed stock/cost/void ledger.
 * Each feed is a separate linked-to-animals care entry; no schema change or duplicate stock deduction.
 */
function bcrf_care_log_session(PDO $pdo, array $in, array $feeds, ?int $uid = null): array {
    $type = bcrf_care_type($pdo, (int)($in['type'] ?? 0));
    if (!$type || !$type['active']) throw new BcrfCareError('Pick what you did.');
    if (!$type['is_feeding']) return bcrf_care_log($pdo, $in, $uid);
    if (!$feeds || count($feeds) > 30) throw new BcrfCareError('Add between 1 and 30 feeds.');
    $clean = [];
    foreach ($feeds as $row) {
        if (!is_array($row)) throw new BcrfCareError('Invalid feed row.');
        $product = (int)($row['product'] ?? 0);
        $quantity = trim((string)($row['qty'] ?? ''));
        $unit = trim((string)($row['unit'] ?? ''));
        if (!$product && $quantity === '' && $unit === '') continue;
        if (!$product || $quantity === '') throw new BcrfCareError('Choose a feed and amount for each row, or remove the empty row.');
        $clean[] = ['feed_product' => $product, 'feed_qty' => $quantity, 'feed_unit' => $unit];
    }
    if (!$clean) return bcrf_care_log($pdo, $in, $uid);   // feed stays optional: no feed rows filled = log the feeding without stock (Nov 17, 2026)
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $result = null; $notes = [];
        foreach ($clean as $row) {
            $r = bcrf_care_log($pdo, array_merge($in, $row), $uid);
            if ($result === null) $result = $r;
            if ($r['stock_note']) $notes[] = $r['stock_note'];
        }
        if ($own) $pdo->commit();
        return ['id' => $result['id'], 'ids_count' => count($clean), 'animals' => $result['animals'], 'stock_note' => implode(' ', $notes)];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
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
function bcrf_care_recent(PDO $pdo, int $animalId = 0, int $limit = 50, bool $withVoided = true, int $careTypeId = 0): array {
    $where = []; $args = [];
    if ($animalId) { $where[] = 'l.id IN (SELECT log_id FROM animal_care_log_animals WHERE animal_id = ?)'; $args[] = $animalId; }
    if (!$withVoided) $where[] = 'l.voided_at IS NULL';
    if ($careTypeId > 0) { $where[] = 'l.care_type_id = ?'; $args[] = $careTypeId; }
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
