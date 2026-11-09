<?php
// Read-only consent status for authenticated Rewards customer pages.
// No evidence references or staff details are exposed.
function bcrf_roost_customer_consent_status(PDO $pdo,int $customerId,string $purpose): ?array {
    if ($customerId<=0 || !in_array($purpose,['rewards_enrollment','household_join'],true))
        throw new InvalidArgumentException('Invalid customer or purpose.');
    $q=$pdo->prepare("SELECT status,policy_version,created_at FROM reward_consent_events
        WHERE customer_id=? AND purpose=? ORDER BY created_at DESC,id DESC LIMIT 1");
    $q->execute([$customerId,$purpose]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
