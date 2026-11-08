<?php
// Additive Roost Rewards service-email outbox schema only.
// No sending, scheduler, enrollment or migration execution from this file.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_email_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        household_id BIGINT UNSIGNED NOT NULL,
        member_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(32) NOT NULL COMMENT 'welcome or expiration_reminder',
        milestone_days TINYINT UNSIGNED NULL COMMENT '60, 30, 7 for expiration',
        activity_at DATETIME(3) NULL COMMENT 'UTC snapshot; recheck before send',
        event_key VARCHAR(160) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, processing, sent, suppressed, failed',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME(3) NOT NULL COMMENT 'UTC',
        claimed_at DATETIME(3) NULL COMMENT 'UTC',
        claim_token CHAR(64) NULL,
        last_error VARCHAR(500) NULL,
        sent_at DATETIME(3) NULL COMMENT 'UTC',
        suppressed_at DATETIME(3) NULL COMMENT 'UTC',
        created_at DATETIME(3) NOT NULL COMMENT 'UTC',
        updated_at DATETIME(3) NOT NULL COMMENT 'UTC',
        UNIQUE KEY uq_reward_email_event (event_key),
        KEY idx_reward_email_pending (status, available_at, id),
        KEY idx_reward_email_household (household_id, created_at),
        KEY idx_reward_email_member (member_id, created_at),
        CONSTRAINT fk_reward_email_household FOREIGN KEY (household_id) REFERENCES reward_households(id),
        CONSTRAINT fk_reward_email_member FOREIGN KEY (member_id) REFERENCES reward_household_members(id),
        CONSTRAINT chk_reward_email_type CHECK (
            (event_type='welcome' AND milestone_days IS NULL)
            OR (event_type='expiration_reminder' AND milestone_days IN (60,30,7))
        )
    ) $cs");
};
