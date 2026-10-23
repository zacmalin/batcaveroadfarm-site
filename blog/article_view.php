<?php
ob_start();
// blog/article_view.php - Public Article Reader Core Interface
require_once '../config.php';

$slug = trim($_GET['slug'] ?? '');
if (empty($slug)) { http_response_code(404); ob_clean(); require __DIR__ . '/../404.php'; exit; }

$stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published'");
$stmt->execute([$slug]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$post) { http_response_code(404); ob_clean(); require __DIR__ . '/../404.php'; exit; }

// Categories assigned to this post
$catStmt = $pdo->prepare("SELECT bc.name, bc.slug FROM blog_categories bc JOIN blog_post_categories bpc ON bc.id = bpc.category_id WHERE bpc.post_id = ? ORDER BY bc.name");
$catStmt->execute([$post['id']]);
$postCategories = $catStmt->fetchAll();

$featImg  = !empty($post['feature_image']) ? $post['feature_image'] : (!empty($post['image_url']) ? $post['image_url'] : '');
$postWhen = !empty($post['date_published']) ? $post['date_published'] : ($post['created_at'] ?? '');

// Use the post's feature image for social sharing (arbitrary size -> skip the fixed 1200x630 hint)
if ($featImg !== '') {
    $ogImage = preg_match('#^https?://#', $featImg) ? $featImg : 'https://batcaveroadfarm.com' . ($featImg[0] === '/' ? '' : '/') . $featImg;
    $ogImageNoSize = true;
}
$pageTitle = $post['title'] . " | The Farmstead Journal";
$pageDescription = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$post['article']))), 0, 155) . "…";
$canonicalUrl = "https://batcaveroadfarm.com/blog/" . $post['slug'] . "/";
$pageCSS      = "https://batcaveroadfarm.com/css/min.blog.css";
$pageJS       = "https://batcaveroadfarm.com/js/min.share.js";
$breadcrumbs = [
    ['label' => 'The Journal', 'url' => '/blog/'],
    ['label' => $post['title']],
];
require_once '../includes/head.php';
require_once '../includes/header.php';
?>
<?php
$__img = $ogImage ?? '';
$__articleSchema = array_filter([
    "@context" => "https://schema.org",
    "@type" => "BlogPosting",
    "headline" => mb_substr($post['title'], 0, 110),
    "url" => $canonicalUrl,
    "mainEntityOfPage" => ["@type" => "WebPage", "@id" => $canonicalUrl],
    "image" => $__img,
    "author" => ["@type" => "Person", "name" => $post['author'] ?: "Bat Cave Road Farm"],
    "description" => mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$post['article']))), 0, 160),
    "publisher" => ["@id" => "https://batcaveroadfarm.com/#business"],
], fn($v) => $v !== null && $v !== '' && $v !== []);
if (!empty($post['date_published'])) { $__articleSchema['datePublished'] = date('c', strtotime($post['date_published'])); }
echo '<script type="application/ld+json">' . json_encode($__articleSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>';

// ---- Share controls (Web Share API + Facebook / X / email / copy) ----
$svg = [
  'share' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg>',
  'fb' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.49 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.49h-2.8V24C19.62 23.1 24 18.1 24 12.07z"/></svg>',
  'x' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.66l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.45-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117l11.966 15.644z"/></svg>',
  'mail' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
  'link' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>',
];
$renderShare = function ($variant) use ($canonicalUrl, $post, $svg) {
    $u    = rawurlencode($canonicalUrl);
    $t    = rawurlencode($post['title']);
    $body = rawurlencode($post['title'] . ' — ' . $canonicalUrl);
    $hu   = htmlspecialchars($canonicalUrl);
    $ht   = htmlspecialchars($post['title']);
    $label = $variant === 'full' ? '<span class="share-label">Share this post</span>' : '';
    return '<div class="share-bar share-bar--' . $variant . '" data-share-url="' . $hu . '" data-share-title="' . $ht . '" role="group" aria-label="Share this article">'
        . $label
        . '<button type="button" class="share-btn share-native" data-share-native hidden aria-label="Share">' . $svg['share'] . '</button>'
        . '<a class="share-btn share-fb" href="https://www.facebook.com/sharer/sharer.php?u=' . $u . '" target="_blank" rel="noopener" data-share="facebook" aria-label="Share on Facebook">' . $svg['fb'] . '</a>'
        . '<a class="share-btn share-x" href="https://twitter.com/intent/tweet?url=' . $u . '&amp;text=' . $t . '" target="_blank" rel="noopener" data-share="x" aria-label="Share on X">' . $svg['x'] . '</a>'
        . '<a class="share-btn share-email" href="mailto:?subject=' . $t . '&amp;body=' . $body . '" data-share="email" aria-label="Share by email">' . $svg['mail'] . '</a>'
        . '<button type="button" class="share-btn share-copy" data-share-copy data-share="copy" aria-label="Copy link">' . $svg['link'] . '</button>'
        . '</div>';
};
?>


<div class="content-wrap">
  <article class="article-reader reveal">
    <header>
      <h1><?= htmlspecialchars($post['title']) ?></h1>
      <div class="article-meta">
        <span>📅 <?= $postWhen ? date('F j, Y', strtotime($postWhen)) : '' ?></span>
        <?php if (!empty($post['author'])): ?><span>✍️ <?= htmlspecialchars($post['author']) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($postCategories)): ?>
        <div class="article-cats">
          <?php foreach ($postCategories as $pc): ?>
            <a class="article-cat" href="/blog/?category=<?= urlencode($pc['slug']) ?>"><?= htmlspecialchars($pc['name']) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?= $renderShare('compact') ?>
    </header>

    <?php if ($featImg !== ''): ?>
      <img class="article-feature" src="<?= htmlspecialchars($featImg) ?>" alt="<?= htmlspecialchars($post['title']) ?>" fetchpriority="high" decoding="async">
    <?php endif; ?>

    <div class="article-content"><?= preg_replace(['/<h1(\b[^>]*)>/i', '#</h1>#i'], ['<h2$1>', '</h2>'], (string)$post['article']) ?></div>

    <?= $renderShare('full') ?>

    <a class="article-back cta-btn" href="/blog/">← Back to the Journal</a>
  </article>
</div>

<?php
require_once '../includes/related.php';
$__kw = bcrf_rel_keywords((string)($post['title'] ?? ''), array_column($postCategories ?? [], 'name'));
$__cards = [];
foreach (bcrf_related_recipes($pdo, $__kw, 2) as $__c) { $__c['kind'] = 'Recipe'; $__cards[] = $__c; }
foreach (bcrf_related_products($pdo, $__kw, 1) as $__c) { $__c['kind'] = 'Farm stand'; $__cards[] = $__c; }
$__cards[] = bcrf_visit_card();
echo bcrf_related_render('More from the farm', $__cards);
?>
<?php require_once '../includes/footer.php'; ?>
