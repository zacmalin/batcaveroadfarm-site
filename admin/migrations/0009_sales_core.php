<?php
// admin/migrations/0009_sales_core.php — Farm OS Phase 1, slice 1: sales core (Nov 6, 2026)
// New tables follow 04's conventions (BIGINT ids, integer cents, UTC times, business_date):
//   tax_categories          rates in basis points, set by the farm (Q-09)
//   number_sequences        server-assigned receipt numbers, R-YYYY-NNNNNN (C-12)
//   sales / sale_lines / payments   one sale, many lines, many payments (split tender), idempotency key
//   inventory_adjustments   append-only stock ledger; every POS sale writes one row per stocked line
// inventory (existing, C-07 keeps its name/INT id/DECIMAL price) gets the product fields from 05:
//   product_type, pricing_mode, unit, tax_category_id, track_inventory.
// Seeded tax rates copy what the register charges today (4.75% general, 2% for Produce/Bakery/Pantry)
// so nothing changes at the till; the farm sets the real rates in the register's Tax screen.
// Safe to repeat: every step checks what's already there.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $cs = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_categories (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(30)  NOT NULL,
        name        VARCHAR(80)  NOT NULL,
        rate_bp     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'basis points: 475 = 4.75%',
        notes       VARCHAR(255) NULL,
        sort_order  INT NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
        created_by  INT NULL,
        updated_at  DATETIME NULL COMMENT 'UTC',
        updated_by  INT NULL,
        archived_at DATETIME NULL,
        UNIQUE KEY uq_code (code)
    ) $cs");
    $pdo->exec("INSERT IGNORE INTO tax_categories (code, name, rate_bp, notes, sort_order, created_at) VALUES
        ('general',         'General merchandise', 475, 'Copied from the register (Nov 2026). Confirm the combined state + county rate with NCDOR.', 1, UTC_TIMESTAMP()),
        ('food_unprepared', 'Unprepared food',     200, 'Copied from the register (Nov 2026): Produce, Bakery, Pantry.', 2, UTC_TIMESTAMP()),
        ('exempt',          'Exempt',                0, NULL, 9, UTC_TIMESTAMP())");

    $pdo->exec("CREATE TABLE IF NOT EXISTS number_sequences (
        name       VARCHAR(40) NOT NULL,
        period     SMALLINT UNSIGNED NOT NULL COMMENT 'year the counter belongs to',
        next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (name, period)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sales (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_number       VARCHAR(24) NOT NULL,
        channel           VARCHAR(10) NOT NULL DEFAULT 'pos',
        register_id       BIGINT UNSIGNED NULL,
        drawer_session_id BIGINT UNSIGNED NULL,
        customer_id       INT NULL,
        status            VARCHAR(20) NOT NULL DEFAULT 'completed' COMMENT 'completed, voided, partially_refunded, refunded',
        subtotal_cents    BIGINT NOT NULL DEFAULT 0,
        discount_cents    BIGINT NOT NULL DEFAULT 0,
        tax_cents         BIGINT NOT NULL DEFAULT 0,
        total_cents       BIGINT NOT NULL DEFAULT 0,
        discount_bp       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'receipt-level discount, 1000 = 10%',
        tax_exempt        TINYINT(1) NOT NULL DEFAULT 0,
        business_date     DATE NOT NULL COMMENT 'farm-local date',
        completed_at      DATETIME(3) NOT NULL COMMENT 'UTC',
        idempotency_key   VARCHAR(64) NOT NULL,
        receipt_delivery  VARCHAR(10) NOT NULL DEFAULT 'none' COMMENT 'print, email, none',
        legacy_receipt_id INT NULL COMMENT 'pos_receipts.id written alongside for the Sales Log',
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC',
        created_by        INT NULL,
        updated_at        DATETIME NULL,
        updated_by        INT NULL,
        archived_at       DATETIME NULL,
        UNIQUE KEY uq_number (sale_number),
        UNIQUE KEY uq_idem (idempotency_key),
        KEY idx_bdate (business_date, status)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_lines (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_id           BIGINT UNSIGNED NOT NULL,
        line_no           SMALLINT UNSIGNED NOT NULL,
        product_id        INT NULL COMMENT 'inventory.id; NULL for an open-price custom item',
        description       VARCHAR(255) NOT NULL COMMENT 'snapshot',
        quantity          DECIMAL(12,3) NOT NULL,
        unit              VARCHAR(10) NOT NULL DEFAULT 'each',
        unit_price_cents  BIGINT NOT NULL,
        gross_cents       BIGINT NOT NULL COMMENT 'quantity x unit price, rounded',
        discount_cents    BIGINT NOT NULL DEFAULT 0,
        tax_category_id   BIGINT UNSIGNED NULL,
        tax_rate_bp       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'snapshot',
        tax_cents         BIGINT NOT NULL DEFAULT 0,
        line_total_cents  BIGINT NOT NULL COMMENT 'gross - discount + tax',
        unit_cost_cents   BIGINT NULL,
        product_type      VARCHAR(20) NOT NULL DEFAULT 'retail',
        category_label    VARCHAR(100) NULL COMMENT 'snapshot, for reports and the thrift note',
        note              VARCHAR(255) NULL,
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sale (sale_id),
        KEY idx_product (product_id),
        CONSTRAINT fk_sl_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_id           BIGINT UNSIGNED NULL,
        tender            VARCHAR(20) NOT NULL COMMENT 'cash, paypal, venmo, cashapp, check, card_online, gift_card, store_credit, other',
        amount_cents      BIGINT NOT NULL COMMENT 'applied to the sale',
        tendered_cents    BIGINT NULL COMMENT 'cash handed over',
        change_cents      BIGINT NOT NULL DEFAULT 0,
        payer_handle      VARCHAR(120) NULL COMMENT 'P2P: payer name or @handle',
        reference         VARCHAR(80)  NULL COMMENT 'P2P: last characters of the transaction ID; check number',
        status            VARCHAR(12) NOT NULL DEFAULT 'completed',
        reconciled_at     DATETIME NULL,
        reconciled_by     INT NULL,
        reconcile_flag    VARCHAR(20) NULL COMMENT 'missing, wrong_amount, reversed',
        business_date     DATE NOT NULL,
        created_at        DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        KEY idx_sale (sale_id),
        KEY idx_recon (tender, reconciled_at, business_date),
        CONSTRAINT fk_pay_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_adjustments (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id       INT NOT NULL COMMENT 'inventory.id',
        location_id      BIGINT UNSIGNED NULL,
        lot_id           BIGINT UNSIGNED NULL,
        quantity_change  DECIMAL(12,3) NOT NULL,
        quantity_before  DECIMAL(12,3) NOT NULL,
        quantity_after   DECIMAL(12,3) NOT NULL,
        reason           VARCHAR(20) NOT NULL COMMENT 'sale, void, refund_restock, count, receive, damage, correction',
        source_type      VARCHAR(30) NULL,
        source_id        BIGINT UNSIGNED NULL,
        unit_cost_cents  BIGINT NULL,
        note             VARCHAR(255) NULL,
        created_at       DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by       INT NULL,
        KEY idx_product (product_id, created_at),
        KEY idx_source (source_type, source_id)
    ) $cs");

    // Product fields on the existing inventory table
    $adds = [
        'product_type'    => "VARCHAR(20) NOT NULL DEFAULT 'retail' COMMENT 'retail, produce, kitchen_good, admission, pass, gift_card, thrift_item, fee, service'",
        'pricing_mode'    => "VARCHAR(12) NOT NULL DEFAULT 'each' COMMENT 'each, by_weight, open_price'",
        'unit'            => "VARCHAR(10) NOT NULL DEFAULT 'each' COMMENT 'each, lb, oz, dozen'",
        'tax_category_id' => "BIGINT UNSIGNED NULL",
        'track_inventory' => "TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($adds as $c => $def) {
        if (!$col('inventory', $c)) $pdo->exec("ALTER TABLE inventory ADD COLUMN `$c` $def");
    }

    // Backfill: same tax split the register uses today; Produce becomes product type 'produce'.
    $food = "('Produce','Bakery','Pantry','Baked Goods','Fruits & Vegetables')";
    $pdo->exec("UPDATE inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id
                SET i.tax_category_id = (SELECT id FROM tax_categories WHERE code =
                        IF(COALESCE(ic.name, i.category) IN $food, 'food_unprepared', 'general'))
                WHERE i.tax_category_id IS NULL");
    $pdo->exec("UPDATE inventory i LEFT JOIN inventory_categories ic ON ic.id = i.category_id
                SET i.product_type = 'produce'
                WHERE i.product_type = 'retail' AND COALESCE(ic.name, i.category) IN ('Produce','Fruits & Vegetables')");

    // Start this year's receipt counter after any receipts already numbered R-YYYY-… (none yet; safe on re-run).
    $pdo->exec("INSERT IGNORE INTO number_sequences (name, period, next_value) VALUES ('receipt', YEAR(UTC_TIMESTAMP()), 1)");
};
