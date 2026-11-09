<?php
// admin/migrations/0020_thrift_intake.php — Farm OS Phase 2, slice 2.3: thrift intake (Nov 9, 2026; 05 Storefront, POS & Thrift)
// inventory columns for thrift items (each item is a quantity-1 product, product_type = thrift_item).
// Safe to repeat: columns added only when missing.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };

    $adds = [
        'thrift_source'       => "VARCHAR(20) NULL COMMENT 'donated, consigned, owned'",
        'thrift_condition'    => "VARCHAR(40) NULL COMMENT 'e.g. good, fair, as-is'",
        'thrift_status'       => "VARCHAR(20) NULL COMMENT 'available, sold, returned, withdrawn'",
        'thrift_intake_at'    => "DATETIME NULL COMMENT 'UTC when taken in'",
        'thrift_intake_by'    => "INT NULL",
        'consignor_customer_id' => "INT NULL COMMENT 'customers.id when consigned; settlements later'",
        'consignor_share_bp'  => "INT UNSIGNED NULL COMMENT 'consignor share in basis points; 6000 = 60%'",
    ];
    foreach ($adds as $c => $def) {
        if (!$col('inventory', $c)) $pdo->exec("ALTER TABLE inventory ADD COLUMN `$c` $def");
    }

    // Ensure a Thrift category exists for intake defaults (optional; intake can pick any category).
    try {
        $has = (int)$pdo->query("SELECT COUNT(*) FROM inventory_categories WHERE name = 'Thrift' OR slug = 'thrift'")->fetchColumn();
        if (!$has) {
            $cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory_categories'")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('slug', $cols, true) && in_array('sort_order', $cols, true)) {
                $pdo->exec("INSERT INTO inventory_categories (name, slug, sort_order) VALUES ('Thrift', 'thrift', 50)");
            } else {
                $pdo->exec("INSERT INTO inventory_categories (name) VALUES ('Thrift')");
            }
        }
    } catch (Throwable $e) { /* category optional */ }
};
