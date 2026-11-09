<?php
// Removes reversible invitation secrets from terminal queue rows only.
// Never deletes invitation records or queue audit metadata; no scheduler.
function bcrf_roost_purge_invitation_delivery_payloads(PDO $pdo,int $minimumAgeDays=7,int $batchSize=100): int {
    if ($minimumAgeDays<1 || $minimumAgeDays>3650 || $batchSize<1 || $batchSize>500)
        throw new InvalidArgumentException('Invalid invitation retention bounds.');
    if ($pdo->inTransaction()) throw new LogicException('Purge requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->query("SELECT id FROM reward_invitation_delivery_outbox
            WHERE status IN ('sent','suppressed','failed')
              AND updated_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ".$minimumAgeDays." DAY)
              AND payload_ciphertext<>''
            ORDER BY id ASC LIMIT ".$batchSize." FOR UPDATE SKIP LOCKED");
        $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) { $pdo->commit(); return 0; }
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $u=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
            SET payload_nonce='',payload_ciphertext='',payload_tag=''
            WHERE id IN ($marks) AND status IN ('sent','suppressed','failed')
              AND payload_ciphertext<>''");
        $u->execute($ids);
        $count=$u->rowCount();
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
