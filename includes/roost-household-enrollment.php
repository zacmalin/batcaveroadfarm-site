<?php
// Dormant, trusted-caller Rewards household enrollment.
// The caller MUST independently verify adult identity, verified email,
// affirmative Rewards consent and eligibility before invoking this service.
// No public route calls this function. No marketing consent is inferred.
function bcrf_roost_enroll_verified_adult(PDO $pdo, int $customerId, bool $adultVerified, bool $rewardsConsent): array {
    if ($customerId<=0 || !$adultVerified || !$rewardsConsent) {
        throw new InvalidArgumentException('Verified adult and explicit Rewards consent required.');
    }
    if ($pdo->inTransaction()) throw new LogicException('Enrollment requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT id FROM customers WHERE id=? AND account_status='active'
            AND email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$customerId]);
        if (!$q->fetchColumn()) throw new DomainException('Active verified customer required.');
        $q=$pdo->prepare("SELECT household_id,role,verification_status FROM reward_household_members
            WHERE customer_id=? AND revoked_at IS NULL FOR UPDATE");
        $q->execute([$customerId]);
        if ($q->fetch(PDO::FETCH_ASSOC)) throw new DomainException('Customer already belongs to a Rewards household.');
        $pdo->exec("INSERT INTO reward_households (status,created_at) VALUES ('active',UTC_TIMESTAMP(3))");
        $householdId=(int)$pdo->lastInsertId();
        $q=$pdo->prepare("INSERT INTO reward_household_members
            (household_id,customer_id,role,verification_status,can_redeem,joined_at)
            VALUES (?,?,'manager','verified',1,UTC_TIMESTAMP(3))");
        $q->execute([$householdId,$customerId]);
        $q=$pdo->prepare("INSERT INTO reward_accounts (household_id,updated_at)
            VALUES (?,UTC_TIMESTAMP(3))");
        $q->execute([$householdId]);
        $pdo->commit();
        return ['household_id'=>$householdId,'balance_points'=>0];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
