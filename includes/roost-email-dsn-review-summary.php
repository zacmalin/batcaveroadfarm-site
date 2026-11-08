<?php
// Dormant, read-only view-model for a future staff-reviewed bounce queue.
// Caller must authenticate staff and independently verify message provenance,
// original recipient, and outbound-event correlation. No mailbox access,
// persistence, suppression, or admin route is provided.
require_once __DIR__ . '/roost-email-dsn-review.php';

/**
 * Summarize a verified, manually supplied delivery-status record for display.
 * Never interprets the supplied evidence as authorization to suppress.
 * Returns only whitelisted fields; no raw message body or email credentials.
 */
function bcrf_roost_dsn_review_summary(
    string $enhancedStatus,
    string $outboundReference,
    bool $recipientCorrelated,
    bool $provenanceVerified
): array {
    $reference = trim($outboundReference);
    if ($reference === '' || strlen($reference) > 160 || preg_match('/[\\x00-\\x1f\\x7f]/', $reference)) {
        throw new InvalidArgumentException('Invalid outbound reference.');
    }
    $category = bcrf_roost_dsn_review_category($enhancedStatus);
    $verified = $recipientCorrelated && $provenanceVerified;
    return [
        'outbound_reference' => $reference,
        'status_code' => trim($enhancedStatus),
        'category' => $category,
        'provenance_verified' => $provenanceVerified,
        'recipient_correlated' => $recipientCorrelated,
        'review_state' => !$verified ? 'evidence_incomplete'
            : ($category === 'transient_review' ? 'investigate_transient'
            : ($category === 'mailbox_unknown_review' ? 'manual_hard_bounce_review'
            : ($category === 'permanent_failure_review' ? 'investigate_permanent'
            : 'no_suppression_recommended'))),
        'automatic_suppression_allowed' => false,
    ];
}
