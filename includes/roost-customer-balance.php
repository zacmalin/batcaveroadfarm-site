<?php
// Customer-only read-only Rewards balance. Never use a supplied household ID.
// No enrollment, awards, redemptions, or schema changes.
function bcrf_roost_customer_balance(PDO $pdo, int $customerId): ?array {
    if ($customerId <= 0) throw new InvalidArgumentException('Authenticated customer required.');
    $st = $pdo->prepare("SELECT a.household_id, a.balance_points, a.lifetime_earned,
            a.lifetime_redeemed, h.last_qualifying_activity_at
        FROM reward_household_members m
        JOIN reward_households h ON h.id=m.household_id AND h.status='active'
        JOIN reward_accounts a ON a.household_id=h.id
        JOIN customers c ON c.id=m.customer_id
        WHERE m.customer_id=? AND m.revoked_at IS NULL
          AND m.verification_status='verified'
          AND m.role IN ('manager','adult')
          AND c.account_status='active' AND c.email_verified_at IS NOT NULL
        LIMIT 1");
    $st->execute([$customerId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row ? [
        'balance_points'=>(int)$row['balance_points'],
        'lifetime_earned'=>(int)$row['lifetime_earned'],
        'lifetime_redeemed'=>(int)$row['lifetime_redeemed'],
        'last_qualifying_activity_at'=>$row['last_qualifying_activity_at']
    ] : null;
}
