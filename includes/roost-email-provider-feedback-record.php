<?php
// Dormant adapter for already-authenticated provider feedback.
// No public endpoint, webhook authentication, worker or scheduler is installed.
// Callers must verify the provider signature and event provenance BEFORE calling.
require_once __DIR__ . '/roost-email-provider-feedback.php';
require_once __DIR__ . '/roost-email-suppression-admin.php';

/**
 * Persist only supported permanent suppression events after external verification.
 * The adapter intentionally accepts canonical event types, not raw provider JSON.
 * A stable provider reference is required for operator investigation.
 * Returns false for transient/unknown events without modifying the database.
 */
function bcrf_roost_record_verified_provider_feedback(
    PDO $pdo, string $email, string $canonicalEventType, string $providerReference
): bool {
    $reason = bcrf_roost_verified_provider_feedback_reason($canonicalEventType);
    if ($reason === null) return false;
    $reference = trim($providerReference);
    if ($reference === '' || strlen($reference) > 160) {
        throw new BcrfRewardError('Verified provider feedback requires a valid reference.');
    }
    bcrf_roost_email_record_suppression($pdo, $email, 'service', $reason, $reference);
    return true;
}
