<?php
// includes/thrift.php — thrift intake (Farm OS Phase 2, slice 2.3, Nov 9, 2026; 05 › Thrift)
// Each thrift piece is one inventory row: product_type thrift_item, track_inventory 1, quantity 1.
//   bcrf_thrift_ready / sources / conditions / statuses
//   bcrf_thrift_intake — create item + opening stock 1 at a location
//   bcrf_thrift_list / get
// Consignor settlements and returns screens come in a later slice; consigned items store customer id + share bp now.
declare(strict_types=1);

if (!function_exists('bcrf_thrift_ready')) {

require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/vendors.php';

function bcrf_thrift_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                               AND table_name = 'inventory' AND column_name = 'thrift_source'")->fetchColumn();
        return $ready = ($c === 1 && bcrf_stock_ready($pdo));
    } catch (Throwable $e) { return $ready = false; }
}

function bcrf_thrift_sources(): array {
    return [
        'donated'   => 'Donated',
        'consigned' => 'Consigned',
        'owned'     => 'Owned (bought / farm)',
    ];
}

function bcrf_thrift_conditions(): array {
    return ['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'as_is' => 'As-is'];
}

function bcrf_thrift_statuses(): array {
    return [
        'available'  => 'For sale',
        'sold'       => 'Sold',
        'returned'   => 'Returned to consignor',
        'withdrawn'  => 'Withdrawn',
    ];
}

function bcrf_thrift_next_sku(PDO $pdo): string {
    $n = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(sku, 5) AS UNSIGNED)), 0) FROM inventory WHERE sku REGEXP '^THR-[0-9]+$'")->fetchColumn();
    return 'THR-' . str_pad((string)($n + 1), 5, '0', STR_PAD_LEFT);
}

/** Prefer a location named Thrift / Thrift Shop; else default. */
function bcrf_thrift_location_id(PDO $pdo): int {
    foreach (bcrf_locations($pdo) as $l) {
        $n = strtolower((string)$l['name']);
        if (str_contains($n, 'thrift')) return (int)$l['id'];
    }
    return bcrf_location_default($pdo);
}

function bcrf_thrift_list(PDO $pdo, array $f = [], int $limit = 200): array {
    $w = ["i.product_type = 'thrift_item'"]; $a = [];
    $status = $f['status'] ?? 'available';
    if ($status === 'available') $w[] = "i.thrift_status = 'available' AND i.is_active = 1";
    elseif ($status === 'sold') $w[] = "i.thrift_status = 'sold'";
    elseif ($status === 'all') { /* no status filter */ }
    else { $w[] = 'i.thrift_status = ?'; $a[] = $status; }
    if (($f['q'] ?? '') !== '') {
        $w[] = '(i.name LIKE ? OR i.sku = ?)';
        $a[] = '%' . $f['q'] . '%'; $a[] = $f['q'];
    }
    if (!empty($f['source'])) { $w[] = 'i.thrift_source = ?'; $a[] = $f['source']; }
    $st = $pdo->prepare("SELECT i.id, i.name, i.sku, i.price, i.image_url, i.thrift_source, i.thrift_condition, i.thrift_status,
                                i.thrift_intake_at, i.consignor_share_bp, i.stock_quantity, i.is_active,
                                COALESCE(ic.name, i.category, 'Thrift') AS cat
                         FROM inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id
                         WHERE " . implode(' AND ', $w) . "
                         ORDER BY i.thrift_intake_at DESC, i.id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_thrift_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT i.*, ic.name AS category_name,
                                TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) AS consignor_name
                         FROM inventory i
                         LEFT JOIN inventory_categories ic ON ic.id = i.category_id
                         LEFT JOIN customers c ON c.id = i.consignor_customer_id
                         WHERE i.id = ? AND i.product_type = 'thrift_item'");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Intake one thrift item. $in: name, price, source, condition, category_id, location_id,
 * description, image_url, cost (owned only, dollars), consignor_customer_id, consignor_share_pct (default 60).
 * Returns product id.
 */
function bcrf_thrift_intake(PDO $pdo, array $in, ?int $uid): int {
    if (!bcrf_thrift_ready($pdo)) throw new InvalidArgumentException('Run migration 0020 in Settings › Database first.');
    $bad = fn(string $m) => throw new InvalidArgumentException($m);

    $name = mb_substr(trim(str_replace("\r", '', (string)($in['name'] ?? ''))), 0, 255);
    if ($name === '') $bad('Give the item a title.');

    $source = (string)($in['source'] ?? '');
    if (!isset(bcrf_thrift_sources()[$source])) $bad('Pick donated, consigned, or owned.');

    $cond = (string)($in['condition'] ?? 'good');
    if (!isset(bcrf_thrift_conditions()[$cond])) $cond = 'good';

    $priceS = trim(str_replace([',', '$'], '', (string)($in['price'] ?? '')));
    if ($priceS === '' || !is_numeric($priceS)) $bad('Enter a price.');
    $price = round((float)$priceS, 2);
    if ($price < 0 || $price > 100000) $bad('Price must be between $0 and $100,000.');

    $cat = (int)($in['category_id'] ?? 0) ?: null;
    if ($cat) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM inventory_categories WHERE id = ?');
        $st->execute([$cat]);
        if (!(int)$st->fetchColumn()) $bad('Pick a category.');
    } else {
        // Prefer Thrift category if present
        $cat = (int)$pdo->query("SELECT id FROM inventory_categories WHERE slug = 'thrift' OR name = 'Thrift' ORDER BY id LIMIT 1")->fetchColumn() ?: null;
    }

    $locationId = (int)($in['location_id'] ?? 0) ?: bcrf_thrift_location_id($pdo);
    bcrf_stock_active_location($pdo, $locationId);

    $desc = mb_substr(trim(str_replace("\r", '', (string)($in['description'] ?? ''))), 0, 5000) ?: null;
    $img = mb_substr(trim((string)($in['image_url'] ?? '')), 0, 255) ?: null;
    if ($img !== null && !preg_match('#^(https://|/)[^\s"<>]+$#', $img)) $bad('Photo must be https://… or a /path.');

    $consignorId = null;
    $shareBp = null;
    if ($source === 'consigned') {
        $consignorId = (int)($in['consignor_customer_id'] ?? 0) ?: null;
        // Optional for this slice — full consignor profiles later
        if ($consignorId) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM customers WHERE id = ?');
            $st->execute([$consignorId]);
            if (!(int)$st->fetchColumn()) $bad('That consignor is not in customers.');
        }
        $pct = (float)($in['consignor_share_pct'] ?? 60);
        if ($pct < 0 || $pct > 100) $bad('Consignor share must be 0–100%.');
        $shareBp = (int)round($pct * 100);
    }

    $costCents = null;
    if ($source === 'owned') {
        $cs = trim(str_replace([',', '$'], '', (string)($in['cost'] ?? '')));
        if ($cs !== '') {
            if (!is_numeric($cs)) $bad('Check the cost.');
            $costCents = (int)round((float)$cs * 100);
            if ($costCents < 0 || $costCents > 100000000) $bad('Cost must be between $0 and $1,000,000.');
        }
    } elseif ($source === 'donated') {
        $costCents = 0;
    }
    // consigned: no farm cost

    $taxId = null;
    foreach ($pdo->query("SELECT id, code FROM tax_categories WHERE archived_at IS NULL") as $tc) {
        if ($tc['code'] === 'general') $taxId = (int)$tc['id'];
    }

    $pdo->beginTransaction();
    try {
        $sku = bcrf_thrift_next_sku($pdo);
        $legacy = bcrf_product_legacy_category($pdo, $cat, 'Merchandise');

        $pdo->prepare("INSERT INTO inventory (
                name, description, image_url, sku, price, product_type, pricing_mode, unit,
                category_id, category, tax_category_id, track_inventory, stock_quantity, is_active,
                thrift_source, thrift_condition, thrift_status, thrift_intake_at, thrift_intake_by,
                consignor_customer_id, consignor_share_bp, avg_cost_cents
            ) VALUES (
                ?,?,?,?,?, 'thrift_item', 'each', 'each',
                ?,?,?, 1, 0, 1,
                ?,?, 'available', UTC_TIMESTAMP(), ?,
                ?,?, ?
            )")->execute([
            $name, $desc, $img, $sku, $price,
            $cat, $legacy, $taxId,
            $source, $cond, $uid,
            $consignorId, $shareBp, $costCents,
        ]);
        $id = (int)$pdo->lastInsertId();

        // Opening stock of 1 at the chosen location
        $L = bcrf_stock_lock($pdo, $id, $uid);
        bcrf_stock_move($pdo, $id, $locationId, 1.0, 'opening', 'thrift_intake', $id,
            'Thrift intake (' . $source . ')', $uid, 'strict', $L, $costCents);

        if ($costCents !== null && function_exists('bcrf_product_set_cost') && bcrf_vendors_ready($pdo)) {
            // set_cost may no-op if same; avg already set on insert — still write history
            $pdo->prepare('UPDATE inventory SET avg_cost_cents = ? WHERE id = ?')->execute([$costCents, $id]);
            $pdo->prepare('INSERT INTO product_cost_history (product_id, cost_cents, previous_cents, quantity_on_hand, source, source_id, note, created_at, created_by)
                           VALUES (?, ?, NULL, 1, ?, ?, ?, UTC_TIMESTAMP(3), ?)')
                ->execute([$id, $costCents, 'opening', $id, 'Thrift intake', $uid]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$t, $aid, $label, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, [
            'actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $label, 'ip' => $ip,
            'action' => 'create', 'object_type' => 'inventory', 'object_id' => (string)$id,
            'object_label' => 'Thrift intake: ' . $name . ' (' . $source . ', $' . number_format($price, 2) . ')',
            'before' => null,
            'after' => ['name' => $name, 'source' => $source, 'price' => $price, 'sku' => $sku],
        ]);
    }
    if (function_exists('bcrf_event')) bcrf_event('thrift.intake', 'inventory', $id, ['source' => $source, 'name' => $name]);
    return $id;
}

/** Mark thrift item sold (called from sale_complete when stock hits 0). */
function bcrf_thrift_mark_sold(PDO $pdo, int $productId): void {
    if (!bcrf_thrift_ready($pdo)) return;
    $pdo->prepare("UPDATE inventory SET thrift_status = 'sold', is_active = 0
                   WHERE id = ? AND product_type = 'thrift_item' AND thrift_status = 'available'")->execute([$productId]);
}

}
