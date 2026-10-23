<?php
// blog/index.php - Farmstead Journal Archive Directory Index
require_once '../config.php';

$limit = 6; // posts per page
$page = max(1, (int)(filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1));
$offset = ($page - 1) * $limit;

$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? ''); // category slug

$params = [];
$conditions = ["bp.status = 'published'"];
if ($search !== '') {
    $conditions[] = "(bp.title LIKE ? OR bp.article LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($category !== '') {
    $conditions[] = "bp.id IN (SELECT bpc2.post_id FROM blog_post_categories bpc2 JOIN blog_categories bc2 ON bpc2.category_id = bc2.id WHERE bc2.slug = ?)";
    $params[] = $category;
}
$whereSql = "WHERE " . implode(" AND ", $conditions);

$listSql = "SELECT bp.*,
                   GROUP_CONCAT(bc.name ORDER BY bc.name SEPARATOR ', ') AS categories,
                   GROUP_CONCAT(bc.slug ORDER BY bc.name SEPARATOR ',')  AS category_slugs
            FROM blog_posts bp
            LEFT JOIN blog_post_categories bpc ON bp.id = bpc.post_id
            LEFT JOIN blog_categories bc       ON bpc.category_id = bc.id
            $whereSql
            GROUP BY bp.id
            ORDER BY COALESCE(bp.date_published, bp.created_at) DESC, bp.id DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT bp.id) FROM blog_posts bp $whereSql");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Category list for the filter dropdown (with published-post counts)
$blogCategories = $pdo->query(
    "SELECT bc.id, bc.name, bc.slug, COUNT(bpc.post_id) AS post_count
     FROM blog_categories bc
     LEFT JOIN blog_post_categories bpc ON bc.id = bpc.category_id
     LEFT JOIN blog_posts bp ON bpc.post_id = bp.id AND bp.status = 'published'
     GROUP BY bc.id
     ORDER BY bc.name ASC"
)->fetchAll();

$pageTitle = "The Farmstead Journal Blog | Bat Cave Road Farm";
$pageDescription = "Read updates directly from Bat Cave Road Farm. Farmstead logs covering sustainable agricultural habits, animal care, and life in Old Fort, NC.";
$canonicalUrl = "https://batcaveroadfarm.com/blog/";
$pageCSS      = "https://batcaveroadfarm.com/css/min.blog.css";
require_once '../includes/head.php';
require_once '../includes/header.php';

// CollectionPage + ItemList (reference-style) — list-page schema for the journal
$__li = []; $__n = 0;
foreach ($articles as $__a) {
    if (empty($__a['slug'])) continue;
    $__li[] = ['@type' => 'ListItem', 'position' => ++$__n,
        'url'  => 'https://batcaveroadfarm.com/blog/' . urlencode($__a['slug']) . '/',
        'name' => (string)($__a['title'] ?? '')];
}
if ($__li) {
    echo '<script type="application/ld+json">' . json_encode([
        '@context' => 'https://schema.org', '@type' => 'CollectionPage',
        '@id'      => 'https://batcaveroadfarm.com/blog/#collection',
        'url'      => 'https://batcaveroadfarm.com/blog/',
        'name'     => ($pageTitle ?? 'Blog — Bat Cave Road Farm'),
        'isPartOf' => ['@id' => 'https://batcaveroadfarm.com/#website'],
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($__li), 'itemListElement' => $__li],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
}
?>

<div class="hero hero-photo">
  <span class="hero-deco" aria-hidden="true">📖</span>
  <div class="hero-content">
    <h1>The Farmstead Journal</h1>
    <p>Chronicles of life, land, and animal husbandry in Western North Carolina</p>
  </div>
</div>
<div class="divider" aria-hidden="true">✦ ✦ ✦</div>

<div class="content-wrap">
  <form method="GET" class="journal-search reveal" role="search" aria-label="Search and filter journal">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search the journal…" aria-label="Search journal articles">
    <select name="category" aria-label="Filter by category">
      <option value="">All categories</option>
      <?php foreach ($blogCategories as $bcat): if ((int)$bcat['post_count'] === 0 && $category !== $bcat['slug']) continue; ?>
        <option value="<?= htmlspecialchars($bcat['slug']) ?>"<?= $category === $bcat['slug'] ? ' selected' : '' ?>><?= htmlspecialchars($bcat['name']) ?><?= (int)$bcat['post_count'] > 0 ? ' (' . (int)$bcat['post_count'] . ')' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="cta-btn">Search</button>
  </form>

  <div class="journal-grid">
    <?php if (empty($articles)): ?>
      <p class="journal-empty">No journal entries match your search or category just yet.</p>
    <?php endif; ?>
    <?php foreach ($articles as $art):
        $bUrl = '/blog/' . urlencode($art['slug']) . '/';
        $img  = !empty($art['feature_image']) ? $art['feature_image'] : (!empty($art['image_url']) ? $art['image_url'] : '');
        $when = !empty($art['date_published']) ? $art['date_published'] : ($art['created_at'] ?? '');
        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags((string)($art['article'] ?? ''))));
        $excerpt = mb_strlen($excerpt) > 180 ? mb_substr($excerpt, 0, 180) . '…' : $excerpt;
        $catNames = !empty($art['categories']) ? explode(', ', $art['categories']) : [];
        $catSlugs = !empty($art['category_slugs']) ? explode(',', $art['category_slugs']) : [];
    ?>
      <article class="journal-card reveal">
        <div>
          <?php if ($img !== ''): ?>
            <img class="thumb" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($art['title']) ?>" loading="lazy" decoding="async">
          <?php endif; ?>
          <div class="card-inner">
            <span class="journal-date"><?= $when ? date('F j, Y', strtotime($when)) : '' ?></span>
            <h2><a href="<?= $bUrl ?>"><?= htmlspecialchars($art['title']) ?></a></h2>
            <p class="excerpt"><?= htmlspecialchars($excerpt) ?></p>
            <?php if ($catNames): ?>
              <div class="journal-cats">
                <?php foreach ($catNames as $ci => $cn): $cs = $catSlugs[$ci] ?? ''; ?>
                  <a class="journal-cat" href="/blog/?category=<?= urlencode($cs) ?>"><?= htmlspecialchars($cn) ?></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="journal-card-foot">
          <span>✍️ <?= htmlspecialchars($art['author'] ?? '') ?></span>
          <a class="read" href="<?= $bUrl ?>">Read entry →</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php
    $pagerClass = 'journal-pager';
    $pagerLabel = 'Journal pages';
    $pagerUrl = function (int $p) use ($search, $category): string {
        return '/blog/?page=' . $p
            . ($search   !== '' ? '&search='   . urlencode($search)   : '')
            . ($category !== '' ? '&category=' . urlencode($category) : '');
    };
    require __DIR__ . '/../includes/pager.php';
  ?>
</div>

<?php require_once '../includes/footer.php'; ?>
