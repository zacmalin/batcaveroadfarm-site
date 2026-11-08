<?php
// Pure delivery policy. Newsletter unsubscribe is marketing-only, never an
// authorization shortcut for service mail. No database writes or sends.
require_once __DIR__ . '/roost-email-recipient.php';

function bcrf_roost_service_delivery_permitted(
    ?string $email,
    bool $ownershipVerified,
    bool $serviceSuppressed,
    bool $transportSuppressed,
    string $memberRole
): bool {
    return bcrf_roost_verified_service_recipient(
        $email, $ownershipVerified, $serviceSuppressed || $transportSuppressed, $memberRole
    ) !== null;
}

function bcrf_roost_marketing_delivery_permitted(
    bool $emailOwnershipVerified,
    bool $explicitRewardsTopicOptIn,
    bool $newsletterSubscribed,
    bool $globalMarketingSuppressed,
    bool $rollingSevenDayLimitReached
): bool {
    return $emailOwnershipVerified && $explicitRewardsTopicOptIn &&
        $newsletterSubscribed && !$globalMarketingSuppressed && !$rollingSevenDayLimitReached;
}
