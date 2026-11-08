<?php
// Read-only service suppression gate. No newsletter opt-out lookup or sender.
// Store normalized address hashes instead of duplicating customer email PII.
function bcrf_roost_email_address_hash(string $email): string {
    $normalized = strtolower(trim($email));
    if (!filter_var($normalized, FILTER_VALIDATE_EMAIL) || strlen($normalized) > 254) {
        throw new BcrfRewardError('Invalid service-email address.');
    }
    return hash('sha256', $normalized);
}

function bcrf_roost_email_service_suppressed(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM reward_email_suppressions
        WHERE email_hash=? AND scope IN ('service','all') AND cleared_at IS NULL LIMIT 1");
    $stmt->execute([bcrf_roost_email_address_hash($email)]);
    return $stmt->fetchColumn() !== false;
}
