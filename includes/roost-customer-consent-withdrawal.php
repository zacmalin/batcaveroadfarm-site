<?php
// Dormant consent withdrawal service. Withdrawal does not remove an existing
// household membership or erase audit history; staff policy handles that.
function bcrf_roost_withdraw_customer_consent(PDO $pdo,int $customerId,string $purpose,string $policyVersion): int {
    if ($customerId<=0 || !in_array($purpose,['rewards_enrollment','household_join'],true)
        || !preg_match('/^[A-Za-z0-9._-]{1,40}$/D',$policyVersion))
        throw new InvalidArgumentException('Invalid customer, purpose or policy version.');
    if ($pdo->inTransaction()) throw new LogicException('Consent withdrawal requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT id FROM customers WHERE id=? AND account_status='active'
            AND email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$customerId]);
        if (!$q->fetchColumn()) throw new DomainException('Verified active customer required.');
        $q=$pdo->prepare("INSERT INTO reward_consent_events
            (customer_id,purpose,policy_version,status,source,created_at)
            VALUES (?,?,?,'withdrawn','authenticated_account',UTC_TIMESTAMP(3))");
        $q->execute([$customerId,$purpose,$policyVersion]);
        $id=(int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
