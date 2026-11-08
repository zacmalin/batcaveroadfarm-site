<?php
// Additive, dormant service-email suppression records. Newsletter consent is separate.
// No migration execution, sender, or automation is enabled here.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_email_suppressions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        scope VARCHAR(20) NOT NULL DEFAULT 'service',
        reason VARCHAR(32) NOT NULL,
        source_reference VARCHAR(160) NULL,
        created_at DATETIME(3) NOT NULL,
        cleared_at DATETIME(3) NULL,
        UNIQUE KEY uq_reward_email_suppression (email_hash,scope,reason),
        KEY idx_reward_email_suppression_active (email_hash,scope,cleared_at),
        CONSTRAINT chk_reward_email_suppression_scope CHECK (scope IN ('service','all')),
        CONSTRAINT chk_reward_email_suppression_reason CHECK (
            reason IN ('hard_bounce','complaint','admin_block','invalid_address','provider_block'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
