<?php
// blog/feed.php — RSS 2.0 feed of published posts. Drives ESP "RSS-to-email" + feed readers.
require_once '../config.php';

$SITE = 'https://batcaveroadfarm.com';
date_default_timezone_set('America/New_York');

try {
    $stmt = $pdo->query("
        SELECT title, slug, article, author, feature_image, image_url,
               COALESCE(date_published, created_at) AS pub
        FROM blog_posts
        WHERE status = 'published'
        ORDER BY COALESCE(date_published, created_at) DESC, id DESC
        LIMIT 20
    ");
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[BCRF] blog feed query failed: ' . $e->getMessage());
    $posts = [];
}

function rss_x($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function rss_d($d) { $ts = strtotime((string)$d); return date(DATE_RSS, $ts ?: time()); }

$lastBuild = $posts ? rss_d($posts[0]['pub']) : date(DATE_RSS);

header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0"
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Bat Cave Road Farm — Farmstead Journal</title>
    <link><?= $SITE ?>/blog/</link>
    <atom:link href="<?= $SITE ?>/blog/feed.php" rel="self" type="application/rss+xml" />
    <description>News, recipes, baby-animal updates, and happenings from Bat Cave Road Farm in Old Fort, NC.</description>
    <language>en-us</language>
    <lastBuildDate><?= $lastBuild ?></lastBuildDate>
    <generator>Bat Cave Road Farm</generator>
<?php foreach ($posts as $p):
    $url = $SITE . '/blog/' . rawurlencode($p['slug']) . '/';
    $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags((string)($p['article'] ?? ''))));
    if (mb_strlen($excerpt) > 400) $excerpt = mb_substr($excerpt, 0, 400) . '…';
    $img = !empty($p['feature_image']) ? $p['feature_image'] : (!empty($p['image_url']) ? $p['image_url'] : '');
    $imgUrl = $img && !preg_match('#^https?://#', $img) ? $SITE . '/' . ltrim($img, '/') : $img;
?>
    <item>
      <title><?= rss_x($p['title']) ?></title>
      <link><?= rss_x($url) ?></link>
      <guid isPermaLink="true"><?= rss_x($url) ?></guid>
      <pubDate><?= rss_d($p['pub']) ?></pubDate>
<?php if (!empty($p['author'])): ?>      <dc:creator><?= rss_x($p['author']) ?></dc:creator>
<?php endif; ?>
<?php if ($imgUrl): ?>      <media:content url="<?= rss_x($imgUrl) ?>" medium="image" />
<?php endif; ?>
      <description><?= rss_x($excerpt) ?></description>
      <content:encoded><![CDATA[<?php if ($imgUrl): ?><p><img src="<?= $imgUrl ?>" alt="<?= rss_x($p['title']) ?>" /></p><?php endif; ?><p><?= rss_x($excerpt) ?></p><p><a href="<?= $url ?>">Read the full post on Bat Cave Road Farm &#8594;</a></p>]]></content:encoded>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
