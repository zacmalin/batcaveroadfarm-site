<?php
// Dormant authenticated customer invitation preview. The bearer token is
// checked against the signed-in account's verified email and never returned.
// This preview does not accept invitations or disclose household member PII.
require_once __DIR__.'/roost-household-invitations.php';
function bcrf_roost_customer_invitation_preview(PDO $pdo,int $customerId,string $token): ?array {
    if ($customerId<=0 || !preg_match('/^[a-f0-9]{64}$/D',$token))
        return null;
    $q=$pdo->prepare("SELECT email FROM customers WHERE id=? AND account_status='active'
        AND email_verified_at IS NOT NULL LIMIT 1");
    $q->execute([$customerId]);
    $email=$q->fetchColumn();
    if (!$email) return null;
    $q=$pdo->prepare("SELECT i.id,i.household_id,i.expires_at
        FROM reward_household_invitations i
        JOIN reward_households h ON h.id=i.household_id AND h.status='active'
        WHERE i.token_hash=? AND i.invitee_email_hash=? AND i.status='pending'
          AND i.expires_at>UTC_TIMESTAMP(3) LIMIT 1");
    $q->execute([hash('sha256',$token),hash('sha256',bcrf_roost_normalized_email((string)$email))]);
    $invite=$q->fetch(PDO::FETCH_ASSOC);
    if (!$invite) return null;
    $q=$pdo->prepare("SELECT 1 FROM reward_household_members
        WHERE customer_id=? AND revoked_at IS NULL LIMIT 1");
    $q->execute([$customerId]);
    if ($q->fetchColumn()) return null;
    return ['invitation_id'=>(int)$invite['id'],
        'expires_at'=>(string)$invite['expires_at']];
}
