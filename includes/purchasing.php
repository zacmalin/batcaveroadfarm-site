<?php
// includes/purchasing.php — purchase orders (Farm OS Phase 2, slice 2.4, Nov 9, 2026; 06 Purchasing)
//   bcrf_po_ready / statuses
//   bcrf_po_list / get / save (create or update draft lines)
//   bcrf_po_set_status — ordered | cancelled (and auto partial/received from receives)
//   bcrf_po_apply_receive — after a receive attached to a PO, bump qty_received and status
declare(strict_types=1);

if (!function_exists('bcrf_po_ready')) {

require_once __DIR__ . '/vendors.php';
require_once __DIR__ . '/stock.php';
if (!function_exists('bcrf_muldiv')) require_once __DIR__ . '/sales.php';

function bcrf_po_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('purchase_orders','po_lines')")->fetchColumn();
        return $ready = ($n === 2 && bcrf_vendors_ready($pdo));
    } catch (Throwable $e) { return $ready = false; }
}

function bcrf_po_statuses(): array {
    return [
        'draft'     => 'Draft',
        'ordered'   => 'Ordered',
        'partial'   => 'Partially received',
        'received'  => 'Fully received',
        'cancelled' => 'Cancelled',
    ];
}

function bcrf_po_list(PDO $pdo, array $f = [], int $limit = 80): array {
    $w = []; $a = [];
    $status = $f['status'] ?? 'open';
    if ($status === 'open') $w[] = "po.status IN ('draft','ordered','partial')";
    elseif ($status === 'all') { /* no filter */ }
    else { $w[] = 'po.status = ?'; $a[] = $status; }
    if (!empty($f['vendor_id'])) { $w[] = 'po.vendor_id = ?'; $a[] = (int)$f['vendor_id']; }
    if (($f['q'] ?? '') !== '') {
        $w[] = '(po.po_ref LIKE ? OR v.name LIKE ? OR po.note LIKE ?)';
        $q = '%' . $f['q'] . '%';
        array_push($a, $q, $q, $q);
    }
    $st = $pdo->prepare("SELECT po.*, v.name AS vendor_name,
                                (SELECT COUNT(*) FROM po_lines x WHERE x.po_id = po.id) AS lines_n,
                                (SELECT COALESCE(SUM(quantity_ordered * COALESCE(unit_cost_cents, 0)), 0) FROM po_lines x WHERE x.po_id = po.id) AS ordered_cents,
                                (SELECT COALESCE(SUM(quantity_received), 0) FROM po_lines x WHERE x.po_id = po.id) AS qty_received_sum,
                                (SELECT COALESCE(SUM(quantity_ordered), 0) FROM po_lines x WHERE x.po_id = po.id) AS qty_ordered_sum
                         FROM purchase_orders po
                         JOIN vendors v ON v.id = po.vendor_id"
                         . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
                         . ' ORDER BY FIELD(po.status, \'draft\',\'ordered\',\'partial\',\'received\',\'cancelled\'), po.expected_date IS NULL, po.expected_date, po.id DESC
                         LIMIT ' . max(1, min(200, $limit)));
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_po_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT po.*, v.name AS vendor_name, v.payment_terms
                         FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id WHERE po.id = ?");
    $st->execute([$id]);
    $po = $st->fetch(PDO::FETCH_ASSOC);
    if (!$po) return null;
    $st = $pdo->prepare("SELECT pl.*, i.name AS product_name, i.sku, i.unit, i.avg_cost_cents
                         FROM po_lines pl JOIN inventory i ON i.id = pl.product_id
                         WHERE pl.po_id = ? ORDER BY pl.line_no");
    $st->execute([$id]);
    $po['lines'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $po;
}

/**
 * Create a PO (always starts draft) or replace lines on a draft.
 * $in: vendor_id, po_ref, expected_date, note,
 *      lines: [['product_id'=>, 'qty'=>, 'unit_cost'=> dollars optional, 'note'=>?], ...]
 */
function bcrf_po_save(PDO $pdo, ?int $id, array $in, ?int $uid): int {
    if (!bcrf_po_ready($pdo)) throw new InvalidArgumentException('Run migration 0021 in Settings › Database first.');
    $bad = fn(string $m) => throw new InvalidArgumentException($m);

    $vendorId = (int)($in['vendor_id'] ?? 0);
    if (!$vendorId) $bad('Pick a vendor.');
    $st = $pdo->prepare('SELECT id FROM vendors WHERE id = ? AND archived_at IS NULL');
    $st->execute([$vendorId]);
    if (!$st->fetchColumn()) $bad('Pick a vendor from the list.');

    $poRef = mb_substr(trim((string)($in['po_ref'] ?? '')), 0, 80) ?: null;
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255) ?: null;
    $exp = trim((string)($in['expected_date'] ?? ''));
    if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) $bad('Expected date must be YYYY-MM-DD.');
    $expected = $exp !== '' ? $exp : null;

    $raw = (array)($in['lines'] ?? []);
    if (!$raw) $bad('Add at least one line.');
    if (count($raw) > 100) $bad('Too many lines (max 100).');

    $lines = [];
    $lineNo = 0;
    foreach ($raw as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $qty = bcrf_stock_parse_qty($row['qty'] ?? '');
        $costCents = null;
        if (isset($row['unit_cost_cents']) && $row['unit_cost_cents'] !== '' && $row['unit_cost_cents'] !== null) {
            $costCents = (int)$row['unit_cost_cents'];
        } else {
            $s = trim(str_replace([',', '$'], '', (string)($row['unit_cost'] ?? '')));
            if ($s !== '') {
                if (!is_numeric($s)) $bad('Check the unit cost on each line.');
                $costCents = (int)round((float)$s * 100);
            }
        }
        if ($costCents !== null && ($costCents < 0 || $costCents > 100000000)) $bad('Unit cost must be between $0 and $1,000,000.');
        $lineNo++;
        $lines[] = [
            'line_no' => $lineNo,
            'product_id' => $pid,
            'quantity_ordered' => $qty,
            'unit_cost_cents' => $costCents,
            'note' => mb_substr(trim((string)($row['note'] ?? '')), 0, 255) ?: null,
        ];
    }
    if (!$lines) $bad('Add at least one line with a product and quantity.');

    $pdo->beginTransaction();
    try {
        $ids = array_values(array_unique(array_column($lines, 'product_id')));
        $inList = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, name, track_inventory FROM inventory WHERE id IN ($inList)");
        $st->execute($ids);
        $prods = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $prods[(int)$p['id']] = $p;
        foreach ($ids as $pid) {
            if (!isset($prods[$pid])) $bad('A product on the order no longer exists.');
            if ((int)$prods[$pid]['track_inventory'] !== 1) $bad('"' . $prods[$pid]['name'] . '" is not a stocked item.');
        }

        if ($id) {
            $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $old = $st->fetch(PDO::FETCH_ASSOC);
            if (!$old) $bad('That purchase order no longer exists.');
            if ($old['status'] !== 'draft') $bad('Only a draft can be edited. Cancel and start a new one, or receive against it.');
            $pdo->prepare('UPDATE purchase_orders SET vendor_id=?, po_ref=?, expected_date=?, note=?, updated_at=UTC_TIMESTAMP(), updated_by=? WHERE id=?')
                ->execute([$vendorId, $poRef, $expected, $note, $uid, $id]);
            $pdo->prepare('DELETE FROM po_lines WHERE po_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO purchase_orders (vendor_id, status, po_ref, expected_date, note, created_at, created_by)
                           VALUES (?, \'draft\', ?, ?, ?, UTC_TIMESTAMP(3), ?)')
                ->execute([$vendorId, $poRef, $expected, $note, $uid]);
            $id = (int)$pdo->lastInsertId();
        }

        $ins = $pdo->prepare('INSERT INTO po_lines (po_id, line_no, product_id, quantity_ordered, quantity_received, unit_cost_cents, note, created_at)
                              VALUES (?, ?, ?, ?, 0, ?, ?, UTC_TIMESTAMP(3))');
        foreach ($lines as $ln) {
            $ins->execute([$id, $ln['line_no'], $ln['product_id'], $ln['quantity_ordered'], $ln['unit_cost_cents'], $ln['note']]);
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
            'action' => 'create', 'object_type' => 'purchase_orders', 'object_id' => (string)$id,
            'object_label' => 'PO #' . $id . ' (' . count($lines) . ' lines)',
            'before' => null, 'after' => ['vendor_id' => $vendorId, 'lines' => count($lines), 'status' => 'draft'],
        ]);
    }
    return $id;
}

/** Mark ordered or cancelled. Partial/received are set by bcrf_po_apply_receive. */
function bcrf_po_set_status(PDO $pdo, int $id, string $status, ?int $uid): void {
    if (!in_array($status, ['ordered', 'cancelled'], true)) {
        throw new InvalidArgumentException('Status must be ordered or cancelled.');
    }
    $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
    $pdo->beginTransaction();
    try {
        $st->execute([$id]);
        $po = $st->fetch(PDO::FETCH_ASSOC);
        if (!$po) throw new InvalidArgumentException('That purchase order no longer exists.');
        if ($status === 'ordered') {
            if (!in_array($po['status'], ['draft', 'ordered'], true)) {
                throw new InvalidArgumentException('Only a draft can be marked ordered.');
            }
            $pdo->prepare("UPDATE purchase_orders SET status = 'ordered', ordered_at = COALESCE(ordered_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
                ->execute([$uid, $id]);
        } else { // cancelled
            if (in_array($po['status'], ['received', 'cancelled'], true)) {
                throw new InvalidArgumentException('That order is already closed.');
            }
            $pdo->prepare("UPDATE purchase_orders SET status = 'cancelled', closed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
                ->execute([$uid, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    if (function_exists('bcrf_event')) bcrf_event('po.' . $status, 'purchase_orders', $id, []);
}

/**
 * After a receive that references a PO, add quantities to matching lines and refresh status.
 * Call inside the receive transaction after lines are written. Matching is by product_id (first open lines).
 */
function bcrf_po_apply_receive(PDO $pdo, int $poId, array $receivedLines, ?int $uid): void {
    // $receivedLines: [['product_id'=>int, 'quantity'=>float], ...]
    if (!bcrf_po_ready($pdo)) return;
    $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
    $st->execute([$poId]);
    $po = $st->fetch(PDO::FETCH_ASSOC);
    if (!$po || $po['status'] === 'cancelled' || $po['status'] === 'received') return;

    $st = $pdo->prepare('SELECT id, product_id, quantity_ordered, quantity_received FROM po_lines WHERE po_id = ? ORDER BY line_no FOR UPDATE');
    $st->execute([$poId]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC);
    $byProduct = [];
    foreach ($lines as $ln) {
        $pid = (int)$ln['product_id'];
        if (!isset($byProduct[$pid])) $byProduct[$pid] = [];
        $byProduct[$pid][] = $ln;
    }

    $upd = $pdo->prepare('UPDATE po_lines SET quantity_received = ? WHERE id = ?');
    foreach ($receivedLines as $r) {
        $pid = (int)$r['product_id'];
        $left = (float)$r['quantity'];
        if ($left <= 0 || empty($byProduct[$pid])) continue;
        foreach ($byProduct[$pid] as &$ln) {
            if ($left <= 0) break;
            $need = max(0.0, (float)$ln['quantity_ordered'] - (float)$ln['quantity_received']);
            if ($need <= 0) continue;
            $take = min($need, $left);
            $newRecv = round((float)$ln['quantity_received'] + $take, 3);
            $upd->execute([$newRecv, $ln['id']]);
            $ln['quantity_received'] = $newRecv;
            $left = round($left - $take, 3);
        }
        unset($ln);
    }

    // Refresh status from all lines
    $st = $pdo->prepare('SELECT quantity_ordered, quantity_received FROM po_lines WHERE po_id = ?');
    $st->execute([$poId]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    $anyRecv = false; $allFull = true;
    foreach ($all as $ln) {
        $ord = (float)$ln['quantity_ordered'];
        $rec = (float)$ln['quantity_received'];
        if ($rec > 0) $anyRecv = true;
        if ($rec + 0.0005 < $ord) $allFull = false;
    }
    if ($allFull && $anyRecv) {
        $pdo->prepare("UPDATE purchase_orders SET status = 'received', closed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([$uid, $poId]);
    } elseif ($anyRecv) {
        $pdo->prepare("UPDATE purchase_orders SET status = 'partial', updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([$uid, $poId]);
    } elseif ($po['status'] === 'draft') {
        // first receive on a draft → treat as ordered + partial
        $pdo->prepare("UPDATE purchase_orders SET status = 'partial', ordered_at = COALESCE(ordered_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([$uid, $poId]);
    }
}

}
