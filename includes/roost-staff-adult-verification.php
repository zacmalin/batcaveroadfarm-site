<?php
// Trusted staff-only service. The caller must authorize the staff actor and
// verify adulthood outside this function. Never accept customer self-attestation.
// Store an opaque audit reference, never a date of birth or ID document.
function bcrf_roost_record_staff_adult_verification(PDO $pdo,int $customerId,int $staffId,string $method,string $reference): int {
    if ($customerId<=0 || $staffId<=0
        || !in_array($method,['staff_review','in_person_id_check'],true)
        || !preg_match('/^[A-Za-z0-9._:-]{1,120}$/D',$reference))
        throw new InvalidArgumentException('Approved staff verification evidence required.');
    if ($pdo->inTransaction()) throw new LogicException('Verification requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT id FROM customers WHERE id=? AND account_status='active'
            AND email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$customerId]);
        if (!$q->fetchColumn()) throw new DomainException('Active verified-email customer required.');
        $q=$pdo->prepare("SELECT id,status,revoked_at FROM reward_adult_verifications
            WHERE customer_id=? ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE");
        $q->execute([$customerId]);
        $latest=$q->fetch(PDO::FETCH_ASSOC);
        if ($latest && $latest['status']==='verified' && $latest['revoked_at']===null)
            throw new DomainException('Adult verification already active.');
        $q=$pdo->prepare("INSERT INTO reward_adult_verifications
            (customer_id,status,method,evidence_reference,verified_by_staff_id,verified_at,created_at)
            VALUES (?,'verified',?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $q->execute([$customerId,$method,$reference,$staffId]);
        $id=(int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
