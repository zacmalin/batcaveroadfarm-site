<?php // includes/reconcile.php
/*
 * Farm OS Phase 1, slice 6 (Nov 6, 2026): matching PayPal, Venmo and Cash App payments by hand (05 › Peer-to-peer reconciliation).
 * The farm's P2P accounts are personal, so there's no statement to import: staff compare each recorded payment with the
 * app's history on the farm phone and mark it matched, or flag a problem (missing, wrong amount, reversed).
 * Uses columns that already exist on payments (migration 0009): reconciled_at, reconciled_by, reconcile_flag.
 *   matched  = reconciled_at set, flag NULL
 *   flagged  = flag set, reconciled_at NULL (stays open until someone matches it or clears the flag)
 *   to check = neither
 * Payments on voided sales are left out (their money was handed back or never taken).
 */

if (!function_exists('bcrf_p2p_list')) {

require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/drawers.php';

function bcrf_p2p_tenders(): array { return ['paypal' => 'PayPal', 'venmo' => 'Venmo', 'cashapp' => 'Cash App']; }
function bcrf_p2p_flags(): array   { return ['missing' => 'Not in the app', 'wrong_amount' => 'Wrong amount', 'reversed' => 'Reversed / refunded by payer']; }

/** Days after which an unchecked payment raises an alert (05: 7 days). */
function bcrf_p2p_stale_days(): int { return 7; }

/**
 * P2P payments. $state: 'open' (not checked, no flag), 'flagged', 'matched'. Matched is limited to $from..$to.
 * Returns rows newest day first, oldest payment first within a day.
 */
function bcrf_p2p_list(PDO $pdo, string $state, ?string $from = null, ?string $to = null): array {
    $where = ["p.tender IN ('paypal', 'venmo', 'cashapp')", "p.status = 'completed'", "s.status <> 'voided'"];
    $args = [];
    if ($state === 'open')    $where[] = 'p.reconciled_at IS NULL AND p.reconcile_flag IS NULL';
    if ($state === 'flagged') $where[] = 'p.reconciled_at IS NULL AND p.reconcile_flag IS NOT NULL';
    if ($state === 'matched') { $where[] = 'p.reconciled_at IS NOT NULL'; }
    if ($from) { $where[] = 'p.business_date >= ?'; $args[] = $from; }
    if ($to)   { $where[] = 'p.business_date <= ?'; $args[] = $to; }
    $st = $pdo->prepare("SELECT p.*, s.sale_number, s.total_cents sale_total, s.created_by sale_by
                         FROM payments p JOIN sales s ON s.id = p.sale_id
                         WHERE " . implode(' AND ', $where) . "
                         ORDER BY p.business_date DESC, p.created_at ASC LIMIT 1000");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Group rows into [date => [tender => [rows...]]], keeping the list order. */
function bcrf_p2p_group(array $rows): array {
    $g = [];
    foreach ($rows as $r) $g[$r['business_date']][$r['tender']][] = $r;
    foreach ($g as &$byT) uksort($byT, fn($a, $b) => array_search($a, array_keys(bcrf_p2p_tenders())) <=> array_search($b, array_keys(bcrf_p2p_tenders())));
    return $g;
}

/** Counts for the Action Center, reports and tab badges. */
function bcrf_p2p_counts(PDO $pdo): array {
    $base = "FROM payments p JOIN sales s ON s.id = p.sale_id
             WHERE p.tender IN ('paypal', 'venmo', 'cashapp') AND p.status = 'completed' AND s.status <> 'voided' AND p.reconciled_at IS NULL";
    $r = $pdo->query("SELECT
            SUM(p.reconcile_flag IS NULL) open_n, COALESCE(SUM(IF(p.reconcile_flag IS NULL, p.amount_cents, 0)), 0) open_cents,
            SUM(p.reconcile_flag IS NOT NULL) flagged_n, COALESCE(SUM(IF(p.reconcile_flag IS NOT NULL, p.amount_cents, 0)), 0) flagged_cents,
            MIN(IF(p.reconcile_flag IS NULL, p.business_date, NULL)) oldest_open $base")->fetch(PDO::FETCH_ASSOC);
    $stale = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(p.amount_cents), 0) $base AND p.reconcile_flag IS NULL AND p.business_date < ?");
    $stale->execute([date('Y-m-d', strtotime(bcrf_farm_today() . ' -' . bcrf_p2p_stale_days() . ' days'))]);
    [$sn, $sc] = $stale->fetch(PDO::FETCH_NUM);
    return ['open' => (int)$r['open_n'], 'open_cents' => (int)$r['open_cents'], 'flagged' => (int)$r['flagged_n'],
            'flagged_cents' => (int)$r['flagged_cents'], 'oldest_open' => $r['oldest_open'], 'stale' => (int)$sn, 'stale_cents' => (int)$sc];
}

/** Farm-local today (Y-m-d). */
function bcrf_farm_today(): string {
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

/** One day's P2P status for the daily sales report: [tender => [n, cents, matched_n, flagged rows]]. */
function bcrf_p2p_day(PDO $pdo, string $date): array {
    $st = $pdo->prepare("SELECT p.*, s.sale_number FROM payments p JOIN sales s ON s.id = p.sale_id
                         WHERE p.tender IN ('paypal', 'venmo', 'cashapp') AND p.status = 'completed' AND s.status <> 'voided' AND p.business_date = ?
                         ORDER BY p.created_at");
    $st->execute([$date]);
    $out = [];
    foreach ($st as $p) {
        $t = &$out[$p['tender']];
        $t ??= ['n' => 0, 'cents' => 0, 'matched' => 0, 'open' => 0, 'flagged' => []];
        $t['n']++; $t['cents'] += (int)$p['amount_cents'];
        if ($p['reconciled_at']) $t['matched']++;
        elseif ($p['reconcile_flag']) $t['flagged'][] = $p;
        else $t['open']++;
        unset($t);
    }
    return $out;
}

/**
 * Mark payments. $action: 'match', 'flag' (needs $flag), 'reopen' (back to "to check": clears match and flag).
 * Only P2P payments on sales that aren't voided. Returns how many rows changed. Audited per payment.
 */
function bcrf_p2p_mark(PDO $pdo, array $paymentIds, string $action, ?string $flag = null): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
    if (!$ids) return 0;
    if ($action === 'flag' && !isset(bcrf_p2p_flags()[$flag])) throw new InvalidArgumentException('Choose what the problem is.');
    if (!in_array($action, ['match', 'flag', 'reopen'], true)) throw new InvalidArgumentException('Unknown action.');
    $uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT p.*, s.sale_number FROM payments p JOIN sales s ON s.id = p.sale_id
                             WHERE p.id IN ($in) AND p.tender IN ('paypal', 'venmo', 'cashapp') AND p.status = 'completed' AND s.status <> 'voided' FOR UPDATE");
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $set = match ($action) {
            'match'  => "reconciled_at = UTC_TIMESTAMP(), reconciled_by = ?, reconcile_flag = NULL",
            'flag'   => "reconciled_at = NULL, reconciled_by = ?, reconcile_flag = ?",
            'reopen' => "reconciled_at = NULL, reconciled_by = NULL, reconcile_flag = NULL",
        };
        $up = $pdo->prepare("UPDATE payments SET $set WHERE id = ?");
        $changed = 0;
        foreach ($rows as $p) {
            $same = ($action === 'match' && $p['reconciled_at'] && !$p['reconcile_flag'])
                 || ($action === 'flag' && !$p['reconciled_at'] && $p['reconcile_flag'] === $flag)
                 || ($action === 'reopen' && !$p['reconciled_at'] && !$p['reconcile_flag']);
            if ($same) continue;
            $up->execute(match ($action) { 'match' => [$uid, $p['id']], 'flag' => [$uid, $flag, $p['id']], 'reopen' => [$p['id']] });
            $changed++;
            if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
                [$type, $aid, $name, $ip] = bcrf_audit_actor();
                $label = bcrf_p2p_tenders()[$p['tender']] . ' $' . number_format($p['amount_cents'] / 100, 2) . ' from ' . ($p['payer_handle'] ?: '?')
                       . ' on ' . $p['sale_number'] . ': ' . ($action === 'match' ? 'matched' : ($action === 'flag' ? 'flagged ' . bcrf_p2p_flags()[$flag] : 'back to check'));
                bcrf_audit_event($pdo, ['actor_type' => $type, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip,
                    'action' => 'p2p_' . $action, 'object_type' => 'payments', 'object_id' => (string)$p['id'], 'object_label' => $label,
                    'before' => ['reconciled_at' => $p['reconciled_at'], 'reconcile_flag' => $p['reconcile_flag']],
                    'after' => ['matched' => $action === 'match', 'flag' => $action === 'flag' ? $flag : null]]);
            }
            if ($action === 'flag' && function_exists('bcrf_event')) {
                bcrf_event('payment.flagged', 'payments', (int)$p['id'], ['tender' => $p['tender'], 'amount_cents' => (int)$p['amount_cents'], 'flag' => $flag, 'sale_number' => $p['sale_number']]);
            }
        }
        $pdo->commit();
        return $changed;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

}
