<?php
// includes/settlements.php — consignor settlements (Farm OS Phase 2, Nov 9, 2026)
// Sold consigned thrift items with a consignor + share become payable. A settlement
// records the payout for one or more items belonging to the same consignor.
declare(strict_types=1);

if (!function_exists('bcrf_settlements_ready')) {

require_once __DIR__ . '/thrift.php';
require_once __DIR__ . '/sales.php';

function bcrf_settlements_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('consignor_settlements','consignor_settlement_lines')")->fetchColumn();
        $c = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                               AND table_name = 'inventory' AND column_name = 'settlement_id'")->fetchColumn();
        return $ready = ($n === 2 && $c === 1 && bcrf_thrift_ready($pdo));
    } catch (Throwable $e) { return $ready = false; }
}

/**
 * Sold consigned thrift items not yet settled.
 * Optional $consignorId to filter one customer. Returns rows with sale linkage when found.
 */
function bcrf_settlement_payable(PDO $pdo, ?int $consignorId = null, int $limit = 300): array {
    if (!bcrf_settlements_ready($pdo)) return [];
    $w = ["i.product_type = 'thrift_item'", "i.thrift_source = 'consigned'", "i.thrift_status = 'sold'",
          "i.consignor_customer_id IS NOT NULL", "i.settlement_id IS NULL"];
    $a = [];
    if ($consignorId) { $w[] = 'i.consignor_customer_id = ?'; $a[] = $consignorId; }
    $st = $pdo->prepare("SELECT i.id, i.name, i.sku, i.price, i.consignor_customer_id, i.consignor_share_bp,
                                i.thrift_intake_at,
                                TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) AS consignor_name,
                                c.email AS consignor_email
                         FROM inventory i
                         LEFT JOIN customers c ON c.id = i.consignor_customer_id
                         WHERE " . implode(' AND ', $w) . "
                         ORDER BY i.consignor_customer_id, i.thrift_intake_at, i.id
                         LIMIT " . max(1, min(500, $limit)));
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    // Attach most recent non-voided sale line for each product (qty-1 thrift items).
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sl = $pdo->prepare("SELECT l.id AS sale_line_id, l.sale_id, l.product_id, l.gross_cents, l.discount_cents,
                                l.line_total_cents, s.sale_number, s.business_date, s.completed_at
                         FROM sale_lines l
                         JOIN sales s ON s.id = l.sale_id AND s.status <> 'voided'
                         WHERE l.product_id IN ($in)
                         ORDER BY s.completed_at DESC, l.id DESC");
    $sl->execute($ids);
    $byProduct = [];
    foreach ($sl->fetchAll(PDO::FETCH_ASSOC) as $ln) {
        $pid = (int)$ln['product_id'];
        if (!isset($byProduct[$pid])) $byProduct[$pid] = $ln; // first = newest
    }

    foreach ($rows as &$r) {
        $pid = (int)$r['id'];
        $ln = $byProduct[$pid] ?? null;
        $shareBp = (int)($r['consignor_share_bp'] ?? 6000);
        if ($shareBp < 0) $shareBp = 0;
        if ($shareBp > 10000) $shareBp = 10000;
        if ($ln) {
            $net = (int)$ln['gross_cents'] - (int)$ln['discount_cents'];
            $r['sale_id'] = (int)$ln['sale_id'];
            $r['sale_line_id'] = (int)$ln['sale_line_id'];
            $r['sale_number'] = $ln['sale_number'];
            $r['business_date'] = $ln['business_date'];
            $r['sale_net_cents'] = $net;
        } else {
            // No sale row found (legacy / manual status); fall back to list price.
            $r['sale_id'] = null;
            $r['sale_line_id'] = null;
            $r['sale_number'] = null;
            $r['business_date'] = null;
            $r['sale_net_cents'] = (int)round(((float)$r['price']) * 100);
        }
        $r['share_bp'] = $shareBp;
        $r['consignor_cents'] = intdiv(2 * $r['sale_net_cents'] * $shareBp + 10000, 20000); // half-up
        $r['farm_cents'] = $r['sale_net_cents'] - $r['consignor_cents'];
    }
    unset($r);
    return $rows;
}

/** Group payable rows by consignor_customer_id. */
function bcrf_settlement_payable_by_consignor(PDO $pdo): array {
    $groups = [];
    foreach (bcrf_settlement_payable($pdo) as $r) {
        $cid = (int)$r['consignor_customer_id'];
        if (!isset($groups[$cid])) {
            $groups[$cid] = [
                'consignor_customer_id' => $cid,
                'consignor_name' => trim((string)$r['consignor_name']) ?: ('Customer #' . $cid),
                'consignor_email' => $r['consignor_email'] ?? null,
                'items' => [],
                'sale_cents' => 0,
                'consignor_cents' => 0,
                'farm_cents' => 0,
            ];
        }
        $groups[$cid]['items'][] = $r;
        $groups[$cid]['sale_cents'] += (int)$r['sale_net_cents'];
        $groups[$cid]['consignor_cents'] += (int)$r['consignor_cents'];
        $groups[$cid]['farm_cents'] += (int)$r['farm_cents'];
    }
    return array_values($groups);
}

/**
 * Create a settlement for one consignor. $productIds = inventory ids to include (must be payable for that consignor).
 * Returns settlement id.
 */
function bcrf_settlement_create(PDO $pdo, int $consignorId, array $productIds, ?string $note, ?int $uid): int {
    if (!bcrf_settlements_ready($pdo)) throw new InvalidArgumentException('Run migration 0022 in Settings › Database first.');
    $consignorId = max(1, $consignorId);
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    $productIds = array_filter($productIds, fn($x) => $x > 0);
    if (!$productIds) throw new InvalidArgumentException('Pick at least one sold item to settle.');

    $payable = bcrf_settlement_payable($pdo, $consignorId);
    $byId = [];
    foreach ($payable as $r) $byId[(int)$r['id']] = $r;
    $lines = [];
    foreach ($productIds as $pid) {
        if (!isset($byId[$pid])) throw new InvalidArgumentException('Item #' . $pid . ' is not payable for this consignor (already settled, not sold, or wrong person).');
        $lines[] = $byId[$pid];
    }

    $note = $note !== null ? mb_substr(trim(str_replace("\r", '', $note)), 0, 255) : null;
    if ($note === '') $note = null;

    $saleCents = array_sum(array_column($lines, 'sale_net_cents'));
    $consignorCents = array_sum(array_column($lines, 'consignor_cents'));
    $farmCents = $saleCents - $consignorCents;

    $pdo->beginTransaction();
    try {
        // Re-check locks: none of these may have gained a settlement_id since the list was loaded.
        $in = implode(',', array_fill(0, count($productIds), '?'));
        $st = $pdo->prepare("SELECT id FROM inventory WHERE id IN ($in) AND product_type = 'thrift_item'
                             AND thrift_source = 'consigned' AND thrift_status = 'sold'
                             AND consignor_customer_id = ? AND settlement_id IS NULL FOR UPDATE");
        $st->execute([...$productIds, $consignorId]);
        $locked = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($locked) !== count($productIds)) {
            throw new InvalidArgumentException('One or more items were just settled by someone else. Refresh and try again.');
        }

        $pdo->prepare("INSERT INTO consignor_settlements
            (consignor_customer_id, status, items_count, sale_cents, consignor_cents, farm_cents, paid_at, paid_by, note, created_at, created_by)
            VALUES (?, 'paid', ?, ?, ?, ?, UTC_TIMESTAMP(3), ?, ?, UTC_TIMESTAMP(), ?)")
            ->execute([$consignorId, count($lines), $saleCents, $consignorCents, $farmCents, $uid, $note, $uid]);
        $sid = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO consignor_settlement_lines
            (settlement_id, product_id, sale_id, sale_line_id, description, sku, sale_net_cents, share_bp, consignor_cents)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $mark = $pdo->prepare('UPDATE inventory SET settlement_id = ? WHERE id = ? AND settlement_id IS NULL');
        foreach ($lines as $ln) {
            $ins->execute([
                $sid, (int)$ln['id'], $ln['sale_id'], $ln['sale_line_id'],
                (string)$ln['name'], $ln['sku'] ?? null,
                (int)$ln['sale_net_cents'], (int)$ln['share_bp'], (int)$ln['consignor_cents'],
            ]);
            $mark->execute([$sid, (int)$ln['id']]);
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
            'action' => 'create', 'object_type' => 'consignor_settlement', 'object_id' => (string)$sid,
            'object_label' => 'Settlement #' . $sid . ' · ' . count($lines) . ' items · $' . number_format($consignorCents / 100, 2),
            'before' => null,
            'after' => ['consignor_customer_id' => $consignorId, 'consignor_cents' => $consignorCents, 'items' => count($lines)],
        ]);
    }
    if (function_exists('bcrf_event')) bcrf_event('settlement.create', 'consignor_settlement', $sid, ['consignor' => $consignorId, 'cents' => $consignorCents]);
    return $sid;
}

function bcrf_settlement_list(PDO $pdo, array $f = [], int $limit = 100): array {
    if (!bcrf_settlements_ready($pdo)) return [];
    $w = ['1=1']; $a = [];
    if (!empty($f['consignor_customer_id'])) { $w[] = 's.consignor_customer_id = ?'; $a[] = (int)$f['consignor_customer_id']; }
    $st = $pdo->prepare("SELECT s.*,
                                TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) AS consignor_name
                         FROM consignor_settlements s
                         LEFT JOIN customers c ON c.id = s.consignor_customer_id
                         WHERE " . implode(' AND ', $w) . "
                         ORDER BY s.paid_at DESC, s.id DESC LIMIT " . max(1, min(300, $limit)));
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_settlement_get(PDO $pdo, int $id): ?array {
    if (!bcrf_settlements_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT s.*,
                                TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) AS consignor_name,
                                c.email AS consignor_email
                         FROM consignor_settlements s
                         LEFT JOIN customers c ON c.id = s.consignor_customer_id
                         WHERE s.id = ?");
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $ln = $pdo->prepare('SELECT * FROM consignor_settlement_lines WHERE settlement_id = ? ORDER BY id');
    $ln->execute([$id]);
    $s['lines'] = $ln->fetchAll(PDO::FETCH_ASSOC);
    return $s;
}

}
