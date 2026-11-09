<?php
// Evidence gate for trusted Rewards services. An HTTP checkbox is never
// sufficient evidence of adult verification or recorded consent.
function bcrf_roost_verified_adult_with_consent(PDO $pdo,int $customerId,string $purpose): bool {
    if ($customerId<=0 || !in_array($purpose,['rewards_enrollment','household_join'],true))
        throw new InvalidArgumentException('Invalid customer or consent purpose.');
    $q=$pdo->prepare("SELECT v.status,v.revoked_at FROM reward_adult_verifications v
        JOIN customers c ON c.id=v.customer_id
        WHERE v.customer_id=?
          AND c.account_status='active' AND c.email_verified_at IS NOT NULL
        ORDER BY v.created_at DESC,v.id DESC LIMIT 1");
    $q->execute([$customerId]);
    $verification=$q->fetch(PDO::FETCH_ASSOC);
    if (!$verification || $verification['status']!=='verified' || $verification['revoked_at']!==null) return false;
    $q=$pdo->prepare("SELECT status FROM reward_consent_events
        WHERE customer_id=? AND purpose=?
        ORDER BY created_at DESC,id DESC LIMIT 1");
    $q->execute([$customerId,$purpose]);
    return $q->fetchColumn()==='granted';
}
