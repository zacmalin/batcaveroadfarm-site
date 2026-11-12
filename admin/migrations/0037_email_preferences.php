<?php
// admin/migrations/0037_email_preferences.php — Farm OS Phase 3: notification preferences (Nov 12, 2026; 07 › Communications
// and consent, CRM extension "Account preferences"). Adds only:
//   email_preferences      one row per email: the optional service emails it gets (visit reminders, Roost Rewards
//                          reminders) and a random token for its preferences link (no email address in the URL)
//   email_preference_log   every change: which setting, on or off, how (link, portal, staff, unsubscribe, signup), when
//   mailing_list.consent_source, .consent_at, .unsubscribed_at   where and when newsletter consent was given or taken
//                          back, from now on. Existing subscribers keep their current state; their columns stay empty.
// Transactional emails (confirmations, tickets, receipts, quotes, passes, sign-in links, weather closures) are not
// optional and have no setting. Newsletter topics stay in mailing_list, so every form shows the same state.
// Safe to repeat.
return function (PDO $pdo): void {
    $cs = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_preferences (
        id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email              VARCHAR(190) NOT NULL,
        token              CHAR(32) NOT NULL COMMENT 'random; the preferences link carries this, not the email',
        visit_reminders    TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'reminder the day before a booked visit',
        rewards_reminders  TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Roost Rewards points-expiry reminders',
        created_at         DATETIME NOT NULL COMMENT 'UTC',
        updated_at         DATETIME NULL COMMENT 'UTC',
        UNIQUE KEY uq_email (email),
        UNIQUE KEY uq_token (token)
    ) $cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_preference_log (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email       VARCHAR(190) NOT NULL,
        setting     VARCHAR(20) NOT NULL COMMENT 'visit_reminders, rewards_reminders, newsletter, nursery, inventory',
        value       TINYINT(1) NOT NULL,
        via         VARCHAR(12) NOT NULL COMMENT 'link, portal, staff, unsubscribe, signup',
        actor_id    INT NULL COMMENT 'staff users.id when via = staff',
        created_at  DATETIME NOT NULL COMMENT 'UTC',
        KEY idx_email (email, id)
    ) $cs");
    $has = function (string $col) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mailing_list' AND column_name = ?");
        $st->execute([$col]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$has('consent_source')) {
        $pdo->exec("ALTER TABLE mailing_list
            ADD COLUMN consent_source VARCHAR(20) NULL COMMENT 'signup, portal, link (0037); empty = before tracking',
            ADD COLUMN consent_at DATETIME NULL COMMENT 'UTC (0037)',
            ADD COLUMN unsubscribed_at DATETIME NULL COMMENT 'UTC (0037)'");
    }
};
