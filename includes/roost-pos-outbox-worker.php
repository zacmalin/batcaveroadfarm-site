<?php
// Internal Roost Rewards POS outbox claim/retry state machine.
// No scheduler, public route, or ledger posting is enabled here.
const BCRF_ROOST_OUTBOX_MAX_ATTEMPTS = 8;
function bcrf_roost_outbox_claim(PDO $pdo): ?array {
    if ($pdo->inTransaction()) throw new LogicException('Claim requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->query("SELECT id, event_type, source_id, attempts
            FROM reward_pos_outbox
            WHERE status IN ('pending','failed','processing') AND attempts < 8 AND available_at <= UTC_TIMESTAMP(3)
            ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED");
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->commit(); return null; }
        $upd=$pdo->prepare("UPDATE reward_pos_outbox SET status='processing', attempts=attempts+1,
            available_at=DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 10 MINUTE),
            last_error=NULL WHERE id=?");
        $upd->execute([(int)$row['id']]);
        $pdo->commit();
        $row['attempts']=(int)$row['attempts']+1;
        return $row;
    } catch(Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Mark a claimed event after an independent, verified handler completes.
 * Only the current claim's attempt number may finish it.
 * A stale worker cannot overwrite a newer claim.
 */
function bcrf_roost_outbox_finish(PDO $pdo, int $id, int $attempt, string $outcome, ?string $error=null): bool {
    if ($pdo->inTransaction()) throw new LogicException('Finish requires autocommit.');
    if ($id<=0 || $attempt<=0 || !in_array($outcome,['completed','skipped','failed'],true))
        throw new InvalidArgumentException('Invalid outbox completion.');
    if ($outcome==='failed' && ($error===null || trim($error)===''))
        throw new InvalidArgumentException('Failure reason required.');
    $terminal = $outcome === 'failed' && $attempt >= BCRF_ROOST_OUTBOX_MAX_ATTEMPTS;
    $nextStatus = $terminal ? 'dead' : $outcome;
    $delay=min(3600,30*(2**min(7,$attempt-1)));
    $sql="UPDATE reward_pos_outbox
        SET status=?, last_error=?, processed_at=CASE WHEN ?='failed' THEN NULL ELSE UTC_TIMESTAMP(3) END,
            available_at=CASE WHEN ?='failed' THEN DATE_ADD(UTC_TIMESTAMP(3), INTERVAL $delay SECOND) ELSE available_at END
        WHERE id=? AND status='processing' AND attempts=?";
    $st=$pdo->prepare($sql);
    $st->execute([$nextStatus,$error===null?null:mb_substr($error,0,500),$outcome,$outcome,$id,$attempt]);
    return $st->rowCount()===1;
}
