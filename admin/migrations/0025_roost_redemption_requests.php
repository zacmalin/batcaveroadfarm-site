<?php
// Dormant redemption workflow schema. No migration execution or activation.
// A request is not a points reservation, approval, or delivered benefit.
return function (PDO $pdo): void {
    $cs='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_redemption_benefits (
        code VARCHAR(64) NOT NULL PRIMARY KEY,
        tier_points SMALLINT UNSIGNED NOT NULL,
        title VARCHAR(120) NOT NULL,
        description TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        approved_by_staff_id BIGINT UNSIGNED NULL,
        approved_at DATETIME(3) NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        KEY idx_roost_benefit_active (status,tier_points),
        CONSTRAINT chk_roost_benefit_tier CHECK (tier_points IN (250,500,1000,2000)),
        CONSTRAINT chk_roost_benefit_status CHECK (status IN ('draft','active','retired')),
        CONSTRAINT chk_roost_benefit_approval CHECK
            (status <> 'active' OR (approved_by_staff_id IS NOT NULL AND approved_at IS NOT NULL))
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_redemption_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        request_token VARCHAR(64) NOT NULL,
        household_id BIGINT UNSIGNED NOT NULL,
        customer_id INT NOT NULL,
        policy_id BIGINT UNSIGNED NOT NULL,
        tier_points SMALLINT UNSIGNED NOT NULL,
        benefit_code VARCHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'requested',
        requested_at DATETIME(3) NOT NULL,
        reviewed_at DATETIME(3) NULL,
        reviewed_by_staff_id BIGINT UNSIGNED NULL,
        fulfilled_at DATETIME(3) NULL,
        fulfillment_receipt VARCHAR(64) NULL,
        ledger_entry_id BIGINT UNSIGNED NULL,
        cancelled_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL,
        UNIQUE KEY uq_roost_redemption_token (request_token),
        UNIQUE KEY uq_roost_redemption_receipt (fulfillment_receipt),
        UNIQUE KEY uq_roost_redemption_ledger (ledger_entry_id),
        KEY idx_roost_redemption_household (household_id,requested_at),
        KEY idx_roost_redemption_staff (status,requested_at),
        CONSTRAINT fk_roost_redemption_household FOREIGN KEY (household_id)
            REFERENCES reward_households(id),
        CONSTRAINT fk_roost_redemption_customer FOREIGN KEY (customer_id)
            REFERENCES customers(id),
        CONSTRAINT fk_roost_redemption_policy FOREIGN KEY (policy_id)
            REFERENCES reward_policies(id),
        CONSTRAINT chk_roost_redemption_tier CHECK (tier_points IN (250,500,1000,2000)),
        CONSTRAINT chk_roost_redemption_status CHECK
            (status IN ('requested','approved','rejected','cancelled','fulfilled','failed')),
        CONSTRAINT chk_roost_redemption_fulfillment CHECK
            (status <> 'fulfilled' OR
                (fulfilled_at IS NOT NULL AND fulfillment_receipt IS NOT NULL
                 AND ledger_entry_id IS NOT NULL))
    ) $cs");
};
