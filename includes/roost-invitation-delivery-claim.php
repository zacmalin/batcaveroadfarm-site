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
