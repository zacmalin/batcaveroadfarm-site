<?php
// Dormant expiration maintenance. Only pending invitations become expired.
// Accepted and revoked records are preserved unchanged for audit purposes.
// Call from a controlled scheduled worker after migration and launch approval.
function bcrf_roost_expire_household_invitations(PDO $pdo,int $batchSize=100): int {
    if ($batchSize<1 || $batchSize>500)
        throw new InvalidArgumentException('Invalid invitation expiration batch size.');
    if ($pdo->inTransaction())
        throw new LogicException('Expiration requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT id FROM reward_household_invitations
            WHERE status='pending' AND expires_at<=UTC_TIMESTAMP(3)
            ORDER BY expires_at,id LIMIT ".(int)$batchSize." FOR UPDATE");
        $q->execute();
        $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) {
            $pdo->commit();
            return 0;
        }
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $update=$pdo->prepare("UPDATE reward_household_invitations
            SET status='expired' WHERE status='pending' AND id IN ($placeholders)");
        $update->execute($ids);
        $count=$update->rowCount();
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
