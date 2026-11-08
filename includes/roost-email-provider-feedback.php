<?php
// Dormant provider-neutral feedback classifier. No webhook, authentication,
// database write, scheduler or sending is implemented here.
// A future provider adapter MUST authenticate and validate event provenance
// before calling this function. Never trust public payload fields directly.

/**
 * Classify a verified, canonical provider delivery event for suppression.
 * Unknown or transient events are not permanent suppression instructions.
 * Returns a supported suppression reason, or null for non-blocking events.
 */
function bcrf_roost_verified_provider_feedback_reason(string $eventType): ?string {
    return match ($eventType) {
        'hard_bounce' => 'hard_bounce',
        'spam_complaint' => 'complaint',
        'provider_block' => 'provider_block',
        default => null,
    };
}
