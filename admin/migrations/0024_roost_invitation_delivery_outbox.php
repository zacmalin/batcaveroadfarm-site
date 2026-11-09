<?php
// Dormant additive schema for encrypted household invitation delivery.
// No migration execution, sender, scheduler or feature activation here.
return function (PDO $pdo): void {
    $cs='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_invitation_delivery_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invitation_id BIGINT UNSIGNED NOT NULL,
        payload_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
        encryption_key_id VARCHAR(32) NOT NULL,
        payload_nonce VARCHAR(24) NOT NULL,
        payload_ciphertext TEXT NOT NULL,
        payload_tag VARCHAR(24) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME(3) NOT NULL,
        claimed_at DATETIME(3) NULL,
        claim_token CHAR(64) NULL,
        dispatch_started_at DATETIME(3) NULL,
        sent_at DATETIME(3) NULL,
        suppressed_at DATETIME(3) NULL,
        last_error_code VARCHAR(64) NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        UNIQUE KEY uq_reward_invite_delivery_invitation (invitation_id),
        KEY idx_reward_invite_delivery_pending (status,available_at,id),
        CONSTRAINT fk_reward_invite_delivery_invitation FOREIGN KEY (invitation_id)
            REFERENCES reward_household_invitations(id),
        CONSTRAINT chk_reward_invite_delivery_version CHECK (payload_version=1),
        CONSTRAINT chk_reward_invite_delivery_status CHECK
            (status IN ('pending','processing','sent','suppressed','failed'))
    ) $cs");
};
