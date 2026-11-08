<?php
// Farm Rewards: read-only POS eligibility preview. Does not award points.
// Caller must authenticate the sale/customer association and enforce the
// approved policy before calling the ledger writer.
if (!class_exists('BcrfRewardError')) {
    class BcrfRewardError extends RuntimeException {}
}

/**
 * Returns eligible paid net cents from a completed POS sale, excluding tax,
 * tips, gift cards, passes, refunded amounts and reward-funded payments.
 * Conservative by design: unclassified types are not eligible.
 * Does not round to points: fractional-point policy remains undecided.
 */
function bcrf_rewards_sale_eligible_cents(PDO $pdo, int $saleId): int {
    if ($saleId <= 0) throw new BcrfRewardError('Invalid sale.');
    $st = $pdo->prepare("SELECT id, status, total_cents FROM sales WHERE id = ?");
    $st->execute([$saleId]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale || !in_array($sale['status'], ['completed','partially_refunded'], true)) {
        throw new BcrfRewardError('Sale is not eligible for rewards.');
    }
    if ((int)$sale['total_cents'] <= 0) throw new BcrfRewardError('Nonpositive sale total.');
    $lines = $pdo->prepare("SELECT product_type, gross_cents, discount_cents, refunded_net_cents FROM sale_lines WHERE sale_id = ?");
    $lines->execute([$saleId]);
    $allowed = ['retail','produce','kitchen_good','admission','thrift_item'];
    $eligible = 0;
    $lineCount = 0;
    while ($line = $lines->fetch(PDO::FETCH_ASSOC)) {
        $lineCount++;
        $gross = (int)$line['gross_cents'];
        $discount = (int)$line['discount_cents'];
        $refunded = (int)($line['refunded_net_cents'] ?? 0);
        if ($gross < 0 || $discount < 0 || $discount > $gross || $refunded < 0 || $refunded > $gross - $discount) {
            throw new BcrfRewardError('Invalid sale line amounts.');
        }
        if (!in_array($line['product_type'], $allowed, true)) continue;
        $net = $gross - $discount - $refunded;
        if ($eligible > PHP_INT_MAX - $net) throw new BcrfRewardError('Eligible amount overflow.');
        $eligible += $net;
    }
    if ($lineCount === 0) throw new BcrfRewardError('Sale has no line items.');
    // Gift-card/store-credit/reward payment allocation is not yet safely
    // reconciled by line. Fail closed rather than overaward on mixed tenders.
    $tenders = $pdo->prepare("SELECT tender, amount_cents, status, reconciled_at, reconcile_flag FROM payments WHERE sale_id = ?");
    $tenders->execute([$saleId]);
    $paid = 0;
    while ($payment = $tenders->fetch(PDO::FETCH_ASSOC)) {
        if ($payment['status'] !== 'completed') continue;
        if (in_array($payment['tender'], ['gift_card','store_credit','reward','rewards'], true)) {
            throw new BcrfRewardError('Sale has unallocated non-earning tender.');
        }
        if (!in_array($payment['tender'], ['cash','paypal','venmo','cashapp','check','card_online'], true)) {
            throw new BcrfRewardError('Unverified payment method.');
        }
        // Cash is accepted at the register. Noncash tenders require explicit
        // reconciliation before reward eligibility; completed != settled.
        if ($payment['tender'] !== 'cash' && empty($payment['reconciled_at'])) {
            throw new BcrfRewardError('Noncash payment not reconciled.');
        }
        if (!empty($payment['reconcile_flag'])) {
            throw new BcrfRewardError('Payment has reconciliation exception.');
        }
        $amount = (int)$payment['amount_cents'];
        if ($amount <= 0 || $paid > PHP_INT_MAX - $amount) throw new BcrfRewardError('Invalid payment amount.');
        $paid += $amount;
    }
    if ($paid !== (int)$sale['total_cents']) throw new BcrfRewardError('Sale payment total does not match recorded sale.');
    return $eligible;
}
