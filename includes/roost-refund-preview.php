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
        $detail = $pdo->prepare("SELECT sale_line_id, net_cents FROM refund_lines WHERE refund_id=?".$suffix);
        $detail->execute([$refundId]);
        $sum = 0;
        $eligible = 0;
        $count = 0;
        foreach ($detail->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $lineId = (int)$item['sale_line_id'];
            $cents = (int)$item['net_cents'];
            if (!isset($byId[$lineId]) || $cents < 0 || $sum > PHP_INT_MAX-$cents)
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
    return [
        'original_eligible_cents'=>$original,
        'remaining_eligible_cents'=>$remaining,
        'cumulative_points_to_reverse'=>bcrf_rewards_cumulative_refund_points($original,$remaining),
        'eligible_refunds_cents'=>$eligibleRefunds
    ];
}
