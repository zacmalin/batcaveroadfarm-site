<?php
// Dormant composition point for a future authenticated Farm OS route.
// This file must not be used as a standalone HTTP endpoint. The caller must
// first complete check_admin(), re-read account status and enforce view access.
// No route or menu entry is registered by this library.
require_once __DIR__ . '/roost-email-dsn-review-access.php';
require_once __DIR__ . '/roost-email-dsn-review-preview.php';

/** Render only for an active administrator, with a previously validated summary. */
function bcrf_roost_delivery_review_admin_preview(
    string $role, bool $accountActive, array $summary
): string {
    if (!bcrf_roost_delivery_review_access($role, $accountActive)) {
        throw new RuntimeException('Delivery review access denied.');
    }
    return bcrf_roost_dsn_review_preview_html($summary);
}
