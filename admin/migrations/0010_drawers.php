<?php
// admin/migrations/0010_drawers.php — Farm OS Phase 1, slice 2: cash drawers (Nov 6, 2026)
//   registers        one row per till (05 › Cash drawer). Seeded with one register, "Farm Stand".
//   drawer_sessions  open → closed. A generated column lets the database itself refuse a second open
//                    session on the same register ("only one open drawer session per register").
//   cash_movements   append-only: paid_in, paid_out, drop (amount always positive; type gives the sign).
// Sales already have register_id and drawer_session_id (0009); from now on every sale is linked.
// Safe to repeat.
return function (PDO $pdo): void {
    $cs = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

    $pdo->exec("CREATE TABLE IF NOT EXISTS registers (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(80) NOT NULL,
        location_id BIGINT UNSIGNED NULL,
        device_id   BIGINT UNSIGNED NULL,
        sort_order  INT NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
        created_by  INT NULL,
        updated_at  DATETIME NULL,
        updated_by  INT NULL,
        archived_at DATETIME NULL,
        UNIQUE KEY uq_name (name)
    ) $cs");
    $pdo->exec("INSERT IGNORE INTO registers (name, sort_order, created_at) VALUES ('Farm Stand', 1, UTC_TIMESTAMP())");

    $pdo->exec("CREATE TABLE IF NOT EXISTS drawer_sessions (
        id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        register_id            BIGINT UNSIGNED NOT NULL,
        status                 VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open, closed',
        business_date          DATE NOT NULL COMMENT 'farm-local date it was opened',
        opened_at              DATETIME(3) NOT NULL COMMENT 'UTC',
        opened_by              INT NULL,
        opening_float_cents    BIGINT NOT NULL DEFAULT 0,
        opening_count_json     JSON NULL COMMENT 'denomination (cents) => quantity',
        closed_at              DATETIME(3) NULL,
        closed_by              INT NULL,
        counted_cash_cents     BIGINT NULL,
        closing_count_json     JSON NULL,
        expected_cash_cents    BIGINT NULL COMMENT 'float + cash sales - cash refunds + paid in - paid out - drops, at close',
        variance_cents         BIGINT NULL COMMENT 'counted - expected',
        variance_approved_by   INT NULL,
        close_note             VARCHAR(500) NULL,
        open_register_id       BIGINT UNSIGNED AS (IF(status = 'open', register_id, NULL)) STORED,
        created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_one_open (open_register_id),
        KEY idx_register (register_id, opened_at),
        KEY idx_bdate (business_date)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_movements (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        drawer_session_id BIGINT UNSIGNED NOT NULL,
        type              VARCHAR(12) NOT NULL COMMENT 'paid_in, paid_out, drop',
        amount_cents      BIGINT NOT NULL COMMENT 'always positive',
        reason            VARCHAR(255) NOT NULL,
        created_at        DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        KEY idx_session (drawer_session_id),
        CONSTRAINT fk_cm_session FOREIGN KEY (drawer_session_id) REFERENCES drawer_sessions (id)
    ) $cs");

    $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('drawer_variance_threshold_cents', '500')");

    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales' AND index_name = 'idx_drawer'");
    if (!(int)$st->fetchColumn()) $pdo->exec("ALTER TABLE sales ADD KEY idx_drawer (drawer_session_id)");
};
