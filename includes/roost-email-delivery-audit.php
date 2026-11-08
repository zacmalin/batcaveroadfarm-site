<?php
// Dormant audit-only transition for an explicitly confirmed successful delivery.
// No mail transport, scheduler, or retry behavior is enabled here.
require_once __DIR__ . '/roost-email-claim.php';

/**
 * Only the current claim owner can record a confirmed delivery.
 * An uncertain transport result must remain processing for manual review.
 */
function bcrf_roost_email_mark_sent(PDO $pdo, int $id, string $claimToken): bool {
    if ($id < 1 || !preg_match('/^[a-f0-9]{64}$/D', $claimToken)) {
        throw new BcrfRewardError('Invalid delivery confirmation claim.');
    }
    $stmt = $pdo->prepare("UPDATE reward_email_outbox
        SET status='sent',sent_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3),
            claim_token=NULL,last_error=NULL
        WHERE id=? AND status='processing' AND claim_token=? AND sent_at IS NULL");
    $stmt->execute([$id,$claimToken]);
    return $stmt->rowCount() === 1;
}
