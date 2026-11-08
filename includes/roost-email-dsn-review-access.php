<?php
// Dormant authorization policy for a future Farm OS delivery-review view.
// No route, navigation entry, mailbox read, or write capability is installed.
// Until a dedicated permission is approved and registered, only active
// administrators may access sensitive delivery review data.
function bcrf_roost_delivery_review_access(string $role, bool $accountActive): bool {
    return $accountActive && $role === 'administrator';
}
