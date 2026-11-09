<?php
// Dormant manager-only invitation revocation. Never accepts a household ID
// from the request. Accepted invitations are immutable audit records.
function bcrf_roost_revoke_household_invitation(PDO $pdo,int $managerCustomerId,int $invitationId): bool {
    if ($managerCustomerId<=0 || $invitationId<=0)
        throw new InvalidArgumentException('Manager and invitation required.');
    if ($pdo->inTransaction()) throw new LogicException('Revocation requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT m.household_id FROM reward_household_members m
            JOIN reward_households h ON h.id=m.household_id AND h.status='active'
            JOIN customers c ON c.id=m.customer_id
            WHERE m.customer_id=? AND m.role='manager' AND m.verification_status='verified'
              AND m.revoked_at IS NULL AND c.account_status='active'
              AND c.email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$managerCustomerId]);
        $householdId=$q->fetchColumn();
        if (!$householdId) throw new DomainException('Verified manager required.');
        $q=$pdo->prepare("UPDATE reward_household_invitations
            SET status='revoked',revoked_at=UTC_TIMESTAMP(3)
            WHERE id=? AND household_id=? AND status='pending' AND expires_at>UTC_TIMESTAMP(3)");
        $q->execute([$invitationId,$householdId]);
        $revoked=$q->rowCount()===1;
        $pdo->commit();
        return $revoked;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
