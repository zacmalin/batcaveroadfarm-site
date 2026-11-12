<?php
// admin/migrations/0041_animal_care.php — Farm OS Phase 5 slice 2: care and feeding (Nov 12, 2026; 08 › Care and feeding;
// includes/animal_care.php).
//   animal_care_types         what can be logged (Feeding, Water, Hoof trim…). One or more can be feeding types, which take
//                             a feed product and amount. "Quick" types get a big button on the phone. Starter list added.
//   animal_care_logs          one entry: the type, when, who, a note, the enclosure if it was logged for a whole pen, and for
//                             feeding the product, amount, its cost at the time (cents) and the stock ledger row it wrote.
//                             Entries are never deleted; staff can void one with a reason (feed goes back into stock).
//   animal_care_log_animals   which animals one entry covered.
//   site_settings             animal_feed_products = comma-separated inventory ids offered as feed (empty to start).
// Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_care_types (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(60) NOT NULL,
        is_feeding  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = asks for a feed product and amount',
        quick       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = big button on the phone',
        active      TINYINT(1) NOT NULL DEFAULT 1,
        sort_order  SMALLINT NOT NULL DEFAULT 0,
        seed_key    VARCHAR(30) NULL,
        UNIQUE KEY uq_name (name),
        UNIQUE KEY uq_seed (seed_key)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_care_logs (
        id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        care_type_id        INT UNSIGNED NOT NULL,
        enclosure_id        INT UNSIGNED NULL COMMENT 'set when logged for everyone in an enclosure',
        logged_at           DATETIME NOT NULL COMMENT 'UTC',
        logged_by           INT NULL,
        note                VARCHAR(500) NULL,
        feed_product_id     INT NULL COMMENT 'inventory.id',
        feed_qty            DECIMAL(12,3) NULL COMMENT 'in the product''s own unit',
        feed_cost_cents     BIGINT NULL COMMENT 'amount x the product''s average cost at the time',
        stock_adjustment_id BIGINT NULL COMMENT 'inventory_adjustments row that took the feed out of stock',
        voided_at           DATETIME NULL COMMENT 'UTC',
        voided_by           INT NULL,
        void_reason         VARCHAR(255) NULL,
        KEY idx_logged (logged_at),
        KEY idx_type (care_type_id, logged_at),
        KEY idx_feed (feed_product_id, logged_at)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS animal_care_log_animals (
        log_id    INT UNSIGNED NOT NULL,
        animal_id INT NOT NULL,
        PRIMARY KEY (log_id, animal_id),
        KEY idx_animal (animal_id)
    ) $cs");
    $seed = $pdo->prepare("INSERT IGNORE INTO animal_care_types (name, is_feeding, quick, sort_order, seed_key) VALUES (?, ?, ?, ?, ?)");
    foreach ([['Feeding', 1, 1, 10, 'feeding'], ['Water', 0, 1, 20, 'water'], ['Pen cleaned', 0, 1, 30, 'cleaning'],
              ['Observation', 0, 1, 40, 'observation'], ['Grooming', 0, 0, 50, 'grooming'], ['Hoof trim', 0, 0, 60, 'hoof_trim'],
              ['Enrichment', 0, 0, 70, 'enrichment']] as $r) $seed->execute($r);
    $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('animal_feed_products', '')");
};
