<?php
// admin/migrations/0026_social_metrics.php — Reports › Social Media (Nov 11, 2026)
// Two tables filled by includes/social_reports.php (daily job admin/cron/fetch_social_reports.php + Refresh button):
//   social_metrics_daily — one number per network / metric / day (Facebook + Instagram insights, follower snapshots)
//   social_posts         — recent posts with their latest likes, comments, shares, views, reach, saves
// Safe to repeat.
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `social_metrics_daily` (
      `network` varchar(12) NOT NULL,
      `metric` varchar(40) NOT NULL,
      `day` date NOT NULL,
      `value` bigint NOT NULL DEFAULT 0,
      `fetched_at` datetime NOT NULL,
      PRIMARY KEY (`network`, `metric`, `day`),
      KEY `idx_day` (`day`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `social_posts` (
      `network` varchar(12) NOT NULL,
      `post_id` varchar(64) NOT NULL,
      `posted_at` datetime NOT NULL,
      `kind` varchar(30) DEFAULT NULL,
      `caption` varchar(300) DEFAULT NULL,
      `permalink` varchar(500) DEFAULT NULL,
      `likes` int DEFAULT NULL,
      `comments` int DEFAULT NULL,
      `shares` int DEFAULT NULL,
      `views` int DEFAULT NULL,
      `reach` int DEFAULT NULL,
      `saves` int DEFAULT NULL,
      `fetched_at` datetime NOT NULL,
      PRIMARY KEY (`network`, `post_id`),
      KEY `idx_posted` (`posted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
};
