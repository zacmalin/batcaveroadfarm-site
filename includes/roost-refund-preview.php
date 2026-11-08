<?php
// Read-only refund point validation. Never posts a reversal.
require_once __DIR__ . '/rewards-pos-preview.php';

/**
 * Returns cumulative reversal points based on verified refund line amounts.
 * Intended for trusted transaction-scoped callers; locks records if a transaction exists.
 * Rejects inconsistent refund totals, non-sale lines and out-of-range amounts.
 */
function bcrf_roost_refund_reversal_preview(PDO $pdo, int $saleId): array {
    if ($saleId < 1) throw new BcrfRewardError('Invalid sale.');
    $suffix = $pdo->inTransaction() ? ' FOR UPDATE' : '';
    $saleCheck = $pdo->prepare("SELECT status FROM sales WHERE id=?" . $suffix);
    $saleCheck->execute([$saleId]);
    $status = $saleCheck->fetchColumn();
    if ($status === false || !in_array($status, ['completed','partially_refunded','refunded'], true)) {
        throw new BcrfRewardError('Refund references an invalid or voided sale.');
    }
    $lines = $pdo->prepare("SELECT id, product_type, gross_cents, discount_cents, refunded_net_cents
        FROM sale_lines WHERE sale_id=?".$suffix);
    $lines->execute([$saleId]);
    $eligibleTypes = ['retail','produce','kitchen_good','admission','thrift_item'];
    $byId = [];
    $original = 0;
    $remaining = 0;
    foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $id = (int)$line['id'];
        $net = (int)$line['gross_cents'] - (int)$line['discount_cents'];
        $refunded = (int)$line['refunded_net_cents'];
        if ($id <= 0 || isset($byId[$id]) || $net < 0 || $refunded < 0 || $refunded > $net)
            throw new BcrfRewardError('Invalid sale line.');
        $byId[$id] = ['net'=>$net,'refunded'=>$refunded,'eligible'=>in_array($line['product_type'],$eligibleTypes,true)];
        if ($byId[$id]['eligible']) {
            if ($original > PHP_INT_MAX - $net || $remaining > PHP_INT_MAX - ($net-$refunded))
                throw new BcrfRewardError('Refund amount overflow.');
            $original += $net;
            $remaining += $net-$refunded;
        }
    }
    if (!$byId || $original <= 0) throw new BcrfRewardError('No eligible original sale lines.');
    $refunds = $pdo->prepare("SELECT id, net_cents FROM refunds WHERE sale_id=?".$suffix);
    $refunds->execute([$saleId]);
    $sumByLine = [];
    $eligibleRefunds = [];
    foreach ($refunds->fetchAll(PDO::FETCH_ASSOC) as $refund) {
        $refundId = (int)$refund['id'];
        if ($refundId <= 0 || array_key_exists($refundId, $eligibleRefunds) ||
            (int)$refund['net_cents'] < 0) {
            throw new BcrfRewardError('Invalid refund header.');
        }
        $detail = $pdo->prepare("SELECT sale_line_id, net_cents FROM refund_lines WHERE refund_id=?".$suffix);
        $detail->execute([$refundId]);
        $sum = 0;
        $eligible = 0;
        $count = 0;
        foreach ($detail->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $lineId = (int)$item['sale_line_id'];
            $cents = (int)$item['net_cents'];
            if (!isset($byId[$lineId]) || $cents < 0 || $sum > PHP_INT_MAX-$cents ||
                ($sumByLine[$lineId] ?? 0) > PHP_INT_MAX-$cents ||
                ($byId[$lineId]['eligible'] && $eligible > PHP_INT_MAX-$cents))
                throw new BcrfRewardError('Invalid refund line provenance or amount.');
            $sum += $cents;
            $count++;
            $sumByLine[$lineId] = ($sumByLine[$lineId] ?? 0) + $cents;
            if ($byId[$lineId]['eligible']) $eligible += $cents;
        }
        if ($count === 0 || $sum !== (int)$refund['net_cents'])
            throw new BcrfRewardError('Refund line totals do not reconcile.');
        $eligibleRefunds[$refundId] = $eligible;
    }
    foreach ($byId as $id=>$line) {
        if (($sumByLine[$id] ?? 0) !== $line['refunded'])
            throw new BcrfRewardError('Refund line totals differ from sale line running totals.');
    }
    // The current refund's exact point delta is the cumulative amount owed
    // minus reversals already committed for this original sale earning.
    // This helper is read-only; the caller must hold the account lock and
    // recheck all source rows in the same posting transaction.
    return [
        'original_eligible_cents'=>$original,
        'remaining_eligible_cents'=>$remaining,
        'cumulative_points_to_reverse'=>bcrf_rewards_cumulative_refund_points($original,$remaining),
        'eligible_refunds_cents'=>$eligibleRefunds
    ];
}

/**
 * Determine the exact next reversal from verified cumulative refund points and
 * previously posted reversal points. Never reverse more than was earned.
 * Zero means a refund crossed no whole-point threshold and needs no ledger debit.
 */
function bcrf_roost_refund_points_due(int $originalEarnedPoints, int $cumulativeRefundPoints, int $alreadyReversedPoints): int {
    if ($originalEarnedPoints < 0 || $cumulativeRefundPoints < 0 ||
        $alreadyReversedPoints < 0 || $cumulativeRefundPoints > $originalEarnedPoints ||
        $alreadyReversedPoints > $cumulativeRefundPoints) {
        throw new BcrfRewardError('Inconsistent cumulative reward reversal amounts.');
    }
    return $cumulativeRefundPoints - $alreadyReversedPoints;
}

/**
 * Trusted caller preflight for a refund with no point impact. This does not
 * mutate the ledger or mark a refund processed. A later eligible refund may
 * cross the rounding threshold, so always re-evaluate cumulative totals.
 */
function bcrf_roost_refund_is_points_noop(PDO $pdo, int $saleId, int $earnedPoints, int $alreadyReversedPoints): bool {
    $preview = bcrf_roost_refund_reversal_preview($pdo, $saleId);
    return bcrf_roost_refund_points_due(
        $earnedPoints,
        (int)$preview['cumulative_points_to_reverse'],
        $alreadyReversedPoints
    ) === 0;
}
