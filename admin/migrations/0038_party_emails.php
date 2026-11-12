<?php
// admin/migrations/0038_party_emails.php — Farm OS Phase 3: reminder and thank-you emails for parties (Nov 12, 2026;
// includes/party_emails.php). Bookings already had these; parties (event_inquiries) had neither.
//   event_inquiries.reminder_sent_at    when the day-before reminder went out (UTC), so it's sent once
//   event_inquiries.thank_you_sent_at   when the thank-you with the review link went out (UTC), so it's sent once
// Adds only; nothing existing is changed. Past parties are never emailed: the thank-you only goes out 18 hours to
// 4 days after a party starts. Safe to repeat.
return function (PDO $pdo): void {
    $has = function (string $col) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'event_inquiries' AND column_name = ?");
        $st->execute([$col]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$has('reminder_sent_at')) {
        $pdo->exec("ALTER TABLE event_inquiries ADD COLUMN reminder_sent_at DATETIME NULL COMMENT 'UTC; day-before reminder sent (includes/party_emails.php)'");
    }
    if (!$has('thank_you_sent_at')) {
        $pdo->exec("ALTER TABLE event_inquiries ADD COLUMN thank_you_sent_at DATETIME NULL COMMENT 'UTC; thank-you and review email sent (includes/party_emails.php)'");
    }
};
