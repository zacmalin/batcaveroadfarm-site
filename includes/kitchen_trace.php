<?php
// includes/kitchen_trace.php — lot tracing for recalls (Farm OS Phase 4, slice 7, Nov 13, 2026; 09 › Batches and lots).
// No new tables: the stock ledger (inventory_adjustments) already says when each lot went on the shelf and when each sale
// took units off it. Tracing replays one product's ledger, oldest first, first in first out:
//   - a lot goes on the shelf when its batch adds stock ('produced', source kitchen_batch);
//   - other stock that came in (opening balance, receiving, a count that found more) is an unknown lot;
//   - sales and other removals take the oldest units first; voids and refunds put units back on the lots that sale took;
//   - voiding a batch takes that lot's own units off; transfers between locations are ignored (the product is one shelf).
// Two lists per lot:
//   likely    sales FIFO says took units from this lot;
//   possible  every other sale of the product while any of the lot was on the shelf (the safe recall list).
// A product that doesn't count stock has no ledger: every sale between the made-on day and the best-by day (or the next
// batch, or today) is "possible". Voided sales are left out. Quantities are worked in thousandths.
// Used by admin/kitchen_trace.php and tests/kitchen_trace_test.php.
if (!function_exists('bcrf_kitchen_trace_ready')) {

require_once __DIR__ . '/kitchen_batches.php';

/** Batches (0053), the stock ledger by location (0015) and sales (0009) are all there. */
function bcrf_kitchen_trace_ready(PDO $pdo): bool {
    if (!bcrf_kitchen_batches_ready($pdo) || !bcrf_stock_ready($pdo)) return false;
    try { return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('sales','sale_lines','refunds')")->fetchColumn() === 3; }
    catch (Throwable $e) { return false; }
}

/** A batch by its lot code (spaces and case ignored), or null. */
function bcrf_kitchen_lot_find(PDO $pdo, string $code): ?array {
    $code = strtoupper(preg_replace('/\s+/', '', $code));
    if ($code === '') return null;
    $st = $pdo->prepare("SELECT id FROM kitchen_batches WHERE UPPER(lot_code) = ? LIMIT 1");
    $st->execute([$code]);
    $id = $st->fetchColumn();
    return $id === false ? null : bcrf_kitchen_batch($pdo, (int)$id);
}

/**
 * Replay one product's ledger first in, first out.
 * Returns ['lots' => [key => lot], 'sales' => [saleKey => sale]] where a lot key is 'b<batch id>' or 'u<n>' (unknown) and
 *   lot  = [batch_id, start (UTC), end (UTC when the last unit left, null while some is left), in, sold, removed => [reason => q], left] (thousandths)
 *   sale = [type ('sale' | 'pos_receipt'), id, at (UTC), qty, from => [lot key => q]] (thousandths)
 */
function bcrf_kitchen_fifo(PDO $pdo, int $productId): array {
    $st = $pdo->prepare("SELECT id, quantity_change, reason, source_type, source_id, created_at FROM inventory_adjustments
                         WHERE product_id = ? ORDER BY created_at, id");
    $st->execute([$productId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $refundSale = [];
    $rids = array_values(array_unique(array_map(fn($r) => (int)$r['source_id'], array_filter($rows, fn($r) => $r['reason'] === 'refund_restock' && $r['source_type'] === 'refund' && $r['source_id']))));
    if ($rids) {
        foreach ($pdo->query("SELECT id, sale_id FROM refunds WHERE id IN (" . implode(',', $rids) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) $refundSale[(int)$r['id']] = (int)$r['sale_id'];
    }

    $lots = []; $queue = []; $sales = []; $unknown = 0;
    $newLot = function (?int $batchId, string $at) use (&$lots, &$queue, &$unknown): string {
        $key = $batchId ? 'b' . $batchId : 'u' . (++$unknown);
        if (!isset($lots[$key])) $lots[$key] = ['batch_id' => $batchId, 'start' => $at, 'end' => null, 'in' => 0, 'sold' => 0, 'removed' => [], 'left' => 0];
        if (!in_array($key, $queue, true)) $queue[] = $key;
        return $key;
    };
    $take = function (string $key, int $q, string $at) use (&$lots, &$queue): void {
        $lots[$key]['left'] -= $q;
        if ($lots[$key]['left'] <= 0) { $lots[$key]['end'] = $at; $queue = array_values(array_filter($queue, fn($k) => $k !== $key)); }
    };
    $putBack = function (string $key, int $q) use (&$lots, &$queue): void {
        $lots[$key]['left'] += $q;
        $lots[$key]['end'] = null;
        if (!in_array($key, $queue, true)) {   // back on the shelf, in its place by start time
            $queue[] = $key;
            usort($queue, fn($a, $b) => strcmp($lots[$a]['start'], $lots[$b]['start']) ?: strcmp($a, $b));
        }
    };

    foreach ($rows as $r) {
        $q = bcrf_qm($r['quantity_change']);
        if ($q === 0) continue;
        $at = substr((string)$r['created_at'], 0, 19);   // DATETIME(3): seconds are enough
        $reason = (string)$r['reason'];
        if ($reason === 'transfer_in' || $reason === 'transfer_out') continue;
        if ($q > 0) {
            $back = null;
            if ($reason === 'void' && $r['source_type'] === 'sale') $back = 'sale:' . (int)$r['source_id'];
            elseif ($reason === 'refund_restock' && isset($refundSale[(int)$r['source_id']])) $back = 'sale:' . $refundSale[(int)$r['source_id']];
            if ($back !== null && isset($sales[$back])) {
                foreach (array_reverse($sales[$back]['from'], true) as $key => $had) {   // newest lot first
                    if ($q <= 0) break;
                    $n = min($had, $q);
                    $sales[$back]['from'][$key] -= $n;
                    if ($sales[$back]['from'][$key] <= 0) unset($sales[$back]['from'][$key]);
                    $sales[$back]['qty'] -= $n;
                    $lots[$key]['sold'] -= $n;
                    $putBack($key, $n);
                    $q -= $n;
                }
                if ($q <= 0) continue;
            }
            $key = $newLot(($reason === 'produced' && $r['source_type'] === 'kitchen_batch') ? (int)$r['source_id'] : null, $at);
            $lots[$key]['in'] += $q;
            $lots[$key]['left'] += $q;
            continue;
        }
        $need = -$q;
        $isSale = $reason === 'sale';
        $saleKey = $isSale ? (($r['source_type'] === 'pos_receipt' ? 'pos_receipt:' : 'sale:') . (int)$r['source_id']) : null;
        if ($isSale && !isset($sales[$saleKey])) $sales[$saleKey] = ['type' => $r['source_type'] === 'pos_receipt' ? 'pos_receipt' : 'sale', 'id' => (int)$r['source_id'], 'at' => $at, 'qty' => 0, 'from' => []];
        $order = $queue;
        if ($reason === 'batch_void' && $r['source_type'] === 'kitchen_batch' && in_array('b' . (int)$r['source_id'], $queue, true)) {
            $own = 'b' . (int)$r['source_id'];
            $order = array_merge([$own], array_values(array_filter($queue, fn($k) => $k !== $own)));
        }
        foreach ($order as $key) {
            if ($need <= 0) break;
            $n = min($lots[$key]['left'], $need);
            if ($n <= 0) continue;
            if ($isSale) { $lots[$key]['sold'] += $n; $sales[$saleKey]['from'][$key] = ($sales[$saleKey]['from'][$key] ?? 0) + $n; }
            else $lots[$key]['removed'][$reason] = ($lots[$key]['removed'][$reason] ?? 0) + $n;
            $take($key, $n, $at);
            $need -= $n;
        }
        if ($isSale) $sales[$saleKey]['qty'] += -$q;   // anything beyond what was on the shelf came from nowhere we know
    }
    return ['lots' => $lots, 'sales' => $sales];
}

/** UTC 'Y-m-d H:i:s' for the start of a farm-local day. */
function bcrf_kitchen_farm_day_utc(string $ymd, bool $endOfDay = false): string {
    $d = new DateTime($ymd . ($endOfDay ? ' 23:59:59' : ' 00:00:00'), new DateTimeZone('America/New_York'));
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Sale headers and who bought, for sale ids: id => [sale_number, status, completed_at, customer_id, name, email, phone, payer]. */
function bcrf_kitchen_trace_sales(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $out = [];
    foreach ($pdo->query("SELECT s.id, s.sale_number, s.status, s.completed_at, s.customer_id,
                                 TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS name, c.email, c.phone_number AS phone
                          FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['payer'] = '';
        $out[(int)$r['id']] = $r;
    }
    try {
        foreach ($pdo->query("SELECT sale_id, GROUP_CONCAT(DISTINCT payer_handle SEPARATOR ', ') AS payer FROM payments
                              WHERE sale_id IN ($in) AND payer_handle IS NOT NULL AND payer_handle <> '' GROUP BY sale_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($out[(int)$r['sale_id']])) $out[(int)$r['sale_id']]['payer'] = (string)$r['payer'];
        }
    } catch (Throwable $e) { /* payments without payer_handle */ }
    return $out;
}

/**
 * Trace one lot. Returns [batch, mode ('fifo' | 'window'), lot (fifo stats or null), from, to (UTC window), likely, possible, note]
 * where likely/possible rows are [ref, sale_id, number, at (UTC), qty (units, from this lot for likely, whole line for possible),
 * customer_id, name, email, phone, payer, status].
 */
function bcrf_kitchen_lot_trace(PDO $pdo, int $batchId): array {
    $b = bcrf_kitchen_batch($pdo, $batchId);
    if (!$b) throw new BcrfKitchenError('That lot doesn\'t exist.');
    $pid = (int)$b['inventory_id'];
    $now = gmdate('Y-m-d H:i:s');
    $likely = []; $possible = []; $lot = null; $note = '';
    $fifo = (int)$b['stock_added'] ? bcrf_kitchen_fifo($pdo, $pid) : null;
    if ($fifo && isset($fifo['lots']['b' . $batchId])) {
        $mode = 'fifo';
        $lot = $fifo['lots']['b' . $batchId];
        $from = $lot['start'];
        $to = $lot['end'] ?? $now;
        $refs = [];
        foreach ($fifo['sales'] as $k => $s) {
            $mine = $s['from']['b' . $batchId] ?? 0;
            if ($mine > 0) $refs[$k] = ['likely', $mine];
            elseif ($s['qty'] > 0 && $s['at'] >= $from && $s['at'] <= $to) $refs[$k] = ['possible', $s['qty']];
        }
        $head = bcrf_kitchen_trace_sales($pdo, array_map(fn($k) => $fifo['sales'][$k]['id'], array_keys(array_filter($refs, fn($k) => $fifo['sales'][$k]['type'] === 'sale', ARRAY_FILTER_USE_KEY))));
        foreach ($refs as $k => [$list, $q]) {
            $s = $fifo['sales'][$k];
            $hd = $s['type'] === 'sale' ? ($head[$s['id']] ?? null) : null;
            if ($hd && $hd['status'] === 'voided') continue;
            $row = ['ref' => $k, 'sale_id' => $s['type'] === 'sale' ? $s['id'] : null,
                    'number' => $hd['sale_number'] ?? ($s['type'] === 'pos_receipt' ? 'Old register #' . $s['id'] : 'Sale #' . $s['id']),
                    'at' => $s['at'], 'qty' => bcrf_qf($q), 'customer_id' => $hd['customer_id'] ?? null, 'name' => trim((string)($hd['name'] ?? '')),
                    'email' => (string)($hd['email'] ?? ''), 'phone' => (string)($hd['phone'] ?? ''), 'payer' => (string)($hd['payer'] ?? ''), 'status' => (string)($hd['status'] ?? '')];
            if ($list === 'likely') $likely[] = $row; else $possible[] = $row;
        }
    } else {
        $mode = 'window';
        $from = bcrf_kitchen_farm_day_utc((string)$b['made_on']);
        $st = $pdo->prepare("SELECT MIN(made_on) FROM kitchen_batches WHERE inventory_id = ? AND voided_at IS NULL AND id <> ? AND made_on > ?");
        $st->execute([$pid, $batchId, $b['made_on']]);
        $next = $st->fetchColumn();
        $ends = array_filter(['the best-by day' => $b['best_by'] ? bcrf_kitchen_farm_day_utc((string)$b['best_by'], true) : null,
                              'the day the next batch was made' => $next ? bcrf_kitchen_farm_day_utc((string)$next, true) : null]);
        asort($ends);
        $to = $ends && reset($ends) < $now ? reset($ends) : $now;
        $endTxt = $ends && reset($ends) < $now ? key($ends) : 'today';
        $note = (int)$b['stock_added'] ? 'This lot\'s stock record wasn\'t found, so every sale while it could have been on the shelf is listed.'
              : 'This product doesn\'t count stock, so every sale while the lot could have been on the shelf is listed (from the made-on day to the '
                . $endTxt . ').';
        $st = $pdo->prepare("SELECT s.id, SUM(sl.quantity) AS qty, s.completed_at FROM sale_lines sl JOIN sales s ON s.id = sl.sale_id
                             WHERE sl.product_id = ? AND s.completed_at BETWEEN ? AND ? AND s.status <> 'voided'
                             GROUP BY s.id, s.completed_at ORDER BY s.completed_at, s.id");
        $st->execute([$pid, $from, $to]);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        $head = bcrf_kitchen_trace_sales($pdo, array_column($lines, 'id'));
        foreach ($lines as $l) {
            $hd = $head[(int)$l['id']] ?? [];
            $possible[] = ['ref' => 'sale:' . (int)$l['id'], 'sale_id' => (int)$l['id'], 'number' => $hd['sale_number'] ?? ('Sale #' . (int)$l['id']), 'at' => substr((string)$l['completed_at'], 0, 19),
                           'qty' => (float)$l['qty'], 'customer_id' => $hd['customer_id'] ?? null, 'name' => trim((string)($hd['name'] ?? '')), 'email' => (string)($hd['email'] ?? ''),
                           'phone' => (string)($hd['phone'] ?? ''), 'payer' => (string)($hd['payer'] ?? ''), 'status' => (string)($hd['status'] ?? '')];
        }
    }
    $byTime = fn($a, $c) => strcmp($a['at'], $c['at']) ?: strcmp($a['ref'], $c['ref']);
    usort($likely, $byTime); usort($possible, $byTime);
    return ['batch' => $b, 'mode' => $mode, 'lot' => $lot, 'from' => $from, 'to' => $to, 'likely' => $likely, 'possible' => $possible, 'note' => $note];
}

/** People to contact: one row per customer (or per sale with no customer), likely first. */
function bcrf_kitchen_trace_contacts(array $trace): array {
    $out = [];
    foreach (['likely' => $trace['likely'], 'possible' => $trace['possible']] as $list => $rows) {
        foreach ($rows as $r) {
            $k = $r['customer_id'] ? 'c' . (int)$r['customer_id'] : $r['ref'];
            if (!isset($out[$k])) $out[$k] = ['list' => $list, 'customer_id' => $r['customer_id'], 'name' => $r['name'], 'email' => $r['email'], 'phone' => $r['phone'],
                                                'payer' => $r['payer'], 'sales' => [], 'qty' => 0.0];
            $out[$k]['sales'][] = $r['number'];
            $out[$k]['qty'] += (float)$r['qty'];
        }
    }
    return array_values($out);
}

}
