<?php
// Dormant, explicit suppression-record administration. Never called by an
// endpoint, scheduler or mail transport. All changes require an approved caller.
require_once __DIR__ . '/roost-email-suppressions.php';

function bcrf_roost_email_record_suppression(
    PDO $pdo, string $email, string $scope, string $reason, ?string $reference = null
): void {
    if (!in_array($scope, ['service','all'], true) ||
        !in_array($reason, ['hard_bounce','complaint','admin_block','invalid_address','provider_block'], true) ||
        ($reference !== null && strlen($reference) > 160)) {
        throw new BcrfRewardError('Invalid service-email suppression.');
    }
    $stmt = $pdo->prepare("INSERT INTO reward_email_suppressions
        (email_hash,scope,reason,source_reference,created_at,cleared_at)
        VALUES (?,?,?,?,UTC_TIMESTAMP(3),NULL)
        ON DUPLICATE KEY UPDATE cleared_at=NULL,
            source_reference=VALUES(source_reference),created_at=UTC_TIMESTAMP(3)");
    $stmt->execute([bcrf_roost_email_address_hash($email),$scope,$reason,$reference]);
}

/** Explicit clearance of one reason; other active blocks remain effective. */
function bcrf_roost_email_clear_suppression(
    PDO $pdo, string $email, string $scope, string $reason
): bool {
    if (!in_array($scope, ['service','all'], true) ||
        !in_array($reason, ['hard_bounce','complaint','admin_block','invalid_address','provider_block'], true)) {
        throw new BcrfRewardError('Invalid suppression clearance.');
    }
    $stmt = $pdo->prepare("UPDATE reward_email_suppressions
        SET cleared_at=UTC_TIMESTAMP(3)
        WHERE email_hash=? AND scope=? AND reason=? AND cleared_at IS NULL");
    $stmt->execute([bcrf_roost_email_address_hash($email),$scope,$reason]);
    return $stmt->rowCount() === 1;
}
