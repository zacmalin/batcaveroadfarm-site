<?php
// Additive audit evidence. No public form may self-assert verified adulthood.
// Store only verification method/reference, not identity documents.
return function (PDO $pdo): void {
    $cs='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_adult_verifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        status VARCHAR(20) NOT NULL COMMENT 'verified or revoked',
        method VARCHAR(40) NOT NULL COMMENT 'approved staff verification method',
        evidence_reference VARCHAR(120) NOT NULL COMMENT 'opaque reference; no document data',
        verified_by_staff_id INT NOT NULL,
        verified_at DATETIME(3) NOT NULL COMMENT 'UTC',
        revoked_at DATETIME(3) NULL,
        created_at DATETIME(3) NOT NULL COMMENT 'UTC',
        KEY idx_roost_adult_verification (customer_id,status,revoked_at),
        CONSTRAINT fk_roost_adult_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_consent_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        purpose VARCHAR(40) NOT NULL COMMENT 'rewards_enrollment or household_join',
        policy_version VARCHAR(40) NOT NULL,
        status VARCHAR(20) NOT NULL COMMENT 'granted or withdrawn',
        source VARCHAR(40) NOT NULL COMMENT 'approved authenticated consent channel',
        created_at DATETIME(3) NOT NULL COMMENT 'UTC',
        KEY idx_roost_consent (customer_id,purpose,created_at,id),
        CONSTRAINT fk_roost_consent_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
    ) $cs");
};
