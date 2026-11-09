<?php
// Read-only Farm Rewards delivery review placeholder. No mailbox or customer
// data is loaded. Routed only through admin/admin.php after check_admin()
// and the permissions gate. No direct execution.
if (!defined('BCRF_REWARDS_DELIVERY_REVIEW_INCLUDED')) {
    define('BCRF_REWARDS_DELIVERY_REVIEW_INCLUDED', true);
}
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== 'admin.php') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../../includes/roost-email-dsn-review-access.php';
if (!bcrf_roost_delivery_review_access((string)($current_role ?? ''), true)) {
    http_response_code(403);
    echo '<p>Delivery review access denied.</p>';
    return;
}
echo '<section class="p-6"><h1 class="text-xl font-semibold">Farm Rewards delivery review</h1>';
echo '<p class="mt-3">Preview only. Mailbox integration and delivery-failure records are not connected.</p>';
echo '<p class="mt-2">Staff must independently verify the original recipient, delivery report provenance, and outbound reference before any manual suppression decision.</p>';
echo '<p class="mt-2">No automated suppression or email sending is available on this screen.</p>';
// Synthetic fixture only: no real customer address, mailbox access or database reads.
require_once __DIR__ . '/../../includes/roost-email-manual-review-record.php';
require_once __DIR__ . '/../../includes/roost-email-dsn-review-admin-preview.php';
$fixture = bcrf_roost_manual_delivery_review_record([
    'enhanced_status' => '5.1.1',
    'outbound_reference' => 'DEMO-ONLY-001',
    'provenance_verified' => false,
    'recipient_correlated' => false,
]);
echo '<div class="mt-5"><h2 class="font-semibold">Synthetic example (not a real delivery report)</h2>';
echo bcrf_roost_delivery_review_admin_preview((string)$current_role, true, $fixture);
echo '</div></section>';
