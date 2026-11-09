<?php
// Read-only redemption authorization and tier eligibility. This is NOT a
// redemption transaction, reservation, benefit issuance, or checkout route.
// No launch activation: benefits and fulfillment must be approved first.
function bcrf_roost_redemption_authorization(PDO $pdo,int $householdId,int $customerId,int $tier): array {
    if ($householdId<=0 || $customerId<=0 || !in_array($tier,[250,500,1000,2000],true))
        throw new InvalidArgumentException('Invalid reward redemption request.');
    if ($pdo->inTransaction()) throw new LogicException('Redemption preview requires autocommit.');
    $q=$pdo->prepare("SELECT a.balance_points,h.status AS household_status,
        m.role,m.can_redeem,m.verification_status,m.revoked_at,
        c.account_status,c.email_verified_at
        FROM reward_accounts a
        JOIN reward_households h ON h.id=a.household_id
        JOIN reward_household_members m ON m.household_id=h.id AND m.customer_id=?
        JOIN customers c ON c.id=m.customer_id
        WHERE a.household_id=? LIMIT 1");
    $q->execute([$customerId,$householdId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['household_status']!=='active' ||
        $row['account_status']!=='active' || $row['email_verified_at']===null ||
        $row['verification_status']!=='verified' || $row['revoked_at']!==null ||
        !in_array($row['role'],['manager','adult'],true) || (int)$row['can_redeem']!==1)
        return ['authorized'=>false,'eligible'=>false,'points_needed'=>null];
    $balance=(int)$row['balance_points'];
    return ['authorized'=>true,'eligible'=>$balance >= $tier,
        'points_needed'=>max(0,$tier-$balance)];
}
