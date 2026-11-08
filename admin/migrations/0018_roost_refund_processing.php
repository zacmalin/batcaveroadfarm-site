<?php
// Roost Rewards refund processing receipts. Additive, no automatic processing.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_refund_processing (
        refund_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        household_id BIGINT UNSIGNED NOT NULL,
        original_earning_entry_id BIGINT UNSIGNED NOT NULL,
        points_reversed BIGINT NOT NULL,
        reversal_entry_id BIGINT UNSIGNED NULL,
        processed_at DATETIME(3) NOT NULL,
        CONSTRAINT fk_rrp_refund FOREIGN KEY (refund_id) REFERENCES refunds(id),
        CONSTRAINT fk_rrp_household FOREIGN KEY (household_id) REFERENCES reward_households(id),
        CONSTRAINT fk_rrp_earning FOREIGN KEY (original_earning_entry_id) REFERENCES reward_ledger(id),
        CONSTRAINT fk_rrp_reversal FOREIGN KEY (reversal_entry_id) REFERENCES reward_ledger(id),
        CONSTRAINT chk_rrp_points CHECK (points_reversed >= 0),
        CONSTRAINT chk_rrp_entry CHECK (
            (points_reversed = 0 AND reversal_entry_id IS NULL) OR
            (points_reversed > 0 AND reversal_entry_id IS NOT NULL)
        ),
        UNIQUE KEY uq_rrp_reversal (reversal_entry_id),
        KEY idx_rrp_earning (original_earning_entry_id, refund_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
