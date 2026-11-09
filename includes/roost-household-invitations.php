<?php
// Dormant invitation issuing service. Caller must arrange safe delivery of the
// returned token; never log it or store it unencrypted. No email is sent here.
// Email comparison uses a normalized SHA-256 digest; no raw invitee email stored.
function bcrf_roost_normalized_email(string $email): string {
    $normalized=strtolower(trim($email));
    if (strlen($normalized)>254 || !filter_var($normalized,FILTER_VALIDATE_EMAIL))
        throw new InvalidArgumentException('Valid invitee email required.');
    return $normalized;
}
function bcrf_roost_create_household_invitation(PDO $pdo,int $managerCustomerId,string $inviteeEmail,?array $delivery=null): array {
    if ($managerCustomerId<=0) throw new InvalidArgumentException('Manager required.');
    $email=bcrf_roost_normalized_email($inviteeEmail);
    if ($delivery!==null && (!isset($delivery['key'],$delivery['key_id']) || !is_string($delivery['key']) || !is_string($delivery['key_id'])))
        throw new InvalidArgumentException('Valid delivery encryption configuration required.');
    if ($pdo->inTransaction()) throw new LogicException('Invitation requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT m.household_id,c.email
            FROM reward_household_members m
            JOIN reward_households h ON h.id=m.household_id AND h.status='active'
            JOIN customers c ON c.id=m.customer_id
            WHERE m.customer_id=? AND m.role='manager' AND m.verification_status='verified'
              AND m.revoked_at IS NULL AND c.account_status='active'
              AND c.email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$managerCustomerId]);
        $manager=$q->fetch(PDO::FETCH_ASSOC);
        if (!$manager) throw new DomainException('Verified household manager required.');
        if (bcrf_roost_normalized_email((string)$manager['email'])===$email)
            throw new DomainException('Cannot invite yourself.');
        $householdId=(int)$manager['household_id'];
        $emailHash=hash('sha256',$email);
        $q=$pdo->prepare("SELECT COUNT(*) FROM reward_household_invitations
            WHERE household_id=? AND invitee_email_hash=? AND status='pending'
              AND expires_at>UTC_TIMESTAMP(3)");
        $q->execute([$householdId,$emailHash]);
        if ((int)$q->fetchColumn()>0) throw new DomainException('Active invitation already exists.');
        $token=bin2hex(random_bytes(32));
        $q=$pdo->prepare("INSERT INTO reward_household_invitations
            (household_id,invited_by_customer_id,invitee_email_hash,token_hash,status,expires_at,created_at)
            VALUES (?,?,?,?,'pending',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 7 DAY),UTC_TIMESTAMP(3))");
        $q->execute([$householdId,$managerCustomerId,$emailHash,hash('sha256',$token)]);
        $id=(int)$pdo->lastInsertId();
        if ($delivery!==null) {
            require_once __DIR__.'/roost-invitation-delivery-outbox.php';
            bcrf_roost_queue_invitation_delivery($pdo,$id,$email,$token,$delivery['key'],$delivery['key_id']);
        }
        $pdo->commit();
        return ['invitation_id'=>$id,'token'=>$delivery===null ? $token : null,'expires_in_days'=>7,
            'delivery_queued'=>$delivery!==null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
