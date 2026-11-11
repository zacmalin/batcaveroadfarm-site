<?php
// admin/migrations/0028_gift_cards.php — Farm OS Phase 2: gift cards (Nov 11, 2026; 05 › Gift cards, Q-08)
//   gift_cards            one row per card: code, value, balance, where it came from, online order details
//   gift_card_txns        the ledger: issue, redeem, refund_credit, refund_void, void, adjust, reverse (balance follows it)
//   payments.gift_card_id, refund_payments.gift_card_id   which card paid (or got the money back)
//   Product "Gift Card" (type gift_card, open price, Exempt tax, no stock) in a "Gift Cards" category, for the register.
//   pos_tenders: "gift_card" added at the end when the setting lists payment types, so the register offers it.
// Safe to repeat: tables IF NOT EXISTS; columns, product and category only when missing.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS gift_cards (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code              CHAR(16) NOT NULL COMMENT '16 characters, no 0/O/1/I; shown as XXXX-XXXX-XXXX-XXXX. Whoever has it can spend it.',
        status            VARCHAR(12) NOT NULL DEFAULT 'active' COMMENT 'pending (online, not paid yet), active, void',
        source            VARCHAR(12) NOT NULL COMMENT 'pos, online, manual',
        initial_cents     BIGINT NOT NULL,
        balance_cents     BIGINT NOT NULL DEFAULT 0 COMMENT 'kept equal to the sum of gift_card_txns',
        sale_id           BIGINT UNSIGNED NULL COMMENT 'sold on the register',
        sale_line_id      BIGINT UNSIGNED NULL,
        purchaser_name    VARCHAR(120) NULL,
        purchaser_email   VARCHAR(190) NULL,
        recipient_name    VARCHAR(120) NULL,
        recipient_email   VARCHAR(190) NULL,
        message           VARCHAR(300) NULL,
        stripe_session_id VARCHAR(255) NULL,
        paid_at           DATETIME NULL COMMENT 'UTC, online orders',
        sent_at           DATETIME NULL COMMENT 'UTC, last time the card was emailed',
        note              VARCHAR(255) NULL COMMENT 'manual cards: why it was given',
        voided_at         DATETIME NULL,
        voided_by         INT NULL,
        void_reason       VARCHAR(255) NULL,
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        updated_at        DATETIME NULL,
        UNIQUE KEY uq_code (code),
        KEY idx_status (status),
        KEY idx_sale (sale_id),
        KEY idx_email (purchaser_email),
        KEY idx_session (stripe_session_id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS gift_card_txns (
        id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gift_card_id        BIGINT UNSIGNED NOT NULL,
        type                VARCHAR(16) NOT NULL COMMENT 'issue, redeem, refund_credit, refund_void, void, adjust, reverse',
        amount_cents        BIGINT NOT NULL COMMENT '+ adds to the balance, - takes from it',
        balance_after_cents BIGINT NOT NULL,
        sale_id             BIGINT UNSIGNED NULL,
        refund_id           BIGINT UNSIGNED NULL,
        payment_id          BIGINT UNSIGNED NULL,
        note                VARCHAR(255) NULL,
        created_at          DATETIME(3) NOT NULL COMMENT 'UTC',
        created_by          INT NULL,
        KEY idx_card (gift_card_id, id),
        KEY idx_sale (sale_id),
        CONSTRAINT fk_gct_card FOREIGN KEY (gift_card_id) REFERENCES gift_cards (id)
    ) $cs");

    if (!$col('payments', 'gift_card_id')) {
        $pdo->exec("ALTER TABLE payments ADD COLUMN gift_card_id BIGINT UNSIGNED NULL COMMENT 'gift_cards.id when tender = gift_card', ADD KEY idx_gift_card (gift_card_id)");
    }
    if (!$col('refund_payments', 'gift_card_id')) {
        $pdo->exec("ALTER TABLE refund_payments ADD COLUMN gift_card_id BIGINT UNSIGNED NULL COMMENT 'gift_cards.id the refund went back onto'");
    }

    // The register's "Gift Card" item: open price (staff type the amount), no tax, no stock.
    $pdo->exec("INSERT IGNORE INTO inventory_categories (name, slug, icon, sort_order) VALUES ('Gift Cards', 'gift-cards', '🎁', 90)");
    $cat = $pdo->query("SELECT id FROM inventory_categories WHERE slug = 'gift-cards'")->fetchColumn();
    $has = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE product_type = 'gift_card'")->fetchColumn();
    if (!$has) {
        $tax = $pdo->query("SELECT id FROM tax_categories WHERE code = 'exempt'")->fetchColumn();
        $sku = 'GIFT-CARD';
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE sku = ?");
        $st->execute([$sku]);
        if ((int)$st->fetchColumn()) $sku = null;
        $pdo->prepare("INSERT INTO inventory (category, is_active, name, icon, description, price, stock_quantity, min_stock_level, is_alert_enabled, sku, category_id,
                                              product_type, pricing_mode, unit, tax_category_id, track_inventory)
                       VALUES ('Merchandise', 1, 'Gift Card', '🎁', 'Type the amount. Prints or emails a gift card code.', 0, 0, NULL, 0, ?, ?, 'gift_card', 'open_price', 'each', ?, 0)")
            ->execute([$sku, $cat ?: null, $tax ?: null]);
    }

    // Offer it on the register when the farm has chosen its payment types (empty setting = all types already).
    $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'pos_tenders'");
    $st->execute();
    $cur = trim((string)$st->fetchColumn());
    if ($cur !== '' && !in_array('gift_card', array_map('trim', explode(',', $cur)), true)) {
        $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = 'pos_tenders'")->execute([$cur . ',gift_card']);
    }
};
