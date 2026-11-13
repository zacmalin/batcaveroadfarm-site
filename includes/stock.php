<?php
// includes/stock.php — stock by location, adjustments, transfers and counts (Farm OS Phase 1, slice 8, Nov 6, 2026; 06 Purchasing & Inventory)
// Rules:
//   - Quantity on hand lives in inventory_levels (product × location). inventory.stock_quantity is kept equal to the sum, so
//     everything that reads the total (public pages, low-stock email, dashboard, old register) keeps working.
//   - Every change goes through bcrf_stock_move() inside a transaction: it locks the product row first (one lock order for
//     sales, refunds, counts and adjustments), then the product's level rows, and writes one inventory_adjustments row with
//     the location, the quantity before and after at that location, the reason and the source record.
//   - If the total was changed by something outside this file (old register, Full Editor, connector), the next move or the
//     Stock page puts the difference on the default location as a "sync" ledger row, so levels and the total agree again.
//   - Until migration 0015 runs, bcrf_stock_ready() is false and callers keep their old behaviour.
if (!class_exists('BcrfStockError')) { class BcrfStockError extends RuntimeException {} }

/** True once migration 0015 has run (cached per request). */
function bcrf_stock_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                                   AND table_name IN ('locations','inventory_levels','inventory_counts','inventory_count_lines')")->fetchColumn() === 4;
    } catch (Throwable $e) { $ready = false; }
    return $ready;
}

/** Reasons staff can pick for a manual adjustment: code => [label, sign] (sign 0 = staff choose add or remove). */
function bcrf_stock_manual_reasons(): array {
    return [
        'receive'       => ['Received (no purchase order)', 1],
        'internal_use'  => ['Used on the farm', -1],
        'spoilage'      => ['Spoiled or expired', -1],
        'damage'        => ['Damaged', -1],
        'shrink'        => ['Lost or stolen', -1],
        'vendor_return' => ['Returned to vendor', -1],
        'correction'    => ['Correction', 0],
    ];
}

/** Every reason the ledger can hold, for display. */
function bcrf_stock_reason_label(string $r): string {
    $m = array_map(fn($x) => $x[0], bcrf_stock_manual_reasons()) + [
        'sale' => 'Sold', 'void' => 'Sale voided', 'refund_restock' => 'Refund, back in stock', 'count' => 'Count',
        'opening' => 'Opening balance', 'transfer_out' => 'Moved out', 'transfer_in' => 'Moved in', 'sync' => 'Changed outside Stock',
        'produced' => 'Made in the kitchen', 'batch_use' => 'Used in a kitchen batch', 'batch_void' => 'Kitchen batch voided',   // Phase 4 slice 3
    ];
    return $m[$r] ?? $r;
}

function bcrf_stock_setting(PDO $pdo, string $key, int $default): int {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $cache[$key] = ($v === false || !is_numeric($v)) ? $default : max(0, (int)$v);
    } catch (Throwable $e) { return $cache[$key] = $default; }
}

/** Quantity helpers: work in thousandths so DECIMAL(12,3) values add up exactly. */
function bcrf_qm($q): int { return (int)round((float)$q * 1000); }
function bcrf_qf(int $m): float { return round($m / 1000, 3); }
function bcrf_qtxt($q): string { $s = rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.'); return $s === '-0' ? '0' : $s; }

// -- Locations --

function bcrf_locations(PDO $pdo, bool $withArchived = false): array {
    return $pdo->query("SELECT id, code, name, sellable, is_default, sort_order, archived_at FROM locations"
                       . ($withArchived ? '' : ' WHERE archived_at IS NULL') . " ORDER BY archived_at IS NOT NULL, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_location(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT id, code, name, sellable, is_default, sort_order, archived_at FROM locations WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bcrf_location_default(PDO $pdo): int {
    $id = (int)$pdo->query("SELECT id FROM locations WHERE is_default = 1 AND archived_at IS NULL ORDER BY sort_order, id LIMIT 1")->fetchColumn();
    if (!$id) $id = (int)$pdo->query("SELECT id FROM locations WHERE archived_at IS NULL ORDER BY sort_order, id LIMIT 1")->fetchColumn();
    if (!$id) throw new BcrfStockError('No stock locations are set up. Add one in Stock › Locations.');
    return $id;
}

/** The location a register sells from (its own, or the default). */
function bcrf_location_for_register(PDO $pdo, ?int $registerId): int {
    if ($registerId) {
        $st = $pdo->prepare("SELECT r.location_id FROM registers r JOIN locations l ON l.id = r.location_id AND l.archived_at IS NULL WHERE r.id = ?");
        $st->execute([$registerId]);
        if ($id = (int)$st->fetchColumn()) return $id;
    }
    return bcrf_location_default($pdo);
}

/** Add or change a location (administrators). Returns its id. */
function bcrf_location_save(PDO $pdo, ?int $id, string $name, bool $sellable, bool $isDefault, bool $archived, ?int $uid): int {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '' || mb_strlen($name) > 80) throw new BcrfStockError('Give the location a name (up to 80 characters).');
    $pdo->beginTransaction();
    try {
        $old = $id ? bcrf_location($pdo, $id) : null;
        if ($id && !$old) throw new BcrfStockError('That location no longer exists.');
        if ($archived && $old) {
            if ((int)$old['is_default'] || $isDefault) throw new BcrfStockError('Make another location the default before archiving this one.');
            $st = $pdo->prepare("SELECT COUNT(*) FROM inventory_levels WHERE location_id = ? AND quantity <> 0");
            $st->execute([$id]);
            if ($n = (int)$st->fetchColumn()) throw new BcrfStockError($n . ' item' . ($n === 1 ? ' is' : 's are') . ' still stocked here. Move or count them to 0 first.');
            $st = $pdo->prepare("SELECT COUNT(*) FROM registers WHERE location_id = ? AND archived_at IS NULL");
            $st->execute([$id]);
            if ((int)$st->fetchColumn()) throw new BcrfStockError('A register sells from here. Point it at another location first.');
        }
        if ($isDefault && $archived) throw new BcrfStockError('The default location can\'t be archived.');
        if ($isDefault && !$sellable) throw new BcrfStockError('The default location must be sellable (registers fall back to it).');
        if (!$id) {
            $code = substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_'), 0, 24) ?: 'loc';
            $base = $code; $n = 1;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM locations WHERE code = ?");
            while (true) { $chk->execute([$code]); if (!(int)$chk->fetchColumn()) break; $code = $base . '_' . (++$n); }
            $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM locations")->fetchColumn();
            $pdo->prepare("INSERT INTO locations (code, name, sellable, is_default, sort_order, created_at, created_by) VALUES (?, ?, ?, 0, ?, UTC_TIMESTAMP(), ?)")
                ->execute([$code, $name, $sellable ? 1 : 0, $sort, $uid]);
            $id = (int)$pdo->lastInsertId();
        } else {
            if ((int)$old['is_default'] && !$isDefault) throw new BcrfStockError('Choose a new default by marking another location as the default.');
            $pdo->prepare("UPDATE locations SET name = ?, sellable = ?, archived_at = " . ($archived ? 'COALESCE(archived_at, UTC_TIMESTAMP())' : 'NULL')
                          . ", updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute([$name, $sellable ? 1 : 0, $uid, $id]);
        }
        if ($isDefault) {
            $pdo->prepare("UPDATE locations SET is_default = (id = ?)")->execute([$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    bcrf_stock_audit($pdo, $old ? 'location_update' : 'location_create', 'locations', $id, $name,
        $old ? ['name' => $old['name'], 'sellable' => (int)$old['sellable'], 'default' => (int)$old['is_default'], 'archived' => $old['archived_at'] !== null] : null,
        ['name' => $name, 'sellable' => $sellable, 'default' => $isDefault, 'archived' => $archived]);
    return $id;
}

/** Point a register at a location (administrators). */
function bcrf_register_set_location(PDO $pdo, int $registerId, int $locationId, ?int $uid): void {
    $loc = bcrf_location($pdo, $locationId);
    if (!$loc || $loc['archived_at'] !== null) throw new BcrfStockError('Pick an active location.');
    if (!(int)$loc['sellable']) throw new BcrfStockError($loc['name'] . ' is not sellable, so a register can\'t sell from it.');
    $st = $pdo->prepare("UPDATE registers SET location_id = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?");
    $st->execute([$locationId, $uid, $registerId]);
    bcrf_stock_audit($pdo, 'register_location', 'registers', $registerId, 'Register sells from ' . $loc['name'], null, ['location_id' => $locationId]);
}

// -- The one place stock changes --

/**
 * Lock a product and its levels (call inside a transaction). Repairs drift between inventory.stock_quantity and the levels
 * by putting the difference on the default location. Returns ['p' => product row, 'lv' => [location_id => thousandths]].
 */
function bcrf_stock_lock(PDO $pdo, int $productId, ?int $uid = null): array {
    $st = $pdo->prepare("SELECT id, name, sku, price, stock_quantity, track_inventory, unit FROM inventory WHERE id = ? FOR UPDATE");
    $st->execute([$productId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new BcrfStockError('That item no longer exists.');
    $st = $pdo->prepare("SELECT location_id, quantity FROM inventory_levels WHERE product_id = ? ORDER BY location_id FOR UPDATE");
    $st->execute([$productId]);
    $lv = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $lv[(int)$r['location_id']] = bcrf_qm($r['quantity']);
    $total = bcrf_qm($p['stock_quantity']);
    $sum = array_sum($lv);
    if ((int)$p['track_inventory'] === 1 && $total !== $sum) {
        $first = !$lv;   // stocked item with no levels yet (e.g. switched to tracked after migration 0015): its total is the opening balance
        $def = bcrf_location_default($pdo);
        $before = $lv[$def] ?? 0;
        $after = max(0, $before + ($total - $sum));
        $lv[$def] = $after;
        bcrf_stock_write_level($pdo, $productId, $def, $after);
        $newTotal = array_sum($lv);
        if ($newTotal !== $total) $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?")->execute([bcrf_qf($newTotal), $productId]);
        $p['stock_quantity'] = bcrf_qf($newTotal);
        bcrf_stock_ledger($pdo, $productId, $def, $after - $before, $before, $after, $first ? 'opening' : 'sync', 'sync', null,
            $first ? 'Opening balance (first time stocked)' : 'Total was ' . bcrf_qtxt(bcrf_qf($total)) . ', locations added up to ' . bcrf_qtxt(bcrf_qf($sum)) . ' (changed outside Stock: old register, Full Editor or connector)', $uid);
    }
    return ['p' => $p, 'lv' => $lv];
}

function bcrf_stock_write_level(PDO $pdo, int $productId, int $locationId, int $qm): void {
    $pdo->prepare("INSERT INTO inventory_levels (product_id, location_id, quantity, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3))
                   ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = VALUES(updated_at)")->execute([$productId, $locationId, bcrf_qf($qm)]);
}

function bcrf_stock_ledger(PDO $pdo, int $productId, int $locationId, int $changeM, int $beforeM, int $afterM, string $reason,
                           ?string $srcType, ?int $srcId, ?string $note, ?int $uid, ?int $unitCostCents = null): int {
    $pdo->prepare("INSERT INTO inventory_adjustments (product_id, location_id, quantity_change, quantity_before, quantity_after, reason,
                          source_type, source_id, unit_cost_cents, note, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?)")
        ->execute([$productId, $locationId, bcrf_qf($changeM), bcrf_qf($beforeM), bcrf_qf($afterM), substr($reason, 0, 20), $srcType, $srcId,
                   $unitCostCents, $note === null ? null : mb_substr($note, 0, 255), $uid]);
    return (int)$pdo->lastInsertId();
}

/**
 * Change one product's quantity at one location (call inside a transaction).
 * $mode: 'strict' refuses to go below zero; 'clamp' stops at zero (sales, as before) and notes the shortfall.
 * Returns ['before', 'after', 'change' (floats), 'id' (ledger row, 0 if nothing changed), 'short' (float)].
 */
function bcrf_stock_move(PDO $pdo, int $productId, int $locationId, float $delta, string $reason, ?string $srcType, ?int $srcId,
                         ?string $note, ?int $uid, string $mode = 'strict', ?array $locked = null, ?int $unitCostCents = null): array {
    $L = $locked ?? bcrf_stock_lock($pdo, $productId, $uid);
    $before = $L['lv'][$locationId] ?? 0;
    $d = bcrf_qm($delta);
    $after = $before + $d;
    $short = 0;
    if ($after < 0) {
        if ($mode !== 'clamp') {
            $loc = bcrf_location($pdo, $locationId);
            throw new BcrfStockError('Only ' . bcrf_qtxt(bcrf_qf($before)) . ' of "' . $L['p']['name'] . '" at ' . ($loc['name'] ?? 'that location') . '.');
        }
        $short = -$after; $after = 0;
    }
    if (bcrf_qf($after) > 1000000) throw new BcrfStockError('That would put over 1,000,000 on hand; check the number.');
    bcrf_stock_write_level($pdo, $productId, $locationId, $after);
    $total = array_sum($L['lv']) - $before + $after;
    $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?")->execute([bcrf_qf($total), $productId]);
    if ($short > 0) $note = trim(($note ?? '') . ' (short by ' . bcrf_qtxt(bcrf_qf($short)) . ')');
    $id = ($after !== $before || $short > 0)
        ? bcrf_stock_ledger($pdo, $productId, $locationId, $after - $before, $before, $after, $reason, $srcType, $srcId, $note, $uid, $unitCostCents)
        : 0;
    return ['before' => bcrf_qf($before), 'after' => bcrf_qf($after), 'change' => bcrf_qf($after - $before), 'id' => $id, 'short' => bcrf_qf($short)];
}

function bcrf_stock_audit(PDO $pdo, string $action, string $type, $id, string $label, ?array $before, ?array $after): void {
    if (!function_exists('bcrf_audit_event') || !function_exists('bcrf_audit_actor')) return;
    [$t, $aid, $name, $ip] = bcrf_audit_actor();
    bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => $action,
        'object_type' => $type, 'object_id' => (string)$id, 'object_label' => $label, 'before' => $before, 'after' => $after]);
}

function bcrf_stock_active_location(PDO $pdo, int $locationId): array {
    $loc = bcrf_location($pdo, $locationId);
    if (!$loc || $loc['archived_at'] !== null) throw new BcrfStockError('Pick an active location.');
    return $loc;
}

function bcrf_stock_parse_qty($raw, bool $allowZero = false): float {
    $s = trim(str_replace(',', '', (string)$raw));
    if ($s === '' || !is_numeric($s)) throw new BcrfStockError('Enter a number.');
    $q = round((float)$s, 3);
    if ($q < 0 || (!$allowZero && $q == 0)) throw new BcrfStockError($allowZero ? 'The number can\'t be negative.' : 'Enter a quantity above 0.');
    if ($q > 1000000) throw new BcrfStockError('That number looks wrong (over 1,000,000).');
    return $q;
}

/** Manual adjustment. $qty is positive; $direction (+1/-1) only matters for 'correction'. */
function bcrf_stock_adjust(PDO $pdo, int $productId, int $locationId, $qty, string $reason, int $direction, string $note, ?int $uid): array {
    $reasons = bcrf_stock_manual_reasons();
    if (!isset($reasons[$reason])) throw new BcrfStockError('Pick a reason.');
    $q = bcrf_stock_parse_qty($qty);
    $sign = $reasons[$reason][1] ?: ($direction < 0 ? -1 : 1);
    $note = trim($note);
    if (in_array($reason, ['correction', 'shrink'], true) && $note === '') throw new BcrfStockError('Add a note for ' . strtolower($reasons[$reason][0]) . 's.');
    $loc = bcrf_stock_active_location($pdo, $locationId);
    $pdo->beginTransaction();
    try {
        $L = bcrf_stock_lock($pdo, $productId, $uid);
        if ((int)$L['p']['track_inventory'] !== 1) throw new BcrfStockError('"' . $L['p']['name'] . '" isn\'t a stocked item, so it has no quantity to change.');
        $r = bcrf_stock_move($pdo, $productId, $locationId, $sign * $q, $reason, 'manual', null, $note !== '' ? $note : null, $uid, 'strict', $L);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    bcrf_stock_audit($pdo, 'stock_adjust', 'inventory', $productId,
        $L['p']['name'] . ' at ' . $loc['name'] . ': ' . ($sign > 0 ? '+' : '−') . bcrf_qtxt($q) . ' (' . $reasons[$reason][0] . ')' . ($note !== '' ? ' ' . mb_substr($note, 0, 80) : ''),
        ['quantity' => $r['before']], ['quantity' => $r['after'], 'reason' => $reason]);
    return $r + ['name' => $L['p']['name'], 'location' => $loc['name']];
}

/** Move stock between two locations. */
function bcrf_stock_transfer(PDO $pdo, int $productId, int $from, int $to, $qty, string $note, ?int $uid): array {
    if ($from === $to) throw new BcrfStockError('Pick two different locations.');
    $q = bcrf_stock_parse_qty($qty);
    $lf = bcrf_stock_active_location($pdo, $from);
    $lt = bcrf_stock_active_location($pdo, $to);
    $note = trim($note);
    $pdo->beginTransaction();
    try {
        $L = bcrf_stock_lock($pdo, $productId, $uid);
        if ((int)$L['p']['track_inventory'] !== 1) throw new BcrfStockError('"' . $L['p']['name'] . '" isn\'t a stocked item.');
        $out = bcrf_stock_move($pdo, $productId, $from, -$q, 'transfer_out', 'transfer', null, 'To ' . $lt['name'] . ($note !== '' ? ': ' . $note : ''), $uid, 'strict', $L);
        $L['lv'][$from] = bcrf_qm($out['after']);
        $in = bcrf_stock_move($pdo, $productId, $to, $q, 'transfer_in', 'transfer', $out['id'], 'From ' . $lf['name'] . ($note !== '' ? ': ' . $note : ''), $uid, 'strict', $L);
        $pdo->prepare("UPDATE inventory_adjustments SET source_id = ? WHERE id = ?")->execute([$out['id'], $out['id']]);   // both rows point at the move-out row
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    bcrf_stock_audit($pdo, 'stock_transfer', 'inventory', $productId, $L['p']['name'] . ': ' . bcrf_qtxt($q) . ' from ' . $lf['name'] . ' to ' . $lt['name'],
        [$lf['name'] => $out['before'], $lt['name'] => $in['before']], [$lf['name'] => $out['after'], $lt['name'] => $in['after']]);
    return ['name' => $L['p']['name'], 'from' => $lf['name'], 'to' => $lt['name'], 'qty' => $q, 'from_after' => $out['after'], 'to_after' => $in['after']];
}

/** Repair drift for every stocked product whose total no longer matches its levels. Returns how many were repaired. */
function bcrf_stock_sync_all(PDO $pdo, ?int $uid = null): int {
    $ids = $pdo->query("SELECT i.id FROM inventory i LEFT JOIN (SELECT product_id, SUM(quantity) s FROM inventory_levels GROUP BY product_id) l ON l.product_id = i.id
                        WHERE i.track_inventory = 1 AND ROUND(i.stock_quantity, 3) <> ROUND(COALESCE(l.s, 0), 3)")->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try { bcrf_stock_lock($pdo, (int)$id, $uid); $pdo->commit(); $n++; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('[stock] sync ' . $id . ': ' . $e->getMessage()); }
    }
    return $n;
}

/** Set the quantity at a location to a counted number (call inside a transaction). Used by count sessions and the quick count screen. */
function bcrf_stock_set_count(PDO $pdo, int $productId, int $locationId, float $counted, ?int $countId, ?int $uid, ?array $locked = null): array {
    $L = $locked ?? bcrf_stock_lock($pdo, $productId, $uid);
    $before = $L['lv'][$locationId] ?? 0;
    $r = bcrf_stock_move($pdo, $productId, $locationId, bcrf_qf(bcrf_qm($counted) - $before), 'count', $countId ? 'count' : 'quick_count', $countId,
                         null, $uid, 'strict', $L);
    return $r + ['had_level' => array_key_exists($locationId, $L['lv']), 'p' => $L['p']];
}

// -- Reading --

/** Stocked products with their quantity at each location. Filters: q, category_id, location_id, low (total at or below minimum). */
function bcrf_stock_levels(PDO $pdo, array $f = []): array {
    $w = ["i.track_inventory = 1", "i.is_active = 1"]; $a = [];
    if (($f['q'] ?? '') !== '') { $w[] = "(i.name LIKE ? OR i.sku = ?)"; $a[] = '%' . $f['q'] . '%'; $a[] = $f['q']; }
    if (!empty($f['category_id'])) { $w[] = "i.category_id = ?"; $a[] = (int)$f['category_id']; }
    if (!empty($f['low'])) $w[] = "i.min_stock_level IS NOT NULL AND i.min_stock_level > 0 AND i.stock_quantity <= i.min_stock_level";
    if (!empty($f['location_id'])) { $w[] = "EXISTS (SELECT 1 FROM inventory_levels x WHERE x.product_id = i.id AND x.location_id = ?)"; $a[] = (int)$f['location_id']; }
    $st = $pdo->prepare("SELECT i.id, i.name, i.sku, i.price, i.unit, i.stock_quantity, i.min_stock_level, COALESCE(ic.name, i.category, 'Other') AS cat
                         FROM inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id
                         WHERE " . implode(' AND ', $w) . " ORDER BY COALESCE(ic.sort_order, 999), cat, i.name LIMIT 1000");
    $st->execute($a);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['levels'] = []; $rows[(int)$r['id']] = $r; }
    if ($rows) {
        $in = implode(',', array_keys($rows));
        foreach ($pdo->query("SELECT product_id, location_id, quantity FROM inventory_levels WHERE product_id IN ($in)") as $l) {
            $rows[(int)$l['product_id']]['levels'][(int)$l['location_id']] = (float)$l['quantity'];
        }
    }
    return array_values($rows);
}

/** One product with its levels, or null. */
function bcrf_stock_product(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT i.id, i.name, i.sku, i.price, i.unit, i.stock_quantity, i.min_stock_level, i.track_inventory, i.is_active,
                                COALESCE(ic.name, i.category, 'Other') AS cat
                         FROM inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id WHERE i.id = ?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    $p['levels'] = [];
    $st = $pdo->prepare("SELECT location_id, quantity FROM inventory_levels WHERE product_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) $p['levels'][(int)$l['location_id']] = (float)$l['quantity'];
    return $p;
}

/** Ledger rows, newest first. Filters: from/to (farm dates), product_id, location_id, reason. */
function bcrf_stock_history(PDO $pdo, array $f = [], int $limit = 300): array {
    $w = []; $a = [];
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $utc = fn(string $d, string $t) => (new DateTime($d . ' ' . $t, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    if (!empty($f['from'])) { $w[] = "a.created_at >= ?"; $a[] = $utc($f['from'], '00:00:00'); }
    if (!empty($f['to']))   { $w[] = "a.created_at < ?";  $a[] = $utc(date('Y-m-d', strtotime($f['to'] . ' +1 day')), '00:00:00'); }
    if (!empty($f['product_id']))  { $w[] = "a.product_id = ?";  $a[] = (int)$f['product_id']; }
    if (!empty($f['location_id'])) { $w[] = "a.location_id = ?"; $a[] = (int)$f['location_id']; }
    if (!empty($f['reason']))      { $w[] = "a.reason = ?";      $a[] = (string)$f['reason']; }
    $st = $pdo->prepare("SELECT a.*, i.name AS product_name, i.unit, l.name AS location_name,
                                CASE WHEN a.source_type = 'sale' THEN (SELECT sale_number FROM sales WHERE id = a.source_id)
                                     WHEN a.source_type = 'refund' THEN (SELECT refund_number FROM refunds WHERE id = a.source_id) END AS source_label
                         FROM inventory_adjustments a JOIN inventory i ON i.id = a.product_id LEFT JOIN locations l ON l.id = a.location_id"
                         . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, min(5000, $limit)));
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// -- Count sessions --

function bcrf_count_scopes(): array {
    return ['all' => 'Everything at this location', 'category' => 'One category', 'low' => 'Items at or below their minimum'];
}

function bcrf_count_start(PDO $pdo, int $locationId, string $scope, ?int $categoryId, bool $full, string $note, ?int $uid): int {
    $loc = bcrf_stock_active_location($pdo, $locationId);
    if (!isset(bcrf_count_scopes()[$scope])) throw new BcrfStockError('Pick what to count.');
    if ($scope === 'category' && !$categoryId) throw new BcrfStockError('Pick a category.');
    if ($scope !== 'category') $categoryId = null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("SELECT id FROM locations WHERE id = ? FOR UPDATE")->execute([$locationId]);   // one open count per location
        $st = $pdo->prepare("SELECT id FROM inventory_counts WHERE location_id = ? AND status = 'open' LIMIT 1");
        $st->execute([$locationId]);
        if ($open = (int)$st->fetchColumn()) { $pdo->rollBack(); throw new BcrfStockError('A count is already open for ' . $loc['name'] . '. Carry on with it or finish it first.', $open); }
        $pdo->prepare("INSERT INTO inventory_counts (location_id, scope, category_id, full_count, status, note, started_at, started_by) VALUES (?, ?, ?, ?, 'open', ?, UTC_TIMESTAMP(3), ?)")
            ->execute([$locationId, $scope, $categoryId, $full ? 1 : 0, trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null, $uid]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    bcrf_stock_audit($pdo, 'count_start', 'inventory_counts', $id, 'Count started at ' . $loc['name'] . ' (' . bcrf_count_scopes()[$scope] . ($full ? ', full' : ', partial') . ')', null, null);
    return $id;
}

function bcrf_count_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT c.*, l.name AS location_name, ic.name AS category_name,
                                (SELECT COUNT(*) FROM inventory_count_lines x WHERE x.count_id = c.id) AS lines_n,
                                (SELECT COUNT(*) FROM inventory_count_lines x WHERE x.count_id = c.id AND x.flagged = 1) AS flagged_n,
                                (SELECT COUNT(*) FROM inventory_count_lines x WHERE x.count_id = c.id AND x.difference <> 0) AS diff_n
                         FROM inventory_counts c JOIN locations l ON l.id = c.location_id LEFT JOIN inventory_categories ic ON ic.id = c.category_id WHERE c.id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bcrf_counts_list(PDO $pdo, int $limit = 30): array {
    return $pdo->query("SELECT c.*, l.name AS location_name, ic.name AS category_name,
                               (SELECT COUNT(*) FROM inventory_count_lines x WHERE x.count_id = c.id) AS lines_n,
                               (SELECT COUNT(*) FROM inventory_count_lines x WHERE x.count_id = c.id AND x.flagged = 1) AS flagged_n,
                               (SELECT COUNT(*) FROM inventory_count_lines x WHERE x.count_id = c.id AND x.difference <> 0) AS diff_n
                        FROM inventory_counts c JOIN locations l ON l.id = c.location_id LEFT JOIN inventory_categories ic ON ic.id = c.category_id
                        ORDER BY c.status = 'open' DESC, c.started_at DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Items in a count's scope. Without a search: stocked items that have a level at the location. With a search ($q): any stocked
 * item matching by name or SKU, so staff can count something here for the first time. Each item has on_hand and its line (if counted).
 */
function bcrf_count_items(PDO $pdo, array $c, string $q = ''): array {
    $w = ["i.track_inventory = 1", "i.is_active = 1"]; $wa = [];
    if ($c['scope'] === 'category') { $w[] = "i.category_id = ?"; $wa[] = (int)$c['category_id']; }
    if ($c['scope'] === 'low') $w[] = "i.min_stock_level IS NOT NULL AND i.min_stock_level > 0 AND i.stock_quantity <= i.min_stock_level";
    if ($q !== '') { $w[] = "(i.name LIKE ? OR i.sku = ?)"; $wa[] = '%' . $q . '%'; $wa[] = $q; }
    else $w[] = "(l.product_id IS NOT NULL OR cl.id IS NOT NULL)";
    $st = $pdo->prepare("SELECT i.id, i.name, i.sku, i.unit, i.price, COALESCE(ic.name, i.category, 'Other') AS cat, COALESCE(l.quantity, 0) AS on_hand,
                                (l.product_id IS NOT NULL) AS stocked_here, cl.counted_qty, cl.expected_qty, cl.difference, cl.flagged, cl.counted_at
                         FROM inventory i
                         LEFT JOIN inventory_categories ic ON ic.id = i.category_id
                         LEFT JOIN inventory_levels l ON l.product_id = i.id AND l.location_id = ?
                         LEFT JOIN inventory_count_lines cl ON cl.product_id = i.id AND cl.count_id = ?
                         WHERE " . implode(' AND ', $w) . " ORDER BY COALESCE(ic.sort_order, 999), cat, i.name LIMIT 500");
    $st->execute(array_merge([(int)$c['location_id'], (int)$c['id']], $wa));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Is a count difference big enough to flag? (06 › Alerts: over count_flag_pct % or count_flag_cents in value.) */
function bcrf_count_is_flagged(PDO $pdo, int $expectedM, int $diffM, float $price, bool $hadLevel): bool {
    if ($diffM === 0 || !$hadLevel) return false;
    $pct = bcrf_stock_setting($pdo, 'count_flag_pct', 20);
    $cents = bcrf_stock_setting($pdo, 'count_flag_cents', 2500);
    if ($expectedM > 0 && $pct > 0 && abs($diffM) * 100 > $pct * $expectedM) return true;
    if ($expectedM <= 0) return true;
    $value = (int)round(abs($diffM) / 1000 * $price * 100);
    return $cents > 0 && $value > $cents;
}

/**
 * Save counted quantities: [$productId => '12'] (blank entries are skipped). Each writes a count adjustment for the difference
 * at the count's location right away. Counting the same item again in the same session adjusts again; the line keeps the
 * first expected number and the running difference. Returns ['saved' => n, 'changed' => n, 'flagged' => [names]].
 */
function bcrf_count_save(PDO $pdo, int $countId, array $entries, ?int $uid): array {
    $vals = [];
    foreach ($entries as $pid => $raw) {
        if (trim((string)$raw) === '') continue;
        try { $vals[(int)$pid] = bcrf_stock_parse_qty($raw, true); }
        catch (BcrfStockError $e) { throw new BcrfStockError('Check the number you typed (' . htmlspecialchars((string)$raw) . '): ' . $e->getMessage()); }
    }
    if (!$vals) throw new BcrfStockError('Type at least one count.');
    $out = ['saved' => 0, 'changed' => 0, 'flagged' => []];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM inventory_counts WHERE id = ? FOR UPDATE");
        $st->execute([$countId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new BcrfStockError('That count no longer exists.');
        if ($c['status'] !== 'open') throw new BcrfStockError('That count is finished. Start a new one to count again.');
        ksort($vals);   // same lock order every time
        $getLine = $pdo->prepare("SELECT * FROM inventory_count_lines WHERE count_id = ? AND product_id = ?");
        foreach ($vals as $pid => $q) {
            $L = bcrf_stock_lock($pdo, $pid, $uid);
            if ((int)$L['p']['track_inventory'] !== 1) throw new BcrfStockError('"' . $L['p']['name'] . '" isn\'t a stocked item.');
            $r = bcrf_stock_set_count($pdo, $pid, (int)$c['location_id'], $q, $countId, $uid, $L);
            $getLine->execute([$countId, $pid]);
            $line = $getLine->fetch(PDO::FETCH_ASSOC);
            $expectedM = $line ? bcrf_qm($line['expected_qty']) : bcrf_qm($r['before']);
            $diffM = ($line ? bcrf_qm($line['difference']) : 0) + bcrf_qm($r['change']);
            $newHere = $line ? (int)$line['new_here'] === 1 : !$r['had_level'];   // first time stocked at this location: nothing to compare with, never flagged
            $flag = bcrf_count_is_flagged($pdo, $expectedM, $diffM, (float)$L['p']['price'], !$newHere);
            if ($line) {
                $pdo->prepare("UPDATE inventory_count_lines SET counted_qty = ?, difference = ?, flagged = ?, adjustment_id = COALESCE(?, adjustment_id), counted_at = UTC_TIMESTAMP(3), counted_by = ? WHERE id = ?")
                    ->execute([$q, bcrf_qf($diffM), $flag ? 1 : 0, $r['id'] ?: null, $uid, $line['id']]);
            } else {
                $pdo->prepare("INSERT INTO inventory_count_lines (count_id, product_id, expected_qty, counted_qty, difference, flagged, new_here, adjustment_id, counted_at, counted_by)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?)")
                    ->execute([$countId, $pid, bcrf_qf($expectedM), $q, bcrf_qf($diffM), $flag ? 1 : 0, $newHere ? 1 : 0, $r['id'] ?: null, $uid]);
            }
            $out['saved']++;
            if ($r['id']) $out['changed']++;
            if ($flag) $out['flagged'][] = $L['p']['name'];
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $out;
}

/** Items a full count would set to 0 on finishing: stocked here, not zero, not counted yet. */
function bcrf_count_uncounted(PDO $pdo, array $c): array {
    return array_values(array_filter(bcrf_count_items($pdo, $c), fn($i) => $i['counted_qty'] === null && (float)$i['on_hand'] != 0));
}

/** Finish a count. $asFull: set every item in scope that wasn't counted to 0 (a full count); otherwise leave them as they are. */
function bcrf_count_close(PDO $pdo, int $countId, bool $asFull, ?int $uid): array {
    $c = bcrf_count_get($pdo, $countId);
    if (!$c) throw new BcrfStockError('That count no longer exists.');
    if ($c['status'] !== 'open') throw new BcrfStockError('That count is already finished.');
    $zeroed = 0;
    if ($asFull) {
        $todo = [];
        foreach (bcrf_count_uncounted($pdo, $c) as $i) $todo[(int)$i['id']] = '0';
        if ($todo) { $r = bcrf_count_save($pdo, $countId, $todo, $uid); $zeroed = $r['saved']; }
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE inventory_counts SET status = 'closed', full_count = ?, closed_at = UTC_TIMESTAMP(3), closed_by = ? WHERE id = ? AND status = 'open'");
        $st->execute([$asFull ? 1 : 0, $uid, $countId]);
        if (!$st->rowCount()) throw new BcrfStockError('That count is already finished.');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    $c = bcrf_count_get($pdo, $countId);
    bcrf_stock_audit($pdo, 'count_close', 'inventory_counts', $countId,
        'Count finished at ' . $c['location_name'] . ': ' . $c['lines_n'] . ' counted, ' . $c['diff_n'] . ' changed, ' . $c['flagged_n'] . ' flagged' . ($zeroed ? ', ' . $zeroed . ' not counted set to 0' : ''),
        null, ['full' => $asFull, 'counted' => (int)$c['lines_n'], 'changed' => (int)$c['diff_n'], 'flagged' => (int)$c['flagged_n'], 'zeroed' => $zeroed]);
    if (function_exists('bcrf_event')) bcrf_event('inventory.count_closed', 'inventory_counts', $countId,
        ['location' => $c['location_name'], 'counted' => (int)$c['lines_n'], 'changed' => (int)$c['diff_n'], 'flagged' => (int)$c['flagged_n']]);
    return $c + ['zeroed' => $zeroed];
}

/** Lines of a count with their product names, differences first. */
function bcrf_count_lines(PDO $pdo, int $countId): array {
    $st = $pdo->prepare("SELECT cl.*, i.name, i.unit, i.price FROM inventory_count_lines cl JOIN inventory i ON i.id = cl.product_id
                         WHERE cl.count_id = ? ORDER BY cl.flagged DESC, cl.difference = 0, i.name");
    $st->execute([$countId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Flagged count differences in the last $days days (dashboard Action Center). */
function bcrf_count_flags_recent(PDO $pdo, int $days = 7): array {
    if (!bcrf_stock_ready($pdo)) return [];
    $st = $pdo->prepare("SELECT cl.count_id, cl.difference, cl.expected_qty, cl.counted_qty, i.name, l.name AS location_name
                         FROM inventory_count_lines cl JOIN inventory_counts c ON c.id = cl.count_id JOIN inventory i ON i.id = cl.product_id JOIN locations l ON l.id = c.location_id
                         WHERE cl.flagged = 1 AND cl.counted_at >= UTC_TIMESTAMP() - INTERVAL ? DAY ORDER BY cl.counted_at DESC LIMIT 50");
    $st->execute([$days]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
