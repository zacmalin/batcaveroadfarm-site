<?php
// admin/migrations/0015_locations.php — Farm OS Phase 1, slice 8: stock by location, adjustments, counts (Nov 6, 2026; 06 Purchasing & Inventory)
// locations              where stock is kept. Seeded: Farmstand (sellable, default), Thrift Shop (sellable), Kitchen, Feed Room (not sellable).
// inventory_levels       quantity of each product at each location. inventory.stock_quantity stays as the total (sum of levels),
//                        so the public site, low-stock email, dashboard and the old register keep reading it unchanged.
// inventory_counts       a count session for one location (all items, one category, or items at/below their minimum).
// inventory_count_lines  one row per product counted in a session: expected, counted, difference, and the ledger row it wrote.
// Every tracked product's current stock becomes its Farmstand level, with an "opening" ledger row, so levels reconcile to
// the ledger from here on. Registers without a location are set to Farmstand. sales.location_id records where a sale's
// stock came from, so refunds and voids put it back there.
// Settings: count_flag_pct 20 and count_flag_cents 2500 (06 › Alerts: count differences over 20% or $25 are flagged).
// Safe to repeat: tables use IF NOT EXISTS; seeds and opening balances only fill what is missing.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS locations (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code         VARCHAR(30) NOT NULL,
        name         VARCHAR(80) NOT NULL,
        sellable     TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'registers can sell from here',
        is_default   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'where stock goes when no location is given',
        sort_order   INT NOT NULL DEFAULT 0,
        archived_at  DATETIME NULL,
        created_at   DATETIME NOT NULL COMMENT 'UTC',
        created_by   INT NULL,
        updated_at   DATETIME NULL,
        updated_by   INT NULL,
        UNIQUE KEY uq_code (code)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_levels (
        product_id   INT NOT NULL COMMENT 'inventory.id',
        location_id  BIGINT UNSIGNED NOT NULL,
        quantity     DECIMAL(12,3) NOT NULL DEFAULT 0,
        updated_at   DATETIME(3) NOT NULL COMMENT 'UTC',
        PRIMARY KEY (product_id, location_id),
        KEY idx_location (location_id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_counts (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        location_id  BIGINT UNSIGNED NOT NULL,
        scope        VARCHAR(12) NOT NULL DEFAULT 'all' COMMENT 'all, category, low',
        category_id  INT NULL,
        full_count   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = closing sets items not counted to 0',
        status       VARCHAR(10) NOT NULL DEFAULT 'open' COMMENT 'open, closed, cancelled',
        note         VARCHAR(255) NULL,
        started_at   DATETIME(3) NOT NULL COMMENT 'UTC',
        started_by   INT NULL,
        closed_at    DATETIME(3) NULL,
        closed_by    INT NULL,
        KEY idx_status (status, started_at)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_count_lines (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        count_id       BIGINT UNSIGNED NOT NULL,
        product_id     INT NOT NULL,
        expected_qty   DECIMAL(12,3) NOT NULL COMMENT 'on hand at the location when counted',
        counted_qty    DECIMAL(12,3) NOT NULL,
        difference     DECIMAL(12,3) NOT NULL,
        flagged        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'difference over count_flag_pct or count_flag_cents',
        new_here       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'first time this item was stocked at the location (never flagged)',
        adjustment_id  BIGINT UNSIGNED NULL,
        counted_at     DATETIME(3) NOT NULL COMMENT 'UTC',
        counted_by     INT NULL,
        UNIQUE KEY uq_line (count_id, product_id),
        KEY idx_flag (flagged, counted_at)
    ) $cs");

    $col = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales' AND column_name = 'location_id'");
    $col->execute();
    if (!(int)$col->fetchColumn()) $pdo->exec("ALTER TABLE sales ADD COLUMN location_id BIGINT UNSIGNED NULL COMMENT 'stock came off here (slice 8)' AFTER register_id");

    $idx = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'inventory_adjustments' AND index_name = 'idx_location'");
    $idx->execute();
    if (!(int)$idx->fetchColumn()) $pdo->exec("ALTER TABLE inventory_adjustments ADD KEY idx_location (location_id, created_at)");

    $seed = $pdo->prepare("INSERT IGNORE INTO locations (code, name, sellable, is_default, sort_order, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    foreach ([['farmstand', 'Farmstand', 1, 1, 1], ['thrift', 'Thrift Shop', 1, 0, 2], ['kitchen', 'Kitchen', 0, 0, 3], ['feed', 'Feed Room', 0, 0, 4]] as $r) $seed->execute($r);
    // Exactly one default: keep an existing one, otherwise Farmstand.
    if (!(int)$pdo->query("SELECT COUNT(*) FROM locations WHERE is_default = 1 AND archived_at IS NULL")->fetchColumn()) {
        $pdo->exec("UPDATE locations SET is_default = 1 WHERE code = 'farmstand'");
    }
    $def = (int)$pdo->query("SELECT id FROM locations WHERE is_default = 1 AND archived_at IS NULL ORDER BY sort_order, id LIMIT 1")->fetchColumn();

    $pdo->prepare("UPDATE registers SET location_id = ? WHERE location_id IS NULL")->execute([$def]);

    foreach (['count_flag_pct' => '20', 'count_flag_cents' => '2500'] as $k => $v) {
        $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    }

    // Opening balances: tracked products with no level anywhere get their current stock at the default location.
    $pdo->beginTransaction();
    try {
        $rows = $pdo->query("SELECT i.id, i.stock_quantity FROM inventory i
                             WHERE i.track_inventory = 1 AND NOT EXISTS (SELECT 1 FROM inventory_levels l WHERE l.product_id = i.id)
                             FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $lvl = $pdo->prepare("INSERT INTO inventory_levels (product_id, location_id, quantity, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3))");
        $led = $pdo->prepare("INSERT INTO inventory_adjustments (product_id, location_id, quantity_change, quantity_before, quantity_after, reason,
                                     source_type, note, created_at) VALUES (?, ?, ?, 0, ?, 'opening', 'migration', 'Opening balance (migration 0015)', UTC_TIMESTAMP(3))");
        foreach ($rows as $r) {
            $q = max(0, round((float)$r['stock_quantity'], 3));
            $lvl->execute([(int)$r['id'], $def, $q]);
            $led->execute([(int)$r['id'], $def, $q, $q]);
            if ($q !== round((float)$r['stock_quantity'], 3)) $pdo->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?")->execute([$q, (int)$r['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
};
