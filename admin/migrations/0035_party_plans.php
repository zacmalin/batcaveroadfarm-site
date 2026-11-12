<?php
// admin/migrations/0035_party_plans.php — Farm OS Phase 3: party packages, guest lists and assigned staff (Nov 12, 2026;
// 07 › Private events: "Packages (configurable): base price, included guests, extra guest price, duration, inclusions" and
// "guest list (optional) … assigned staff").
//   party_packages            what a party includes and costs. One is added to start: "Standard party" at the farm's
//                             current rate (party_price_per_guest_cents, $10 a guest) and length (party_length_minutes).
//   event_inquiries.package_id   the package a party was booked as (NULL = none picked; the per-guest rate applies).
//   party_guests              an optional guest list: name, child or adult, a note (allergies, who they came with),
//                             and when they arrived.
//   party_staff               who is working a party. They see it on their calendar and phone calendar.
// Adds only; nothing existing is changed. No price changes. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $has = function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    };
    $pdo->exec("CREATE TABLE IF NOT EXISTS party_packages (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name               VARCHAR(80) NOT NULL,
        inclusions         TEXT NULL COMMENT 'what the package includes, one item per line',
        base_cents         INT NOT NULL DEFAULT 0 COMMENT 'flat package price; 0 = per guest only',
        included_guests    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'guests covered by the base price',
        extra_guest_cents  INT NOT NULL DEFAULT 0 COMMENT 'each guest beyond included_guests',
        duration_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 120,
        active             TINYINT(1) NOT NULL DEFAULT 1,
        sort_order         SMALLINT NOT NULL DEFAULT 0,
        created_at         DATETIME NOT NULL COMMENT 'UTC',
        updated_at         DATETIME NOT NULL COMMENT 'UTC'
    ) $cs");
    if (!$has('event_inquiries', 'package_id')) {
        $pdo->exec("ALTER TABLE event_inquiries ADD COLUMN package_id INT UNSIGNED NULL COMMENT 'party_packages.id' AFTER event_type");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS party_guests (
        id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_inquiry_id  INT UNSIGNED NOT NULL,
        name              VARCHAR(120) NOT NULL,
        is_child          TINYINT(1) NOT NULL DEFAULT 1,
        note              VARCHAR(255) NULL COMMENT 'allergies, who they came with',
        arrived_at        DATETIME NULL COMMENT 'UTC',
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        KEY idx_party (event_inquiry_id)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS party_staff (
        event_inquiry_id  INT UNSIGNED NOT NULL,
        user_id           INT NOT NULL COMMENT 'users.id',
        job               VARCHAR(80) NULL COMMENT 'e.g. lead, animals, food',
        created_at        DATETIME NOT NULL COMMENT 'UTC',
        PRIMARY KEY (event_inquiry_id, user_id),
        KEY idx_user (user_id)
    ) $cs");
    // The starting package matches what parties cost today, so nothing changes until someone edits it.
    if (!(int)$pdo->query("SELECT COUNT(*) FROM party_packages")->fetchColumn()) {
        $get = function (string $k, int $d) use ($pdo): int {
            $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
            $st->execute([$k]);
            $v = $st->fetchColumn();
            return $v !== false && is_numeric($v) && (int)$v > 0 ? (int)$v : $d;
        };
        $pdo->prepare("INSERT INTO party_packages (name, inclusions, base_cents, included_guests, extra_guest_cents, duration_minutes, active, sort_order, created_at, updated_at)
                       VALUES ('Standard party', ?, 0, 0, ?, ?, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
            ->execute(["Farm admission for every guest\nA feed cup for each child\nRain or shine\nPay on arrival",
                       $get('party_price_per_guest_cents', 1000), $get('party_length_minutes', 120)]);
    }
};
