<?php
// Roost Rewards service email event identity and eligibility helpers.
// Pure functions only. No database mutations, queue execution or email sends.
require_once __DIR__ . '/roost-expiration-preview.php';

/**
 * Stable identity for one verified member's enrollment welcome.
 * The enrollment identifier must come from a committed verified membership.
 */
function bcrf_roost_welcome_event_key(int $memberId, string $verifiedAtUtc): string {
    if ($memberId < 1 || trim($verifiedAtUtc) === '') {
        throw new BcrfRewardError('Verified membership required.');
    }
    return 'roost:welcome:v1:'.$memberId.':'.hash('sha256', $verifiedAtUtc);
}

/**
 * Stable identity for a specific household, recipient, activity epoch and
 * expiration milestone. Never use this key to bypass recipient revalidation.
 */
function bcrf_roost_expiration_event_key(
    int $householdId,
    int $memberId,
    string $activityUtc,
    int $days
): string {
    if ($householdId < 1 || $memberId < 1 || trim($activityUtc) === '' ||
        !in_array($days, [60, 30, 7], true)) {
        throw new BcrfRewardError('Invalid expiration event identity.');
    }
    // Hash avoids storing potentially sensitive activity timestamps in keys.
    return 'roost:expire-mail:v1:'.$householdId.':'.$memberId.':'.$days.':'
        .hash('sha256', $activityUtc);
}

/**
 * Read-only, fail-closed reminder gate; caller must separately verify email,
 * household membership/role and suppression immediately before sending.
 */
function bcrf_roost_reminder_eligible(
    string $householdStatus,
    int $balance,
    ?string $lastActivityUtc,
    string $nowUtc,
    int $days
): bool {
    if ($householdStatus !== 'active' || $balance <= 0 ||
        $lastActivityUtc === null || trim($lastActivityUtc) === '' ||
        !in_array($days, [60, 30, 7], true)) return false;
    return bcrf_roost_expiration_reminder_day($lastActivityUtc, $nowUtc) === $days;
}
