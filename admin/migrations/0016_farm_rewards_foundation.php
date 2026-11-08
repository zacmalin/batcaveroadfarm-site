<?php
// Farm Rewards foundation — schema only. No awards, redemptions, or live enrollment.
// Migration is additive and safe to rerun; launch remains disabled until service-level
// idempotency, locking, consent, and policy tests have passed.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_households (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        last_qualifying_activity_at DATETIME(3) NULL COMMENT 'UTC, purchase or verified visit only',
        created_at DATETIME(3) NOT NULL COMMENT 'UTC',
        updated_at DATETIME(3) NULL COMMENT 'UTC',
        KEY idx_activity (last_qualifying_activity_at)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_household_members (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        household_id BIGINT UNSIGNED NOT NULL,
        customer_id INT NOT NULL,
        role VARCHAR(20) NOT NULL COMMENT 'manager, adult, dependent',
        verification_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        can_redeem TINYINT(1) NOT NULL DEFAULT 0,
        joined_at DATETIME(3) NOT NULL COMMENT 'UTC',
        revoked_at DATETIME(3) NULL COMMENT 'UTC',
        active_customer_id INT GENERATED ALWAYS AS (IF(revoked_at IS NULL, customer_id, NULL)) STORED,
        UNIQUE KEY uq_household_customer (household_id, customer_id),
        UNIQUE KEY uq_one_active_household (active_customer_id),
        KEY idx_household_active (household_id, revoked_at),
        CONSTRAINT fk_reward_member_household FOREIGN KEY (household_id) REFERENCES reward_households(id),
        CONSTRAINT fk_reward_member_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_policies (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(40) NOT NULL,
        config_json JSON NOT NULL COMMENT 'approved earning, exclusions, redemption tiers and inactivity rules',
        status VARCHAR(20) NOT NULL DEFAULT 'draft',
        effective_at DATETIME(3) NULL COMMENT 'UTC',
        created_at DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by INT NULL,
        UNIQUE KEY uq_reward_policy_version (version)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_accounts (
        household_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        balance_points BIGINT NOT NULL DEFAULT 0 COMMENT 'negative permitted after refund reversal',
        lifetime_earned BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lifetime_redeemed BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME(3) NOT NULL COMMENT 'UTC',
        CONSTRAINT fk_reward_account_household FOREIGN KEY (household_id) REFERENCES reward_households(id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_ledger (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        household_id BIGINT UNSIGNED NOT NULL,
        customer_id INT NULL COMMENT 'earning individual or acting redeemer',
        policy_id BIGINT UNSIGNED NOT NULL,
        entry_type VARCHAR(24) NOT NULL COMMENT 'earn_spend, earn_visit, redeem, refund_reversal, expire, adjustment',
        points_delta BIGINT NOT NULL COMMENT 'signed; immutable once posted',
        source_type VARCHAR(40) NOT NULL,
        source_id VARCHAR(100) NOT NULL,
        idempotency_key VARCHAR(160) NOT NULL,
        related_entry_id BIGINT UNSIGNED NULL COMMENT 'refund reversal or correction source',
        metadata_json JSON NULL,
        created_at DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by INT NULL,
        earning_source_type VARCHAR(40) GENERATED ALWAYS AS (IF(entry_type IN ('earn_spend','earn_visit'), source_type, NULL)) STORED,
        earning_source_id VARCHAR(100) GENERATED ALWAYS AS (IF(entry_type IN ('earn_spend','earn_visit'), source_id, NULL)) STORED,
        UNIQUE KEY uq_reward_idempotency (idempotency_key),
        UNIQUE KEY uq_reward_earning_source (earning_source_type, earning_source_id),
        KEY idx_reward_household_history (household_id, created_at, id),
        KEY idx_reward_source (source_type, source_id),
        KEY idx_reward_related (related_entry_id),
        CONSTRAINT fk_reward_ledger_household FOREIGN KEY (household_id) REFERENCES reward_households(id),
        CONSTRAINT fk_reward_ledger_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
        CONSTRAINT fk_reward_ledger_policy FOREIGN KEY (policy_id) REFERENCES reward_policies(id),
        CONSTRAINT fk_reward_ledger_related FOREIGN KEY (related_entry_id) REFERENCES reward_ledger(id)
    ) $cs");
};
