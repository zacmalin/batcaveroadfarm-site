<?php
// includes/roles.php — display names for staff roles (Farm OS Phase 0 cleanup, C-14, Nov 13, 2026).
// The stored role keys stay as they are (administrator, moderator, Shopkeep, Media, Staff) so permissions,
// sessions and protected files keep working; screens show the names from 03 instead.
if (!function_exists('bcrf_role_label')) {

/** role key => display name, in the order screens should list them. */
function bcrf_role_labels(): array {
    return [
        'administrator' => 'Owner',
        'moderator'     => 'Manager',
        'Shopkeep'      => 'Shopkeep',
        'Media'         => 'Content',
        'Staff'         => 'Staff',
    ];
}

/** Display name for a role key; unknown keys come back unchanged. */
function bcrf_role_label(?string $role): string {
    $role = (string)$role;
    return bcrf_role_labels()[$role] ?? $role;
}

}
