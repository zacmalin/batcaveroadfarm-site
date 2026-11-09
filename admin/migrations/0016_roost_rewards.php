<?php
// admin/migrations/0016_roost_rewards.php — Roost Rewards, lean version (Nov 9, 2026)
// Three tables. One member per customer, one enrolled customer per phone number.
// Families share points by using the same phone number at the register.
//   reward_members  customer, phone, cached balance, last earning activity
//   reward_ledger   every point change; ref_key makes each sale/refund/visit/redemption post once
//   reward_tiers    the four redemption tiers and what each one gets you (editable in admin)
// Rewards stay OFF until site setting rewards_enabled = 1 (Admin › Roost Rewards › Settings).
// Additive and safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_members (
        customer_id       INT NOT NULL PRIMARY KEY,
        phone_key         VARCHAR(15) NOT NULL COMMENT 'digits only, US numbers stored as 10 digits',
        balance_points    INT NOT NULL DEFAULT 0 COMMENT 'can go negative after a refund',
        last_activity_at  DATETIME NULL COMMENT 'UTC, last purchase or visit that earned points',
        enrolled_at       DATETIME NOT NULL COMMENT 'UTC',
        enrolled_by       INT NULL COMMENT 'staff users.id, NULL = customer joined online',
        welcome_sent_at   DATETIME NULL,
        UNIQUE KEY uq_phone (phone_key),
        KEY idx_activity (last_activity_at),
        CONSTRAINT fk_reward_member_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_ledger (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        customer_id  INT NOT NULL,
        entry_type   VARCHAR(12) NOT NULL COMMENT 'purchase, visit, refund, redeem, expire, adjust',
        points       INT NOT NULL COMMENT 'signed',
        sale_id      BIGINT UNSIGNED NULL,
        ref_key      VARCHAR(80) NOT NULL COMMENT 'sale:ID, refund:ID, visit:CUSTOMER:DATE, redeem:UUID, ...',
        note         VARCHAR(255) NULL,
        created_at   DATETIME NOT NULL COMMENT 'UTC',
        created_by   INT NULL,
        UNIQUE KEY uq_ref (ref_key),
        KEY idx_member (customer_id, id),
        KEY idx_sale (sale_id),
        CONSTRAINT fk_reward_ledger_member FOREIGN KEY (customer_id) REFERENCES reward_members(customer_id),
        CONSTRAINT chk_reward_type CHECK (entry_type IN ('purchase','visit','refund','redeem','expire','adjust'))
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_tiers (
        points   INT NOT NULL PRIMARY KEY,
        benefit  VARCHAR(160) NOT NULL DEFAULT '',
        active   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'a tier can be redeemed only once it has a benefit and is turned on'
    ) $cs");
    $pdo->exec("INSERT IGNORE INTO reward_tiers (points) VALUES (250), (500), (1000), (2000)");
};
