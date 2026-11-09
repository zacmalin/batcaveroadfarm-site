<?php
// Customer-scoped, read-only ledger history. Never accept a household ID from
// request parameters. Uses the same verified adult membership gate as balance.
require_once __DIR__.'/roost-customer-balance.php';
function bcrf_roost_customer_history(PDO $pdo, int $customerId, int $limit=25): array {
    if ($limit<1 || $limit>50) throw new InvalidArgumentException('Invalid history limit.');
    $account=bcrf_roost_customer_balance($pdo,$customerId);
    if ($account===null) return [];
    $st=$pdo->prepare("SELECT l.id,l.entry_type,l.points_delta,l.created_at
        FROM reward_ledger l
        JOIN reward_household_members m ON m.household_id=l.household_id
        JOIN reward_households h ON h.id=m.household_id AND h.status='active'
        JOIN customers c ON c.id=m.customer_id
        WHERE m.customer_id=? AND m.revoked_at IS NULL
          AND m.verification_status='verified' AND m.role IN ('manager','adult')
          AND c.account_status='active' AND c.email_verified_at IS NOT NULL
        ORDER BY l.created_at DESC,l.id DESC LIMIT ".(int)$limit);
    $st->execute([$customerId]);
    $items=[];
    while ($r=$st->fetch(PDO::FETCH_ASSOC)) {
        $items[]=[
            'id'=>(int)$r['id'],
            'entry_type'=>(string)$r['entry_type'],
            'points_delta'=>(int)$r['points_delta'],
            'created_at'=>(string)$r['created_at'],
        ];
    }
    return $items;
}
