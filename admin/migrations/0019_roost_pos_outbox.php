<?php
// Additive, disabled-by-default durable outbox for POS lifecycle events.
// No worker is activated by this migration.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_pos_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(40) NOT NULL,
        source_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        processed_at DATETIME(3) NULL,
        last_error VARCHAR(500) NULL,
        UNIQUE KEY uq_reward_pos_event (event_type, source_id),
        KEY idx_reward_pos_pending (status, available_at, id),
        CONSTRAINT chk_reward_pos_event CHECK (event_type IN ('sale.completed','sale.refunded')),
        CONSTRAINT chk_reward_pos_status CHECK (status IN ('pending','processing','completed','skipped','failed'))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
