<?php
// Dormant queue claim/ack primitives. No SMTP, scheduler or automatic dispatch.
// Callers must check current invitation and inviter eligibility before sending.
function bcrf_roost_claim_invitation_delivery(PDO $pdo): ?array {
    if ($pdo->inTransaction()) throw new LogicException('Claim requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->query("SELECT id FROM reward_invitation_delivery_outbox
            WHERE status='pending' AND available_at<=UTC_TIMESTAMP(3)
            ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
        $id=$q->fetchColumn();
        if (!$id) { $pdo->commit(); return null; }
        $claim=bin2hex(random_bytes(32));
        $q=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
            SET status='processing',claim_token=?,claimed_at=UTC_TIMESTAMP(3),
                attempts=attempts+1,updated_at=UTC_TIMESTAMP(3)
            WHERE id=? AND status='pending'");
        $q->execute([$claim,(int)$id]);
        if ($q->rowCount()!==1) throw new RuntimeException('Invitation claim lost.');
        $pdo->commit();
        return ['id'=>(int)$id,'claim_token'=>$claim];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function bcrf_roost_complete_invitation_delivery(PDO $pdo,int $id,string $claimToken,
    string $outcome): bool {
    if ($id<1 || !preg_match('/^[a-f0-9]{64}$/D',$claimToken) ||
        !in_array($outcome,['sent','suppressed','failed'],true))
        throw new InvalidArgumentException('Invalid invitation delivery completion.');
    $column=$outcome==='sent'?'sent_at':($outcome==='suppressed'?'suppressed_at':null);
    $sql="UPDATE reward_invitation_delivery_outbox SET status=?,claim_token=NULL,
        claimed_at=NULL,updated_at=UTC_TIMESTAMP(3)";
    if ($column!==null) $sql.=",$column=UTC_TIMESTAMP(3)";
    $sql.=" WHERE id=? AND status='processing' AND claim_token=?";
    $q=$pdo->prepare($sql);
    $q->execute([$outcome,$id,$claimToken]);
    return $q->rowCount()===1;
}

/**
 * Recover abandoned processing claims. Retry at most three total claims.
 * This only changes queue state; it never sends mail.
 */
function bcrf_roost_recover_invitation_delivery(PDO $pdo,int $staleMinutes=15,int $batchSize=50): int {
    if ($pdo->inTransaction()) throw new LogicException('Recovery requires its own transaction.');
    $staleMinutes=max(5,min($staleMinutes,1440));
    $batchSize=max(1,min($batchSize,100));
    $pdo->beginTransaction();
    try {
        $q=$pdo->query("SELECT id,attempts,dispatch_started_at FROM reward_invitation_delivery_outbox
            WHERE status='processing' AND claimed_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ".$staleMinutes." MINUTE)
            ORDER BY claimed_at ASC,id ASC LIMIT ".$batchSize." FOR UPDATE SKIP LOCKED");
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $update=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
            SET status=?,claim_token=NULL,claimed_at=NULL,
                available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE),
                last_error_code=?,updated_at=UTC_TIMESTAMP(3)
            WHERE id=? AND status='processing'");
        $count=0;
        foreach ($rows as $row) {
            $update->execute([
                $row['dispatch_started_at']!==null || (int)$row['attempts']>=3?'failed':'pending',
                $row['dispatch_started_at']!==null?'delivery_unknown':'claim_timeout',
                (int)$row['id']
            ]);
            $count+=$update->rowCount();
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Record a transport failure without exposing message, address, or invitation code.
 * A worker may retry only explicit transient error categories; no sends happen here.
 */
function bcrf_roost_fail_invitation_delivery(PDO $pdo,int $id,string $claimToken,
    string $errorCode,bool $transient): bool {
    if ($id<1 || !preg_match('/^[a-f0-9]{64}$/D',$claimToken) ||
        !in_array($errorCode,['smtp_timeout','smtp_unavailable','smtp_rejected',
            'configuration_error','delivery_unknown'],true))
        throw new InvalidArgumentException('Invalid invitation delivery failure.');
    if ($pdo->inTransaction()) throw new LogicException('Failure handling requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT attempts,dispatch_started_at FROM reward_invitation_delivery_outbox
            WHERE id=? AND claim_token=? AND status='processing' FOR UPDATE");
        $q->execute([$id,$claimToken]);
        $claim=$q->fetch(PDO::FETCH_ASSOC);
        if (!$claim) { $pdo->commit(); return false; }
        // Once SMTP might have started, never automatically retry, even for timeouts.
        $retry=$claim['dispatch_started_at']===null && $transient &&
            in_array($errorCode,['smtp_timeout','smtp_unavailable'],true) &&
            (int)$claim['attempts']<3;
        $delay=5*(2**max(0,(int)$claim['attempts']-1));
        $q=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
            SET status=?,claim_token=NULL,claimed_at=NULL,last_error_code=?,
                available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ".$delay." MINUTE),
                updated_at=UTC_TIMESTAMP(3)
            WHERE id=? AND claim_token=? AND status='processing'");
        $q->execute([$retry?'pending':'failed',$errorCode,$id,$claimToken]);
        $changed=$q->rowCount()===1;
        $pdo->commit();
        return $changed;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Durable boundary before invoking external transport. Once set, a crashed
 * worker is never automatically retried because SMTP outcome may be unknown.
 */
function bcrf_roost_mark_invitation_dispatch_started(PDO $pdo,int $id,string $claimToken): bool {
    if ($id<1 || !preg_match('/^[a-f0-9]{64}$/D',$claimToken))
        throw new InvalidArgumentException('Valid delivery claim required.');
    $q=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
        SET dispatch_started_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
        WHERE id=? AND claim_token=? AND status='processing'
          AND dispatch_started_at IS NULL");
    $q->execute([$id,$claimToken]);
    return $q->rowCount()===1;
}
