<?php
// Dormant Roost Rewards service-email claim and suppression state machine.
// Does not send email; no scheduler or web endpoint invokes this code.
require_once __DIR__ . '/roost-email-outbox.php';

/**
 * Claim one pending email using an atomic row lock. Caller must invoke
 * revalidation and a separately approved delivery transport before any send.
 * No automatic reclaim of processing rows: operators must reconcile uncertain
 * deliveries manually to avoid duplicate service emails.
 */
function bcrf_roost_email_claim(PDO $pdo): ?array {
    if ($pdo->inTransaction()) throw new BcrfRewardError('Email claim requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT id,household_id,member_id,event_type,milestone_days,
            activity_at,event_key FROM reward_email_outbox
            WHERE status='pending' AND available_at<=UTC_TIMESTAMP(3)
            ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->commit();
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $update = $pdo->prepare("UPDATE reward_email_outbox
            SET status='processing',attempts=attempts+1,claim_token=?,
                claimed_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
            WHERE id=? AND status='pending'");
        $update->execute([$token,(int)$row['id']]);
        if ($update->rowCount() !== 1) throw new BcrfRewardError('Email claim race.');
        $pdo->commit();
        $row['claim_token'] = $token;
        return $row;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Mark an unshipped, claimed event suppressed with a bounded audit reason. */
function bcrf_roost_email_suppress(PDO $pdo, int $id, string $claimToken, string $reason): bool {
    if ($id < 1 || !preg_match('/^[a-f0-9]{64}$/D', $claimToken) ||
        trim($reason) === '') throw new BcrfRewardError('Invalid suppression claim.');
    $stmt = $pdo->prepare("UPDATE reward_email_outbox SET status='suppressed',
        suppressed_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3),
        claim_token=NULL,last_error=?
        WHERE id=? AND status='processing' AND claim_token=?");
    $stmt->execute([substr($reason,0,500),$id,$claimToken]);
    return $stmt->rowCount() === 1;
}
