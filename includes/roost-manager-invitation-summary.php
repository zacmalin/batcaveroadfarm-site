<?php
// Read-only manager-scoped invitation status. Never return bearer tokens,
// raw email addresses, or recipient hashes to customer-facing code.
function bcrf_roost_manager_invitation_summary(PDO $pdo,int $customerId): array {
    if ($customerId<=0) throw new InvalidArgumentException('Customer required.');
    $q=$pdo->prepare("SELECT m.household_id FROM reward_household_members m
        JOIN reward_households h ON h.id=m.household_id AND h.status='active'
        JOIN customers c ON c.id=m.customer_id
        WHERE m.customer_id=? AND m.role='manager' AND m.verification_status='verified'
          AND m.revoked_at IS NULL AND c.account_status='active'
          AND c.email_verified_at IS NOT NULL LIMIT 1");
    $q->execute([$customerId]);
    $householdId=$q->fetchColumn();
    if (!$householdId) return [];
    $q=$pdo->prepare("SELECT id,
        CASE WHEN status='pending' AND expires_at<=UTC_TIMESTAMP(3)
             THEN 'expired' ELSE status END AS display_status,
        created_at,expires_at
        FROM reward_household_invitations
        WHERE household_id=? ORDER BY id DESC LIMIT 20");
    $q->execute([(int)$householdId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
