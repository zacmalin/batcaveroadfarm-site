<?php
// Farm Rewards: read-only POS eligibility preview. Does not award points.
// Caller must authenticate the sale/customer association and enforce the
// approved policy before calling the ledger writer.
if (!class_exists('BcrfRewardError')) {
    class BcrfRewardError extends RuntimeException {}
}

/**
 * Approved policy: five whole points per eligible dollar, floored per sale.
 * Integer-only arithmetic avoids floating point drift and multiplication overflow.
 * No fractional remainder carries to subsequent purchases.
 */
function bcrf_rewards_points_for_eligible_cents(int $eligibleCents): int {
    if ($eligibleCents <= 0) throw new BcrfRewardError('Eligible cents must be positive.');
    $wholeDollars = intdiv($eligibleCents, 100);
    $remainderCents = $eligibleCents % 100;
    if ($wholeDollars > intdiv(PHP_INT_MAX - 4, 5)) {
        throw new BcrfRewardError('Reward points overflow.');
    }
    $points = $wholeDollars * 5 + intdiv($remainderCents * 5, 100);
    return $points;
}

/**
 * Returns eligible paid net cents from a completed POS sale, excluding tax,
 * tips, gift cards, passes, refunded amounts and reward-funded payments.
 * Conservative by design: unclassified types are not eligible.
 * Point conversion is separate and floors per sale without carryover.
 */
function bcrf_rewards_sale_eligible_cents(PDO $pdo, int $saleId): int {
    if ($saleId <= 0) throw new BcrfRewardError('Invalid sale.');
    $st = $pdo->prepare("SELECT id, status, subtotal_cents, discount_cents, total_cents FROM sales WHERE id = ?");
    $st->execute([$saleId]);
    $sale = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sale || !in_array($sale['status'], ['completed','partially_refunded'], true)) {
        throw new BcrfRewardError('Sale is not eligible for rewards.');
    }
    if ((int)$sale['total_cents'] <= 0) throw new BcrfRewardError('Nonpositive sale total.');
    $saleNet = (int)$sale['subtotal_cents'] - (int)$sale['discount_cents'];
    if ((int)$sale['subtotal_cents'] < 0 || (int)$sale['discount_cents'] < 0 || $saleNet < 0 ||
        $saleNet > (int)$sale['total_cents']) {
        throw new BcrfRewardError('Invalid sale totals.');
    }
    $lines = $pdo->prepare("SELECT product_type, gross_cents, discount_cents, refunded_net_cents FROM sale_lines WHERE sale_id = ?" . ($pdo->inTransaction() ? " FOR UPDATE" : ""));
    $lines->execute([$saleId]);
    $allowed = ['retail','produce','kitchen_good','admission','thrift_item'];
    $eligible = 0;
    $lineCount = 0;
    $grossTotal = 0;
    $discountTotal = 0;
    while ($line = $lines->fetch(PDO::FETCH_ASSOC)) {
        $lineCount++;
        $gross = (int)$line['gross_cents'];
        $discount = (int)$line['discount_cents'];
        $refunded = (int)($line['refunded_net_cents'] ?? 0);
        if ($gross < 0 || $discount < 0 || $discount > $gross || $refunded < 0 || $refunded > $gross - $discount) {
            throw new BcrfRewardError('Invalid sale line amounts.');
        }
        if ($grossTotal > PHP_INT_MAX - $gross || $discountTotal > PHP_INT_MAX - $discount) {
            throw new BcrfRewardError('Sale line total overflow.');
        }
        $grossTotal += $gross;
        $discountTotal += $discount;
        if (!in_array($line['product_type'], $allowed, true)) continue;
        $net = $gross - $discount - $refunded;
        if ($eligible > PHP_INT_MAX - $net) throw new BcrfRewardError('Eligible amount overflow.');
        $eligible += $net;
    }
    if ($lineCount === 0) throw new BcrfRewardError('Sale has no line items.');
    if ($eligible <= 0) throw new BcrfRewardError('Sale contains no eligible net value.');
    if ($grossTotal !== (int)$sale['subtotal_cents'] || $discountTotal !== (int)$sale['discount_cents']) {
        throw new BcrfRewardError('Sale lines do not match recorded subtotal or discounts.');
    }
    if ($eligible > $saleNet) throw new BcrfRewardError('Eligible amount exceeds sale net subtotal.');
    // Gift-card/store-credit/reward payment allocation is not yet safely
    // reconciled by line. Fail closed rather than overaward on mixed tenders.
    $tenders = $pdo->prepare("SELECT tender, amount_cents, status, reconciled_at, reconcile_flag FROM payments WHERE sale_id = ?" . ($pdo->inTransaction() ? " FOR UPDATE" : ""));
    $tenders->execute([$saleId]);
    $paid = 0;
    while ($payment = $tenders->fetch(PDO::FETCH_ASSOC)) {
        // A pending, failed or reversed tender alongside completed tenders
        // makes allocation ambiguous. Require every recorded payment settled.
        if ($payment['status'] !== 'completed') {
            throw new BcrfRewardError('Sale has an unresolved payment.');
        }
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

/**
 * Resolve the one verified active household belonging to the sale's recorded
 * customer. Never infer ownership from a name, email, or payment handle.
 * This is a read-only guard, not a customer-claim or identity-verification API.
 */
function bcrf_rewards_sale_verified_household(PDO $pdo, int $saleId): array {
    if ($saleId <= 0) throw new BcrfRewardError('Invalid sale.');
    $st = $pdo->prepare("SELECT s.customer_id, m.household_id
        FROM sales s
        JOIN reward_household_members m ON m.customer_id = s.customer_id
        JOIN reward_households h ON h.id = m.household_id
        WHERE s.id = ? AND s.customer_id IS NOT NULL
          AND m.revoked_at IS NULL AND m.verification_status = 'verified'
          AND h.status = 'active'");
    $st->execute([$saleId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // An unverified or revoked second membership must not be silently
    // treated as a valid alternate household; only verified active rows count.

    if (count($rows) !== 1) throw new BcrfRewardError('Sale has no unique verified active household.');
    return ['customer_id'=>(int)$rows[0]['customer_id'], 'household_id'=>(int)$rows[0]['household_id']];
}

/**
 * Read-only claim preview for a customer authenticated by a trusted caller.
 * Never accept the customer ID from an unauthenticated browser request.
 * Does not establish payment ownership or award points.
 *
 * @return array{customer_id:int,household_id:int,eligible_cents:int,points:int}
 */
function bcrf_rewards_sale_claim_preview(PDO $pdo, int $saleId, int $authenticatedCustomerId): array {
    if ($authenticatedCustomerId <= 0) throw new BcrfRewardError('Authenticated customer required.');
    $owner = bcrf_rewards_sale_verified_household($pdo, $saleId);
    if ($owner['customer_id'] !== $authenticatedCustomerId) {
        throw new BcrfRewardError('Sale customer does not match authenticated customer.');
    }
    // A claim preview must be internally consistent if the sale's customer
    // assignment changes during the read-only validation sequence.
    $eligible = bcrf_rewards_sale_eligible_cents($pdo, $saleId);
    $recheck = bcrf_rewards_sale_verified_household($pdo, $saleId);
    if ($recheck !== $owner) {
        throw new BcrfRewardError('Sale household changed during claim preview.');
    }
    return ['customer_id'=>$owner['customer_id'],'household_id'=>$owner['household_id'],'eligible_cents'=>$eligible,'points'=>bcrf_rewards_points_for_eligible_cents($eligible)];
}
