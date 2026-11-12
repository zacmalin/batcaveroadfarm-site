<?php
// admin/migrations/0033_capacity_holds.php — Farm OS Phase 3 slice 5: capacity holds, arrival check-in, weather days
// (Nov 12, 2026; 07 › Large parties and holds, Bookings: arrival and weather).
//   event_inquiries.start_time   the party's start time (the date is preferred_date). Holds and the gate use it;
//                                blank = the hold covers the whole open day.
//   check_ins.booking_id / event_inquiry_id   a group's arrival at the gate, counted against its own hold.
//   weather_days / weather_day_items   a day the farm called off for weather: the message sent, whether the day was
//                                closed to new bookings, and each booking or party told, with what was done about it.
//   site_settings                party_length_minutes 120, hold_early_minutes 30, weather_message (default wording).
// Holds themselves are not stored: they are worked out from confirmed bookings and parties each time, so a date or
// status change can never leave a stale hold behind. Adds only; nothing existing is changed. Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $has = function (string $table, string $col) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$has('event_inquiries', 'start_time')) {
        $pdo->exec("ALTER TABLE event_inquiries ADD COLUMN start_time TIME NULL COMMENT 'farm-local party start (slice 5)' AFTER preferred_date");
    }
    if (!$has('check_ins', 'booking_id')) {
        $pdo->exec("ALTER TABLE check_ins ADD COLUMN booking_id INT UNSIGNED NULL COMMENT 'group booking arrival (slice 5)' AFTER pass_visit_id,
                    ADD COLUMN event_inquiry_id INT UNSIGNED NULL COMMENT 'party arrival (slice 5)' AFTER booking_id,
                    ADD KEY idx_booking (booking_id), ADD KEY idx_event_inquiry (event_inquiry_id)");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS weather_days (
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        business_date  DATE NOT NULL COMMENT 'the farm-local day called off',
        message        TEXT NOT NULL COMMENT 'what customers were told',
        closed_day     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = a booking blackout was added for the day',
        created_at     DATETIME NOT NULL COMMENT 'UTC',
        created_by     INT NULL,
        UNIQUE KEY uq_date (business_date)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS weather_day_items (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        weather_day_id  INT UNSIGNED NOT NULL,
        subject_type    VARCHAR(20) NOT NULL COMMENT 'booking or event_inquiry',
        subject_id      INT UNSIGNED NOT NULL,
        email           VARCHAR(190) NOT NULL DEFAULT '',
        sent_at         DATETIME NULL COMMENT 'UTC; NULL = not emailed (no address, or it failed)',
        send_error      VARCHAR(255) NULL,
        outcome         VARCHAR(12) NOT NULL DEFAULT 'open' COMMENT 'open, rescheduled, refunded, credit, cancelled',
        outcome_note    VARCHAR(255) NULL,
        outcome_at      DATETIME NULL COMMENT 'UTC',
        outcome_by      INT NULL,
        UNIQUE KEY uq_day_subject (weather_day_id, subject_type, subject_id),
        KEY idx_subject (subject_type, subject_id)
    ) $cs");
    $msg = "We're sorry, but the weather means we can't safely host you on this day.\n\n"
         . "We'd love to see you another day: reply to this email with a day that works and we'll move your visit at no cost "
         . "(parties can move to any open day up to two weeks later). If you'd rather cancel, tell us and we'll refund anything you've paid.";
    $st = $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach (['party_length_minutes' => '120', 'hold_early_minutes' => '30', 'weather_message' => $msg] as $k => $v) $st->execute([$k, $v]);
};
