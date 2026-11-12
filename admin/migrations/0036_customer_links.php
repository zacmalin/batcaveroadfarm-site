<?php
// admin/migrations/0036_customer_links.php — Farm OS Phase 3: one customer record across bookings, parties, passes and
// tickets; households; organizations; merging duplicates (Nov 12, 2026; 07 › Customer model, Matching and duplicates,
// CRM extension Phase 1). Adds only:
//   bookings / event_inquiries / passes .customer_id     the customer each record belongs to (tickets and sales have one)
//   bookings / event_inquiries .organization_id          the school, troop, church or business that booked
//   customers.household_id, .merged_into_id, .staff_notes, .source ('account', 'register', 'guest')
//   households, household_children (first name and age range only, per 07)
//   organizations, organization_contacts (contact role: viewer, booking, billing)
//   customer_merges   each merge, with the rows it moved, so it can be undone
//   view_permissions  'customers' for Managers (moderator). Owners always have it.
// Linking existing records (matching by email, making a guest customer for an email with no customer yet) is done by
// includes/crm.php bcrf_crm_sync(), which the Customers page runs; this migration does not change existing rows.
// Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $has = function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    };
    foreach (['bookings', 'event_inquiries', 'passes'] as $t) {
        if (!$has($t, 'customer_id')) $pdo->exec("ALTER TABLE $t ADD COLUMN customer_id INT NULL COMMENT 'customers.id (0036)', ADD KEY idx_customer (customer_id)");
    }
    foreach (['bookings', 'event_inquiries'] as $t) {
        if (!$has($t, 'organization_id')) $pdo->exec("ALTER TABLE $t ADD COLUMN organization_id INT UNSIGNED NULL COMMENT 'organizations.id (0036)', ADD KEY idx_org (organization_id)");
    }
    if (!$has('customers', 'household_id')) {
        $pdo->exec("ALTER TABLE customers
            ADD COLUMN household_id INT UNSIGNED NULL COMMENT 'households.id (0036)',
            ADD COLUMN merged_into_id INT NULL COMMENT 'set when this record was merged into another (0036)',
            ADD COLUMN staff_notes TEXT NULL COMMENT 'internal notes, staff only (0036)',
            ADD COLUMN source VARCHAR(10) NULL COMMENT 'account, register or guest (0036)',
            ADD KEY idx_household (household_id), ADD KEY idx_merged (merged_into_id)");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS households (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        created_by  INT NULL
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS household_children (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        household_id  INT UNSIGNED NOT NULL,
        first_name    VARCHAR(50) NOT NULL,
        age_range     VARCHAR(10) NOT NULL COMMENT 'under2, 2-4, 5-8, 9-12, 13-17',
        created_at    DATETIME NOT NULL COMMENT 'UTC',
        KEY idx_household (household_id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS organizations (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(160) NOT NULL,
        kind        VARCHAR(12) NOT NULL DEFAULT 'other' COMMENT 'school, scouts, church, business, other',
        email       VARCHAR(190) NULL,
        phone       VARCHAR(30) NULL,
        address     VARCHAR(255) NULL,
        notes       TEXT NULL,
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        created_by  INT NULL,
        KEY idx_name (name)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS organization_contacts (
        organization_id  INT UNSIGNED NOT NULL,
        customer_id      INT NOT NULL,
        role             VARCHAR(10) NOT NULL DEFAULT 'booking' COMMENT 'viewer, booking, billing',
        created_at       DATETIME NOT NULL COMMENT 'UTC',
        PRIMARY KEY (organization_id, customer_id),
        KEY idx_customer (customer_id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_merges (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kept_id      INT NOT NULL,
        merged_id    INT NOT NULL,
        moved_json   MEDIUMTEXT NOT NULL COMMENT '{table: {column: [row ids]}} moved from merged_id to kept_id',
        before_json  TEXT NULL COMMENT 'the merged customer as it was',
        merged_at    DATETIME NOT NULL COMMENT 'UTC',
        merged_by    INT NULL,
        undone_at    DATETIME NULL COMMENT 'UTC',
        undone_by    INT NULL,
        KEY idx_kept (kept_id),
        KEY idx_merged (merged_id)
    ) $cs");
    $pdo->exec("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES ('moderator', 'customers', 1)");
};
