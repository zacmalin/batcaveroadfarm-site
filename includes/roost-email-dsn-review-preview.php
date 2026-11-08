<?php
// Dormant staff preview component: caller is responsible for authentication
// and authorization. No route, mailbox connection, actions or writes exist.
require_once __DIR__ . '/roost-email-dsn-review-summary.php';

/** Escape all untrusted values in the read-only review preview. */
function bcrf_roost_dsn_review_preview_html(array $summary): string {
    $required = ['outbound_reference','status_code','category','review_state','provenance_verified','recipient_correlated','automatic_suppression_allowed'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $summary)) {
            throw new InvalidArgumentException('Incomplete delivery review summary.');
        }
    }
    if ($summary['automatic_suppression_allowed'] !== false) {
        throw new InvalidArgumentException('Automatic suppression is forbidden.');
    }
    $escape = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows = [
        'Outbound reference' => $summary['outbound_reference'],
        'Delivery status' => $summary['status_code'],
        'Review category' => $summary['category'],
        'Review state' => $summary['review_state'],
        'Provenance verified' => $summary['provenance_verified'] ? 'Yes' : 'No',
        'Recipient correlated' => $summary['recipient_correlated'] ? 'Yes' : 'No',
    ];
    $html = '<section aria-label="Farm Rewards delivery review"><h2>Delivery failure review</h2><dl>';
    foreach ($rows as $label => $value) {
        $html .= '<dt>'.$escape($label).'</dt><dd>'.$escape($value).'</dd>';
    }
    return $html.'</dl><p>Review only. No customer suppression or delivery action is available.</p></section>';
}
