<?php
// Trusted internal redemption settlement. No public route or automatic
// fulfillment: the caller must first verify an approved, delivered benefit.
require_once __DIR__.'/rewards.php';
require_once __DIR__.'/roost-redemption-authorization.php';

/**
 * Record an already fulfilled benefit exactly once.
 * $fulfillmentId is a stable, unique internal receipt, not user-supplied text.
 * A caller must independently establish fulfillment and authorization.
 */
function bcrf_roost_settle_fulfilled_redemption(
    PDO $pdo,int $householdId,int $customerId,int $policyId,int $tier,
    string $fulfillmentId,bool $fulfillmentVerified=false
): array {
    // Legacy compatibility helper: a caller-supplied boolean is not durable
    // proof of delivery. Do not allow new settlements through this path.
    // Approved internal vouchers must use bcrf_roost_issue_redemption_receipt().
    throw new LogicException('Legacy redemption settlement disabled; use approved voucher issuance.');
    if ($householdId<=0 || $customerId<=0 || $policyId<=0 ||
        !in_array($tier,[250,500,1000,2000],true) ||
        !preg_match('/^[A-Za-z0-9_-]{8,64}$/D',$fulfillmentId))
        throw new InvalidArgumentException('Invalid redemption settlement.');
    if ($pdo->inTransaction()) throw new LogicException('Redemption settlement requires autocommit.');
    $auth=bcrf_roost_redemption_authorization($pdo,$householdId,$customerId,$tier);
    if (!$auth['authorized']) throw new BcrfRewardError('Redeemer is not authorized.');
    // A depleted balance may represent a legitimate retry. The ledger's
    // locked idempotency check decides replay versus a new deduction.
    // The ledger performs final balance and membership checks under row locks.
    // A retry of the same receipt must be allowed after points were deducted.
    return bcrf_reward_post($pdo,[
        'household_id'=>$householdId,'customer_id'=>$customerId,
        'policy_id'=>$policyId,'entry_type'=>'redeem',
        'points_delta'=>-$tier,'source_type'=>'reward_fulfillment',
        'source_id'=>$fulfillmentId,
        'idempotency_key'=>'roost:redeem:'.$fulfillmentId,
        'metadata'=>['tier_points'=>$tier]
    ]);
}
