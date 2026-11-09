<?php
// Evidence gate for trusted Rewards services. An HTTP checkbox is never
// sufficient evidence of adult verification or recorded consent.
function bcrf_roost_verified_adult_with_consent(PDO $pdo,int $customerId,string $purpose): bool {
    if ($customerId<=0 || !in_array($purpose,['rewards_enrollment','household_join'],true))
        throw new InvalidArgumentException('Invalid customer or consent purpose.');
    $q=$pdo->prepare("SELECT 1 FROM reward_adult_verifications v
        JOIN customers c ON c.id=v.customer_id
        WHERE v.customer_id=? AND v.status='verified' AND v.revoked_at IS NULL
          AND c.account_status='active' AND c.email_verified_at IS NOT NULL
        LIMIT 1");
    $q->execute([$customerId]);
    if (!$q->fetchColumn()) return false;
    $q=$pdo->prepare("SELECT status FROM reward_consent_events
        WHERE customer_id=? AND purpose=?
        ORDER BY created_at DESC,id DESC LIMIT 1");
    $q->execute([$customerId,$purpose]);
    return $q->fetchColumn()==='granted';
}
