<?php
// Pure recipient authorization gate. Does not read customer schema or send email.
// Caller must obtain the current address and its independent verification proof
// from an approved customer-identity service, not newsletter consent.
function bcrf_roost_verified_service_recipient(
    ?string $email,
    bool $emailOwnershipVerified,
    bool $recipientSuppressed,
    string $memberRole
): ?string {
    $address = strtolower(trim((string)$email));
    if (!$emailOwnershipVerified || $recipientSuppressed ||
        !in_array($memberRole, ['manager', 'adult'], true) ||
        !filter_var($address, FILTER_VALIDATE_EMAIL) ||
        strlen($address) > 254 || preg_match('/[\r\n]/', $address)) {
        return null;
    }
    return $address;
}
