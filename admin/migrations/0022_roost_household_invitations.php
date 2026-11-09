<?php
// Additive schema only. Invitations remain dormant until verified-adult
// acceptance, email delivery, rate limiting and customer-facing UX are tested.
// Store only a SHA-256 token digest, never the invitation bearer token.
return function (PDO $pdo): void {
    $cs='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_household_invitations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        household_id BIGINT UNSIGNED NOT NULL,
        invited_by_customer_id INT NOT NULL,
        invitee_email_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of normalized email; no raw email',
        token_hash CHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        expires_at DATETIME(3) NOT NULL COMMENT 'UTC',
        accepted_by_customer_id INT NULL,
        accepted_at DATETIME(3) NULL,
        revoked_at DATETIME(3) NULL,
        created_at DATETIME(3) NOT NULL COMMENT 'UTC',
        UNIQUE KEY uq_roost_invitation_token (token_hash),
        KEY idx_roost_invitation_household (household_id,status),
        KEY idx_roost_invitation_recipient (invitee_email_hash,status),
        CONSTRAINT fk_roost_invitation_household FOREIGN KEY (household_id) REFERENCES reward_households(id),
        CONSTRAINT fk_roost_invitation_sender FOREIGN KEY (invited_by_customer_id) REFERENCES customers(id),
        CONSTRAINT fk_roost_invitation_acceptor FOREIGN KEY (accepted_by_customer_id) REFERENCES customers(id)
    ) $cs");
};
