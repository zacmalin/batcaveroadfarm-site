<?php
// Read-only Rewards household roster for an authenticated verified adult.
// Return no names, emails, or identifiers for other household members.
// Do not use customer-supplied household IDs or grant management privileges.
require_once __DIR__.'/roost-customer-balance.php';
function bcrf_roost_customer_household_summary(PDO $pdo, int $customerId): ?array {
    if ($customerId<=0) throw new InvalidArgumentException('Authenticated customer required.');
    if (bcrf_roost_customer_balance($pdo,$customerId)===null) return null;
    $st=$pdo->prepare("SELECT self.role,
        (SELECT COUNT(*) FROM reward_household_members m
         WHERE m.household_id=self.household_id AND m.revoked_at IS NULL
           AND m.verification_status='verified') AS verified_members,
        (SELECT COUNT(*) FROM reward_household_members m
         WHERE m.household_id=self.household_id AND m.revoked_at IS NULL
           AND m.verification_status='pending') AS pending_members
        FROM reward_household_members self
        JOIN reward_households h ON h.id=self.household_id AND h.status='active'
        JOIN customers c ON c.id=self.customer_id
        WHERE self.customer_id=? AND self.revoked_at IS NULL
          AND self.verification_status='verified'
          AND self.role IN ('manager','adult')
          AND c.account_status='active' AND c.email_verified_at IS NOT NULL
        LIMIT 1");
    $st->execute([$customerId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row ? [
        'role'=>(string)$row['role'],
        'verified_members'=>(int)$row['verified_members'],
        'pending_members'=>(int)$row['pending_members']
    ] : null;
}
