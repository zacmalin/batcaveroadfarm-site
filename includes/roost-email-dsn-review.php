<?php
// Dormant, pure classifier for human-reviewed delivery-status notifications.
// Never parses mailbox content, authenticates senders, or changes suppression.
// A 5.x.x code indicates permanent SMTP failure, NOT necessarily an invalid
// recipient: only explicit mailbox-unknown codes may be considered for a
// hard-bounce review. All results require operator confirmation.
function bcrf_roost_dsn_review_category(string $enhancedStatus): string {
    $code = trim($enhancedStatus);
    if (!preg_match('/^[245]\\.[0-9]{1,3}\\.[0-9]{1,3}$/D', $code)) return 'unrecognized';
    if ($code === '5.1.1' || $code === '5.1.10') return 'mailbox_unknown_review';
    if ($code[0] === '4') return 'transient_review';
    if ($code[0] === '5') return 'permanent_failure_review';
    return 'success';
}
