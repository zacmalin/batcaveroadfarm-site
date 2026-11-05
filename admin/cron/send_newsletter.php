<?php
/**
 * cron/send_newsletter.php
 * Sends a "new blog post" newsletter to subscribers via PHP mail(), in capped
 * batches. Designed to be run on a schedule (e.g. every 10 min). Safe to run
 * often: it only ever works on the oldest published post that hasn't finished
 * sending, never double-sends (newsletter_log PK), and stops when the list is done.
 *
 * Run from cron as:   php /home2/ddailnmy/public_html/batcaveroadfarm/cron/send_newsletter.php
 * Or via URL with a key:  /cron/send_newsletter.php?key=<BCRF_CRON_KEY>  (only if CRON_KEY is set in bcrf-secrets.php)
 */

// Swallow any stray pre-output (e.g. a UTF-8 BOM in an include) so header()
// and http_response_code() still work on the web trigger.
ob_start();

// Locate config.php by climbing upward, so this works whether the script is
// in /cron/ or /admin/cron/ (or anywhere). The email template is then found
// relative to the discovered site root (<root>/admin/includes/email_template.php).
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "send_newsletter: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;

$bcrf_docroot = dirname($bcrf_cfg);
$bcrf_tpl = $bcrf_docroot . '/admin/includes/email_template.php';
if (!is_file($bcrf_tpl)) { fwrite(STDERR, "send_newsletter: email_template.php not found\n"); exit(1); }
require_once $bcrf_tpl;
require_once $bcrf_docroot . '/includes/unsubscribe.php';   // signed unsubscribe links

// Drop anything the includes printed (BOM/whitespace) before we send headers.
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('send_newsletter');   // heartbeat for the admin System Health page

// ── Access guard: CLI is always allowed; web access requires the secret key ──
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = $_SERVER['HTTP_X_CRON_KEY'] ?? ($_GET['key'] ?? '');   // header preferred: keeps the key out of server logs
    if (!defined('BCRF_CRON_KEY') || strlen(BCRF_CRON_KEY) < 24 || !hash_equals(BCRF_CRON_KEY, (string)$key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

// ── Tunables ──
const NL_BATCH       = 40;                              // recipients per run (stay under host limits)
require_once dirname(__DIR__, 2) . '/includes/farm.php';   // farm values (Farm OS Phase 0)
define('NL_FROM_EMAIL', bcrf_farm_email('support'));
define('NL_FROM_NAME',  bcrf_farm_name());
const NL_SITE        = 'https://batcaveroadfarm.com';

function nl_log($m) { echo $m . "\n"; }

try {
    // Oldest published post not yet fully sent
    $post = $pdo->query("
        SELECT id, title, slug, article, feature_image, image_url,
               COALESCE(date_published, created_at) AS pub
        FROM blog_posts
        WHERE status = 'published' AND newsletter_sent_at IS NULL
        ORDER BY COALESCE(date_published, created_at) ASC, id ASC
        LIMIT 1
    ")->fetch();

    if (!$post) { nl_log('Nothing to send.'); exit; }

    $pid   = (int)$post['id'];
    $url   = NL_SITE . '/blog/' . rawurlencode($post['slug']) . '/';
    $img   = !empty($post['feature_image']) ? $post['feature_image'] : ($post['image_url'] ?? '');
    if ($img && !preg_match('#^https?://#', $img)) $img = NL_SITE . '/' . ltrim($img, '/');
    $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags((string)($post['article'] ?? ''))));
    if (mb_strlen($excerpt) > 320) $excerpt = mb_substr($excerpt, 0, 320) . '…';

    // Next batch of subscribers who haven't received THIS post yet
    $stmt = $pdo->prepare("
        SELECT email FROM mailing_list
        WHERE is_subscribed = 1
          AND email NOT IN (SELECT email FROM newsletter_log WHERE post_id = ?)
        ORDER BY id ASC
        LIMIT " . (int)NL_BATCH . "
    ");
    $stmt->execute([$pid]);
    $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$recipients) {
        // Everyone has it — close out the post.
        $pdo->prepare("UPDATE blog_posts SET newsletter_sent_at = NOW() WHERE id = ?")->execute([$pid]);
        nl_log("Post #$pid fully sent — marked complete.");
        exit;
    }

    $logIns = $pdo->prepare("INSERT IGNORE INTO newsletter_log (post_id, email) VALUES (?, ?)");
    $sent = 0;

    foreach ($recipients as $email) {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $logIns->execute([$pid, $email]); continue; }

        $unsub   = bcrf_unsub_url($email);   // signed per-recipient link (includes/unsubscribe.php)

        $subject = $post['title'];

        // HTML part — wrap content in the shared branded template.
        // The template body sits on a dark brown background, so content text is light.
        $content  = '';
        if ($img) $content .= '<p style="margin:0 0 18px;"><img src="' . htmlspecialchars($img, ENT_QUOTES) . '" alt="" width="536" style="width:100%;max-width:536px;height:auto;border-radius:8px;display:block;"></p>';
        $content .= '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;line-height:1.25;margin:0 0 14px;">' . htmlspecialchars($post['title']) . '</h1>';
        $content .= '<p style="font-family:Lora,Georgia,serif;color:#f5ead6;font-size:16px;line-height:1.7;margin:0 0 24px;">' . htmlspecialchars($excerpt) . '</p>';
        $content .= '<p style="margin:0;"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px;">Read the full post &#8594;</a></p>';
        // Plain-text alternative
        if (!function_exists('bcrf_utm_url')) require_once __DIR__ . '/../../includes/ga.php';
        $text = $post['title'] . "\n\n" . $excerpt . "\n\nRead it here: " . bcrf_utm_url($url, 'newsletter', 'email', 'new-post-' . $post['title'])
              . "\n\n---\nYou joined the " . NL_FROM_NAME . " mailing list. Unsubscribe: " . $unsub . "\n";

        // Email adapter (Farm OS Phase 0): same template, per-recipient one-click unsubscribe and GA tags as
        // before. Bulk sends stay on PHP mail() (transport 'mail') so they don't count against the SMTP limit.
        $ok = bcf_send_mail($email, $subject, $content, [
            'subtitle'         => 'Newsletter',
            'from_name'        => NL_FROM_NAME,
            'from_email'       => NL_FROM_EMAIL,
            'reply_to'         => NL_FROM_EMAIL,
            'unsubscribe'      => true,
            'list_unsubscribe' => $unsub,
            'text'             => $text,
            'utm_source'       => 'newsletter',
            'utm_campaign'     => 'new-post-' . $post['title'],
            'transport'        => 'mail',
            'headers'          => ['Precedence: bulk', 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . bcrf_farm_domain() . '>'],
        ]);
        $logIns->execute([$pid, $email]); // log regardless to avoid retry-spamming a bad address
        if ($ok) $sent++;
        usleep(120000); // ~0.12s between sends, gentle on the mail queue
    }

    nl_log("Post #$pid: attempted " . count($recipients) . ", sent $sent this run.");

    // If that was the last batch, close the post out now.
    $remaining = $pdo->prepare("SELECT COUNT(*) FROM mailing_list WHERE is_subscribed = 1 AND email NOT IN (SELECT email FROM newsletter_log WHERE post_id = ?)");
    $remaining->execute([$pid]);
    if ((int)$remaining->fetchColumn() === 0) {
        $pdo->prepare("UPDATE blog_posts SET newsletter_sent_at = NOW() WHERE id = ?")->execute([$pid]);
        nl_log("Post #$pid fully sent — marked complete.");
    }
} catch (Throwable $e) {
    error_log('[BCRF] newsletter send failed: ' . $e->getMessage());
    nl_log('Error: ' . $e->getMessage());
}
