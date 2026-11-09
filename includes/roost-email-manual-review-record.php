<?php
// Dormant read-only manual-review record normalization. No mailbox ingestion,
// database writes, or automatic customer suppression. The caller must verify
// provenance and recipient correlation independently before passing flags.
require_once __DIR__ . '/roost-email-dsn-review-summary.php';

/** Normalize operator-entered delivery reports for display; no trust inferred. */
function bcrf_roost_manual_delivery_review_record(array $record): array {
    foreach (['enhanced_status','outbound_reference','provenance_verified','recipient_correlated'] as $key) {
        if (!array_key_exists($key, $record)) {
            throw new InvalidArgumentException('Incomplete manual delivery review record.');
        }
    }
    if (!is_string($record['enhanced_status']) || !is_string($record['outbound_reference']) ||
        !is_bool($record['provenance_verified']) || !is_bool($record['recipient_correlated'])) {
        throw new InvalidArgumentException('Invalid manual delivery review record types.');
    }
    if (strlen($record['enhanced_status']) > 32) {
        throw new InvalidArgumentException('Delivery status exceeds review limit.');
    }
    return bcrf_roost_dsn_review_summary(
        $record['enhanced_status'],
        $record['outbound_reference'],
        $record['recipient_correlated'],
        $record['provenance_verified']
    );
}
