<?php
// admin/migrations/0012_admission.php — Farm OS Phase 1, slice 4a: admission and check-ins (Nov 6, 2026)
//   ticket_types  one per admission product (the inventory items in the "admissions" category, which the
//                 online ticket page already sells). counts_toward_capacity per type.
//   tickets       one per order, with a quantity (07 › Admission). public_code is the code on the QR.
//                 Online day tickets move here from bookings (C-04): every existing day_admission booking is
//                 copied (booking_id links back); new online orders are copied automatically until the
//                 online checkout writes tickets itself (slice 4b).
//   check_ins     the one record of a visit: ticket redemption, pass scan, gate sale, booking, comp.
//                 Existing pass visit logs are copied in (C-04 / 07 › Annual passes).
// Settings: capacity_limit (20, D-04), visit_length_minutes (90, 07), ticket_expiry_months (12, Q-04).
// Safe to repeat.
return function (PDO $pdo): void {
    $cs = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_types (
        id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id             INT NOT NULL COMMENT 'inventory.id of the admission product',
        name                   VARCHAR(100) NOT NULL,
        counts_toward_capacity TINYINT(1) NOT NULL DEFAULT 1,
        sort_order             INT NOT NULL DEFAULT 0,
        created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        archived_at            DATETIME NULL,
        UNIQUE KEY uq_product (product_id)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        public_code       CHAR(10) NOT NULL COMMENT 'random, shown as XXXX-XXXX-XX and in the QR',
        source            VARCHAR(12) NOT NULL COMMENT 'online, gate, comp',
        booking_id        INT UNSIGNED NULL COMMENT 'bookings.id it was copied from (C-04)',
        sale_id           BIGINT UNSIGNED NULL,
        purchaser_name    VARCHAR(120) NOT NULL DEFAULT '',
        purchaser_email   VARCHAR(190) NOT NULL DEFAULT '',
        purchaser_phone   VARCHAR(40)  NOT NULL DEFAULT '',
        customer_id       INT NULL,
        breakdown_json    JSON NULL COMMENT 'ticket type name => quantity',
        quantity          INT NOT NULL,
        quantity_redeemed INT NOT NULL DEFAULT 0,
        status            VARCHAR(20) NOT NULL DEFAULT 'issued' COMMENT 'pending_payment, issued, partially_redeemed, redeemed, void',
        visit_date        DATE NULL COMMENT 'date the buyer picked, if any (tickets are good any open day, D-04)',
        expires_at        DATETIME NULL COMMENT 'UTC; null = never',
        amount_cents      BIGINT NOT NULL DEFAULT 0,
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        created_by        INT NULL,
        updated_at        DATETIME NULL,
        archived_at       DATETIME NULL,
        UNIQUE KEY uq_code (public_code),
        UNIQUE KEY uq_booking (booking_id),
        KEY idx_sale (sale_id),
        KEY idx_email (purchaser_email),
        KEY idx_name (purchaser_name)
    ) $cs");

    $pdo->exec("CREATE TABLE IF NOT EXISTS check_ins (
        id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        source_type              VARCHAR(12) NOT NULL COMMENT 'ticket, pass, gate_sale, booking, comp',
        ticket_id                BIGINT UNSIGNED NULL,
        pass_id                  INT UNSIGNED NULL,
        sale_id                  BIGINT UNSIGNED NULL,
        pass_visit_id            INT UNSIGNED NULL COMMENT 'pass_visits.id (old visit log), unique',
        party_size               INT NOT NULL,
        guests_counted           INT NOT NULL COMMENT 'how many count toward capacity',
        checked_in_at            DATETIME NOT NULL COMMENT 'UTC',
        expected_out_at          DATETIME NOT NULL COMMENT 'UTC; check-in + typical visit length',
        checked_out_at           DATETIME NULL COMMENT 'UTC',
        business_date            DATE NOT NULL,
        staff_user_id            INT NULL,
        capacity_override_reason VARCHAR(255) NULL,
        override_by              INT NULL,
        note                     VARCHAR(255) NULL,
        UNIQUE KEY uq_pass_visit (pass_visit_id),
        KEY idx_open (checked_out_at, expected_out_at),
        KEY idx_bdate (business_date),
        KEY idx_ticket (ticket_id),
        KEY idx_pass (pass_id),
        KEY idx_sale (sale_id)
    ) $cs");

    foreach (['capacity_limit' => '20', 'visit_length_minutes' => '90', 'ticket_expiry_months' => '12'] as $k => $v) {
        $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
    }

    // Admission products: product type, no stock count (admission isn't stock), and one ticket type each
    $pdo->exec("UPDATE inventory i JOIN inventory_categories ic ON ic.id = i.category_id SET i.product_type = 'admission', i.track_inventory = 0 WHERE ic.slug = 'admissions'");
    $pdo->exec("INSERT IGNORE INTO ticket_types (product_id, name, counts_toward_capacity, sort_order, created_at)
                SELECT i.id, i.name, 1, 0, UTC_TIMESTAMP() FROM inventory i JOIN inventory_categories ic ON ic.id = i.category_id
                WHERE ic.slug = 'admissions'");

    // Online tickets: copy every day_admission booking (includes/admission.php does the copying, also used for new orders)
    require_once dirname(__DIR__, 2) . '/includes/admission.php';
    bcrf_tickets_sync_bookings($pdo, 100000);

    // Pass visit log → check-ins. pass_visits.visited_at was written with the database's NOW(); convert with
    // the database's current offset to UTC (old rows from the other side of a DST change land an hour off,
    // which only matters for time-of-day reports on history).
    $offset = (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())")->fetchColumn();
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $mins = 90;
    $ins = $pdo->prepare("INSERT IGNORE INTO check_ins (source_type, pass_id, pass_visit_id, party_size, guests_counted, checked_in_at, expected_out_at,
                                 checked_out_at, business_date, note)
                          VALUES ('pass', ?, ?, ?, ?, ?, ?, ?, ?, 'Copied from the pass visit log (0012)')");
    foreach ($pdo->query("SELECT id, pass_id, visited_at, guest_count FROM pass_visits ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $utc = (new DateTimeImmutable($v['visited_at'], new DateTimeZone('UTC')))->modify(-$offset . ' seconds');
        $out = $utc->modify("+{$mins} minutes")->format('Y-m-d H:i:s');
        $n = max(1, (int)$v['guest_count']);
        $ins->execute([(int)$v['pass_id'], (int)$v['id'], $n, $n, $utc->format('Y-m-d H:i:s'), $out, $out, $utc->setTimezone($tz)->format('Y-m-d')]);
    }
};
