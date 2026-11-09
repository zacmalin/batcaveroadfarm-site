<?php
// Dormant invitation acceptance service. The caller must independently confirm
// the authenticated adult's age/identity and explicit household-join consent.
// A bearer token alone never authorizes membership.
require_once __DIR__.'/roost-household-invitations.php';
function bcrf_roost_accept_household_invitation(PDO $pdo,string $token,int $customerId,bool $adultVerified,bool $joinConsent): array {
    if ($customerId<=0 || !$adultVerified || !$joinConsent || !preg_match('/^[a-f0-9]{64}$/D',$token))
        throw new InvalidArgumentException('Valid invitation, verified adult and consent required.');
    if ($pdo->inTransaction()) throw new LogicException('Acceptance requires its own transaction.');
    $pdo->beginTransaction();
    try {
        // Lock the customer first, matching enrollment's lock order.
        $q=$pdo->prepare("SELECT email FROM customers WHERE id=? AND account_status='active'
            AND email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$customerId]);
        $email=$q->fetchColumn();
        if (!$email) throw new DomainException('Active verified customer required.');
        $q=$pdo->prepare("SELECT household_id FROM reward_household_members
            WHERE customer_id=? AND revoked_at IS NULL FOR UPDATE");
        $q->execute([$customerId]);
        if ($q->fetchColumn()) throw new DomainException('Already a household member.');
        $q=$pdo->prepare("SELECT i.id,i.household_id,i.invitee_email_hash
            FROM reward_household_invitations i
            JOIN reward_households h ON h.id=i.household_id AND h.status='active'
            WHERE i.token_hash=? AND i.status='pending'
              AND i.expires_at>UTC_TIMESTAMP(3) FOR UPDATE");
        $q->execute([hash('sha256',$token)]);
        $invite=$q->fetch(PDO::FETCH_ASSOC);
        if (!$invite || !hash_equals($invite['invitee_email_hash'],hash('sha256',bcrf_roost_normalized_email((string)$email))))
            throw new DomainException('Invitation unavailable for this account.');
        $q=$pdo->prepare("INSERT INTO reward_household_members
            (household_id,customer_id,role,verification_status,can_redeem,joined_at)
            VALUES (?,?,'adult','verified',0,UTC_TIMESTAMP(3))");
        $q->execute([(int)$invite['household_id'],$customerId]);
        $q=$pdo->prepare("UPDATE reward_household_invitations
            SET status='accepted',accepted_by_customer_id=?,accepted_at=UTC_TIMESTAMP(3)
            WHERE id=? AND status='pending'");
        $q->execute([$customerId,(int)$invite['id']]);
        if ($q->rowCount()!==1) throw new RuntimeException('Invitation state changed.');
        $pdo->commit();
        return ['accepted'=>true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
