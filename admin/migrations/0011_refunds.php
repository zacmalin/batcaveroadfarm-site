<?php
// admin/migrations/0011_refunds.php — Farm OS Phase 1, slice 3: refunds and voids (Nov 6, 2026)
//   refunds           one refund of some or all of a sale; RF-YYYY-NNNNNN; idempotency key
//   refund_lines      which sale lines, how many, and whether each went back into stock
//   refund_payments   how the money went back (original tender by default; cash comes from the open drawer)
//   sale_lines        + refunded_quantity / refunded_net_cents / refunded_tax_cents (running totals)
//   sales             + refunded_cents, voided_at, voided_by, void_reason
// Setting refund_approval_threshold_cents: 0 = off (03: default off; suggested $50 once staff are hired).
// Safe to repeat.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $cs = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

    $pdo->exec("CREATE TABLE IF NOT EXISTS refunds (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        refund_number     VARCHAR(24) NOT NULL,
        sale_id           BIGINT UNSIGNED NOT NULL,
        register_id       BIGINT UNSIGNED NULL,
        drawer_session_id BIGINT UNSIGNED NULL COMMENT 'drawer any cash came out of',
        business_date     DATE NOT NULL,
        net_cents         BIGINT NOT NULL COMMENT 'goods after discount',
        tax_cents         BIGINT NOT NULL,
        total_cents       BIGINT NOT NULL,
        reason            VARCHAR(255) NOT NULL,
        approved_by       INT NULL COMMENT 'set when the amount was over the approval threshold',
        idempotency_key   VARCHAR(64) NOT NULL,
        created_at        DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        UNIQUE KEY uq_number (refund_number),
        UNIQUE KEY uq_idem (idempotency_key),
        KEY idx_sale (sale_id),
        KEY idx_drawer (drawer_session_id),
        CONSTRAINT fk_rf_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS refund_lines (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        refund_id     BIGINT UNSIGNED NOT NULL,
        sale_line_id  BIGINT UNSIGNED NOT NULL,
        product_id    INT NULL,
        quantity      DECIMAL(12,3) NOT NULL,
        net_cents     BIGINT NOT NULL,
        tax_cents     BIGINT NOT NULL,
        total_cents   BIGINT NOT NULL,
        restock       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = back into stock, 0 = written off / not stocked',
        KEY idx_refund (refund_id),
        KEY idx_line (sale_line_id),
        CONSTRAINT fk_rl_refund FOREIGN KEY (refund_id) REFERENCES refunds (id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS refund_payments (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        refund_id     BIGINT UNSIGNED NOT NULL,
        tender        VARCHAR(20) NOT NULL,
        amount_cents  BIGINT NOT NULL,
        payer_handle  VARCHAR(120) NULL,
        reference     VARCHAR(80) NULL COMMENT 'P2P: last characters of the refund transaction; check number',
        created_at    DATETIME(3) NOT NULL,
        KEY idx_refund (refund_id),
        CONSTRAINT fk_rp_refund FOREIGN KEY (refund_id) REFERENCES refunds (id)
    ) $cs");

    foreach ([
        ['sale_lines', 'refunded_quantity',  "DECIMAL(12,3) NOT NULL DEFAULT 0"],
        ['sale_lines', 'refunded_net_cents', "BIGINT NOT NULL DEFAULT 0"],
        ['sale_lines', 'refunded_tax_cents', "BIGINT NOT NULL DEFAULT 0"],
        ['sales', 'refunded_cents', "BIGINT NOT NULL DEFAULT 0"],
        ['sales', 'voided_at',      "DATETIME(3) NULL"],
        ['sales', 'voided_by',      "INT NULL"],
        ['sales', 'void_reason',    "VARCHAR(255) NULL"],
    ] as [$t, $c, $def]) {
        if (!$col($t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    }
    $pdo->exec("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('refund_approval_threshold_cents', '0')");
};
