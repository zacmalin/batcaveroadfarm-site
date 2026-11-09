<?php
// Dormant authenticated-account consent recorder. Caller must obtain the
// customer ID from a trusted session, enforce CSRF and display the approved
// versioned policy before calling. Never infer marketing consent.
function bcrf_roost_record_customer_consent(PDO $pdo,int $customerId,string $purpose,string $policyVersion,bool $affirmative): int {
    if ($customerId<=0 || !in_array($purpose,['rewards_enrollment','household_join'],true)
        || !preg_match('/^[A-Za-z0-9._-]{1,40}$/D',$policyVersion))
        throw new InvalidArgumentException('Invalid customer, purpose or policy version.');
    if (!$affirmative) throw new DomainException('Affirmative consent required.');
    if ($pdo->inTransaction()) throw new LogicException('Consent recording requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT id FROM customers WHERE id=? AND account_status='active'
            AND email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$customerId]);
        if (!$q->fetchColumn()) throw new DomainException('Verified active customer required.');
        $q=$pdo->prepare("INSERT INTO reward_consent_events
            (customer_id,purpose,policy_version,status,source,created_at)
            VALUES (?,?,?,'granted','authenticated_account',UTC_TIMESTAMP(3))");
        $q->execute([$customerId,$purpose,$policyVersion]);
        $id=(int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
