<?php
// admin/migrations/0014_register_extras.php — Farm OS Phase 1, slice 7: item discounts and held carts (Nov 6, 2026)
// sale_lines.line_discount_bp: the % off given on that item (1000 = 10%). discount_cents on the line still holds the whole
//   discount (item discount + its share of any receipt discount), so reports and refunds keep working unchanged.
// sale_holds: a cart parked on the register ("held") while a customer steps away, recalled later on any device.
// sales.customer_id already exists (0009); nothing to add for attaching a customer.
// Safe to repeat.
return function (PDO $pdo): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sale_lines' AND column_name = 'line_discount_bp'");
    $st->execute();
    if (!(int)$st->fetchColumn()) {
        $pdo->exec("ALTER TABLE sale_lines ADD COLUMN line_discount_bp INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'item discount, 1000 = 10% (slice 7)' AFTER discount_cents");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_holds (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        register_id  BIGINT UNSIGNED NULL,
        label        VARCHAR(80) NOT NULL,
        customer_id  INT NULL,
        cart_json    JSON NOT NULL COMMENT 'register cart: lines, receipt discount, tax exempt, customer',
        line_count   INT NOT NULL DEFAULT 0,
        total_cents  BIGINT NOT NULL DEFAULT 0 COMMENT 'estimate when held; prices are checked again at sale',
        status       VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open, recalled, discarded',
        created_at   DATETIME NOT NULL COMMENT 'UTC',
        created_by   INT NULL,
        closed_at    DATETIME NULL COMMENT 'UTC, when recalled or discarded',
        closed_by    INT NULL,
        KEY idx_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
