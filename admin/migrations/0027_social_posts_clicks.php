<?php
// admin/migrations/0027_social_posts_clicks.php — Reports › Social Media (Nov 11, 2026)
// Adds social_posts.clicks: Facebook post clicks (Meta post_clicks: link, photo and "see more" clicks).
// includes/social_reports.php fills it once this has run, and leaves it out until then. Safe to repeat.
return function (PDO $pdo): void {
    $has = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns
                             WHERE table_schema = DATABASE() AND table_name = 'social_posts' AND column_name = 'clicks'")->fetchColumn();
    if (!$has) $pdo->exec("ALTER TABLE `social_posts` ADD COLUMN `clicks` int DEFAULT NULL AFTER `saves`");
};
