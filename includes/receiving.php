<?php
// includes/receiving.php — receive stock with unit cost (Farm OS Phase 2, slice 2.2, Nov 9, 2026; 06 Purchasing & Inventory)
//   bcrf_receives_ready($pdo)
//   bcrf_receive_save($pdo, $in, $uid)  — one delivery, many lines; updates stock + moving average
//   bcrf_receive_get / bcrf_receive_list
declare(strict_types=1);

if (!function_exists('bcrf_receives_ready')) {

require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/vendors.php';
if (!function_exists('bcrf_muldiv')) require_once __DIR__ . '/sales.php';

function bcrf_receives_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('receives','receive_lines')")->fetchColumn();
        return $ready = ($n === 2 && bcrf_stock_ready($pdo) && bcrf_vendors_ready($pdo));
    } catch (Throwable $e) { return $ready = false; }
}

function bcrf_receive_list(PDO $pdo, array $f = [], int $limit = 50): array {
    $w = []; $a = [];
    if (!empty($f['from'])) { $w[] = 'r.business_date >= ?'; $a[] = $f['from']; }
    if (!empty($f['to']))   { $w[] = 'r.business_date <= ?'; $a[] = $f['to']; }
    if (!empty($f['vendor_id'])) { $w[] = 'r.vendor_id = ?'; $a[] = (int)$f['vendor_id']; }
    $st = $pdo->prepare("SELECT r.*, v.name AS vendor_name, l.name AS location_name,
                                (SELECT COUNT(*) FROM receive_lines x WHERE x.receive_id = r.id) AS lines_n,
                                (SELECT COALESCE(SUM(line_cost_cents), 0) FROM receive_lines x WHERE x.receive_id = r.id) AS total_cents
                         FROM receives r
                         LEFT JOIN vendors v ON v.id = r.vendor_id
                         LEFT JOIN locations l ON l.id = r.location_id"
                         . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                         . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_receive_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT r.*, v.name AS vendor_name, l.name AS location_name
                         FROM receives r
                         LEFT JOIN vendors v ON v.id = r.vendor_id
                         LEFT JOIN locations l ON l.id = r.location_id
                         WHERE r.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $st = $pdo->prepare("SELECT rl.*, i.name AS product_name, i.unit, i.sku
                         FROM receive_lines rl JOIN inventory i ON i.id = rl.product_id
                         WHERE rl.receive_id = ? ORDER BY rl.line_no");
    $st->execute([$id]);
    $r['lines'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $r;
}

/**
 * Save a receive. $in keys:
 *   vendor_id (optional), location_id, invoice_ref, note, po_id (optional — attach to a purchase order),
 *   lines: [ ['product_id'=>, 'qty'=>, 'unit_cost'=> dollars or 'unit_cost_cents'=>, 'note'=>?], ... ]
 * Throws InvalidArgumentException / BcrfStockError.
 * Returns receive id.
 */
function bcrf_receive_save(PDO $pdo, array $in, ?int $uid): int {
    if (!bcrf_receives_ready($pdo)) throw new InvalidArgumentException('Run migration 0019 in Settings › Database first.');

    $bad = fn(string $m) => throw new InvalidArgumentException($m);
    $locationId = (int)($in['location_id'] ?? 0);
    if (!$locationId) $bad('Pick a location.');
    $loc = bcrf_stock_active_location($pdo, $locationId);

    $vendorId = (int)($in['vendor_id'] ?? 0) ?: null;
    if ($vendorId) {
        $st = $pdo->prepare('SELECT id FROM vendors WHERE id = ? AND archived_at IS NULL');
        $st->execute([$vendorId]);
        if (!$st->fetchColumn()) $bad('Pick a vendor from the list.');
    }

    $poId = (int)($in['po_id'] ?? 0) ?: null;
    if ($poId) {
        require_once __DIR__ . '/purchasing.php';
        if (!bcrf_po_ready($pdo)) $bad('Purchase orders need migration 0021.');
        $st = $pdo->prepare("SELECT id, vendor_id, status FROM purchase_orders WHERE id = ?");
        $st->execute([$poId]);
        $po = $st->fetch(PDO::FETCH_ASSOC);
        if (!$po) $bad('That purchase order no longer exists.');
        if (in_array($po['status'], ['cancelled', 'received'], true)) $bad('That purchase order is already closed.');
        if (!$vendorId && $po['vendor_id']) $vendorId = (int)$po['vendor_id'];
    }

    $invoice = mb_substr(trim((string)($in['invoice_ref'] ?? '')), 0, 80) ?: null;
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255) ?: null;

    $rawLines = (array)($in['lines'] ?? []);
    if (!$rawLines) $bad('Add at least one line.');
    if (count($rawLines) > 100) $bad('Too many lines on one receive (max 100).');

    $lines = [];
    $lineNo = 0;
    foreach ($rawLines as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $qty = bcrf_stock_parse_qty($row['qty'] ?? '');
        if (isset($row['unit_cost_cents'])) {
            $costCents = (int)$row['unit_cost_cents'];
        } else {
            $s = trim(str_replace([',', '$'], '', (string)($row['unit_cost'] ?? '')));
            if ($s === '' || !is_numeric($s)) $bad('Enter a unit cost for each line.');
            $costCents = (int)round((float)$s * 100);
        }
        if ($costCents < 0 || $costCents > 100000000) $bad('Unit cost must be between $0 and $1,000,000.');
        $lineNo++;
        $lineCost = bcrf_muldiv((int)round($qty * 1000), $costCents, 1000);
        $lines[] = [
            'line_no' => $lineNo,
            'product_id' => $pid,
            'quantity' => $qty,
            'unit_cost_cents' => $costCents,
            'line_cost_cents' => $lineCost,
            'note' => mb_substr(trim((string)($row['note'] ?? '')), 0, 255) ?: null,
        ];
    }
    if (!$lines) $bad('Add at least one line with a product, quantity and unit cost.');

    // Farm-local business date
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $bdate = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

    $pdo->beginTransaction();
    try {
        // Validate products and lock in id order
        $ids = array_values(array_unique(array_column($lines, 'product_id')));
        sort($ids);
        $products = [];
        foreach ($ids as $pid) {
            $L = bcrf_stock_lock($pdo, $pid, $uid);
            if ((int)$L['p']['track_inventory'] !== 1) {
                throw new InvalidArgumentException('"' . $L['p']['name'] . '" is not a stocked item.');
            }
            if (!(int)($L['p']['is_active'] ?? 1)) {
                // still allow receive on inactive (restocking before re-activate)
            }
            $products[$pid] = $L;
        }

        // po_id column only after migration 0021
        $hasPoCol = false;
        try {
            $hasPoCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                                         AND table_name = 'receives' AND column_name = 'po_id'")->fetchColumn() === 1;
        } catch (Throwable $e) { $hasPoCol = false; }
        if ($hasPoCol) {
            $pdo->prepare('INSERT INTO receives (vendor_id, po_id, location_id, invoice_ref, note, business_date, created_at, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?)')
                ->execute([$vendorId, $poId, $locationId, $invoice, $note, $bdate, $uid]);
        } else {
            $pdo->prepare('INSERT INTO receives (vendor_id, location_id, invoice_ref, note, business_date, created_at, created_by)
                           VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?)')
                ->execute([$vendorId, $locationId, $invoice, $note, $bdate, $uid]);
            $poId = null;
        }
        $receiveId = (int)$pdo->lastInsertId();

        $insLine = $pdo->prepare('INSERT INTO receive_lines (receive_id, line_no, product_id, quantity, unit_cost_cents, line_cost_cents, note, created_at)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3))');

        foreach ($lines as $ln) {
            $pid = $ln['product_id'];
            $L = $products[$pid];
            $onHandBefore = (float)$L['p']['stock_quantity'];

            $r = bcrf_stock_move(
                $pdo, $pid, $locationId, $ln['quantity'], 'receive', 'receive', $receiveId,
                $ln['note'], $uid, 'strict', $L, $ln['unit_cost_cents']
            );
            // Refresh locked levels for subsequent lines of the same product
            $products[$pid]['lv'][$locationId] = bcrf_qm($r['after']);
            $products[$pid]['p']['stock_quantity'] = bcrf_qf(array_sum($products[$pid]['lv']));

            bcrf_product_apply_receive_cost($pdo, $pid, $ln['quantity'], $ln['unit_cost_cents'], $onHandBefore, $uid, $receiveId);

            $insLine->execute([
                $receiveId, $ln['line_no'], $pid, $ln['quantity'],
                $ln['unit_cost_cents'], $ln['line_cost_cents'], $ln['note'],
            ]);
        }

        if ($poId && function_exists('bcrf_po_apply_receive')) {
            bcrf_po_apply_receive($pdo, $poId, array_map(fn($ln) => [
                'product_id' => $ln['product_id'],
                'quantity' => $ln['quantity'],
            ], $lines), $uid);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$t, $aid, $label, $ip] = bcrf_audit_actor();
        $total = array_sum(array_column($lines, 'line_cost_cents'));
        bcrf_audit_event($pdo, [
            'actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $label, 'ip' => $ip,
            'action' => 'create', 'object_type' => 'receives', 'object_id' => (string)$receiveId,
            'object_label' => 'Receive #' . $receiveId . ' at ' . $loc['name'] . ' (' . count($lines) . ' lines, $' . number_format($total / 100, 2) . ')',
            'before' => null,
            'after' => ['vendor_id' => $vendorId, 'location_id' => $locationId, 'invoice_ref' => $invoice, 'lines' => count($lines), 'total_cents' => $total],
        ]);
    }
    if (function_exists('bcrf_event')) {
        bcrf_event('receive.completed', 'receives', $receiveId, [
            'lines' => count($lines),
            'location_id' => $locationId,
            'vendor_id' => $vendorId,
        ]);
    }
    return $receiveId;
}

}
