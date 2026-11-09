<?php
// Retained only for compatibility with legacy internal callers.
// A caller-provided boolean cannot prove durable benefit fulfillment.
// All approved internal-voucher redemption must use
// bcrf_roost_issue_redemption_receipt() instead.
function bcrf_roost_settle_fulfilled_redemption(
    PDO $pdo,int $householdId,int $customerId,int $policyId,int $tier,
    string $fulfillmentId,bool $fulfillmentVerified=false
): array {
    throw new LogicException('Legacy redemption settlement disabled; use approved voucher issuance.');
}
