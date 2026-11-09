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
              AND updated_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ".$minimumAgeDays." DAY)
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

/**
 * Terminalize pending jobs whose invitations can no longer be delivered.
 * Does not claim, decrypt, send or touch processing jobs. Run separately from
 * the terminal-payload purge, which applies its own retention window.
 */
function bcrf_roost_suppress_inactive_invitation_delivery(PDO $pdo,int $batchSize=100): int {
    if ($batchSize<1 || $batchSize>500)
        throw new InvalidArgumentException('Invalid invitation suppression batch.');
    if ($pdo->inTransaction()) throw new LogicException('Suppression requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->query("SELECT o.id FROM reward_invitation_delivery_outbox o
            JOIN reward_household_invitations i ON i.id=o.invitation_id
            WHERE o.status='pending'
              AND (i.status<>'pending' OR i.expires_at<=UTC_TIMESTAMP(3))
            ORDER BY o.id ASC LIMIT ".$batchSize." FOR UPDATE SKIP LOCKED");
        $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) { $pdo->commit(); return 0; }
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $u=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
            SET status='suppressed',suppressed_at=UTC_TIMESTAMP(3),
                updated_at=UTC_TIMESTAMP(3)
            WHERE id IN ($marks) AND status='pending'");
        $u->execute($ids);
        $count=$u->rowCount();
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
