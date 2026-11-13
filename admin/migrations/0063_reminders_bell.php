<?php
// admin/migrations/0063_reminders_bell.php — parked items finished before go-live (Nov 13, 2026).
//   event_quotes.deposit_reminded_at / balance_reminded_at   when the deposit or balance reminder went to the customer (UTC), so once
//   passes.renewal_reminded_at                               when the "your pass ends soon" email went out (UTC), so once
//   notifications        the admin bell: one row per thing staff should see (a person, a role, or every manager)
//   notification_reads   who has read which notification
// Logic: includes/reminders.php, includes/notifications.php, admin/cron/reminders.php. Adds only; safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $tbl = function (string $t) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$t]);
        return (int)$st->fetchColumn() > 0;
    };
    if ($tbl('event_quotes')) {
        if (!$has('event_quotes', 'deposit_reminded_at')) $pdo->exec("ALTER TABLE event_quotes ADD COLUMN deposit_reminded_at DATETIME NULL COMMENT 'UTC; deposit reminder emailed (includes/reminders.php)'");
        if (!$has('event_quotes', 'balance_reminded_at')) $pdo->exec("ALTER TABLE event_quotes ADD COLUMN balance_reminded_at DATETIME NULL COMMENT 'UTC; balance-before-the-day reminder emailed'");
    }
    if ($tbl('passes') && !$has('passes', 'renewal_reminded_at')) {
        $pdo->exec("ALTER TABLE passes ADD COLUMN renewal_reminded_at DATETIME NULL COMMENT 'UTC; pass-ending-soon email sent (includes/reminders.php)'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NULL COMMENT 'one person; NULL = everyone in role (or every manager when role is NULL too)',
        role        VARCHAR(40) NULL,
        kind        VARCHAR(40) NOT NULL COMMENT 'party_request, booking_request, online_payment, deposit_overdue, reminder_sent, task_late, ...',
        title       VARCHAR(200) NOT NULL,
        body        VARCHAR(500) NULL,
        link        VARCHAR(255) NULL COMMENT 'admin path, e.g. /admin/party.php?id=2',
        dedupe_key  VARCHAR(100) NOT NULL COMMENT 'one notification per thing (kind:id)',
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        UNIQUE KEY uq_notifications_dedupe (dedupe_key),
        KEY ix_notifications_user (user_id, created_at),
        KEY ix_notifications_created (created_at)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_reads (
        notification_id BIGINT UNSIGNED NOT NULL,
        user_id         INT NOT NULL,
        read_at         DATETIME NOT NULL COMMENT 'UTC',
        PRIMARY KEY (notification_id, user_id),
        KEY ix_notification_reads_user (user_id)
    ) $cs");
};
