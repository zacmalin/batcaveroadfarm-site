<?php
/**
 * admin/cron/publish_scheduled.php
 * Scheduled blog post publisher — run every 5 minutes via cPanel Cron.
 *
 * Bluehost cPanel cron command:
 *   /usr/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/publish_scheduled.php
 *
 * Cron schedule: every 5 minutes
 *   Minute:  *\/5   Hour: *   Day: *   Month: *   Weekday: *
 */

// Run from CLI only — block web access
if (php_sapi_name() !== 'cli' && !defined('CRON_ALLOWED')) {
    http_response_code(403);
    exit("Direct web access blocked.\n");
}

define('CRON_ALLOWED', true);

// Bootstrap — load config without session
require_once dirname(__DIR__, 2) . '/config.php';

$now = date('Y-m-d H:i:s');
$published = 0;
$errors    = 0;

try {
    // Find all posts scheduled for now or earlier that haven't been published yet
    $due = $pdo->prepare("
        SELECT id, title
        FROM   blog_posts
        WHERE  status     = 'draft'
          AND  publish_at IS NOT NULL
          AND  publish_at <= ?
        ORDER  BY publish_at ASC
    ");
    $due->execute([$now]);
    $posts = $due->fetchAll(PDO::FETCH_ASSOC);

    if (empty($posts)) {
        echo "[{$now}] No scheduled posts due.\n";
        exit(0);
    }

    $upd = $pdo->prepare("UPDATE blog_posts SET status = 'published' WHERE id = ? AND status = 'draft'");

    foreach ($posts as $post) {
        try {
            $upd->execute([$post['id']]);
            if ($upd->rowCount() > 0) {
                $published++;
                echo "[{$now}] Published: #{$post['id']} — {$post['title']}\n";
            }
        } catch (Throwable $e) {
            $errors++;
            error_log('[Cron] Publish failed for post #' . $post['id'] . ': ' . $e->getMessage());
            echo "[{$now}] ERROR post #{$post['id']}: " . $e->getMessage() . "\n";
        }
    }

    echo "[{$now}] Done. Published: {$published}, Errors: {$errors}\n";

} catch (Throwable $e) {
    error_log('[Cron] publish_scheduled.php fatal: ' . $e->getMessage());
    echo "[{$now}] FATAL: " . $e->getMessage() . "\n";
    exit(1);
}
