<?php
// Dormant reconciliation helpers for uncertain delivery outcomes.
// Never automatically retries a processing event or assumes mail was delivered.
require_once __DIR__ . '/roost-email-claim.php';

/**
 * Record a definitive transport failure without requeueing. The claim token
 * prevents a stale worker from altering a newer state. Operator review is
 * required before any later retry decision.
 */
function bcrf_roost_email_mark_failed(PDO $pdo, int $id, string $claimToken, string $reason): bool {
    if ($id < 1 || !preg_match('/^[a-f0-9]{64}$/D', $claimToken) || trim($reason) === '') {
        throw new BcrfRewardError('Invalid delivery failure claim.');
    }
    $stmt = $pdo->prepare("UPDATE reward_email_outbox
        SET status='failed',last_error=?,updated_at=UTC_TIMESTAMP(3),claim_token=NULL
        WHERE id=? AND status='processing' AND claim_token=? AND sent_at IS NULL");
    $stmt->execute([substr(trim($reason),0,500),$id,$claimToken]);
    return $stmt->rowCount() === 1;
}

/** Read-only review queue; no automatic reclaim or dispatch. */
function bcrf_roost_email_review_queue(PDO $pdo, int $limit = 50): array {
    if ($limit < 1 || $limit > 100) throw new BcrfRewardError('Invalid review limit.');
    $stmt = $pdo->query("SELECT id,event_type,status,attempts,claimed_at,updated_at,
        sent_at,suppressed_at,last_error
        FROM reward_email_outbox
        WHERE status IN ('processing','failed')
        ORDER BY updated_at,id LIMIT ".(int)$limit);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
