<?php
// recipes/index.php - Relational Recipes Directory Index Hub
require_once '../config.php';
require_once '../includes/ratings.php';
require_once '../includes/img_dims.php';

$limit = 8;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$offset = ($page - 1) * $limit;

$search = trim($_GET['search'] ?? '');
$category_id = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT) ?: 0;
$cuisine = trim($_GET['cuisine'] ?? '');
$ingredient_id = filter_input(INPUT_GET, 'ingredient', FILTER_VALIDATE_INT) ?: 0;
$ingredientName = '';
if ($ingredient_id) {
    try { $ingStmt = $pdo->prepare("SELECT singular_name FROM ingredients WHERE id = ?"); $ingStmt->execute([$ingredient_id]); $ingredientName = (string)($ingStmt->fetchColumn() ?: ''); }
    catch (Throwable $e) { $ingredientName = ''; }
    if ($ingredientName === '') { $ingredient_id = 0; }
}

// Context "back to ..." when arriving from a farm-stand recipe indicator.
// from=stand     -> came from a single item page  -> back to that item, labelled by item
// from=inventory -> came from the inventory list  -> back to the category anchor, labelled by category
$backCtx = '';
$bFrom = $_GET['from'] ?? '';
if ($bFrom === 'stand' || $bFrom === 'inventory') {
    $iId = filter_input(INPUT_GET, 'i', FILTER_VALIDATE_INT) ?: 0;
    if ($iId) {
        try {
            $bt = $pdo->prepare("SELECT i.name AS item_name, ic.name AS cat_name FROM inventory i LEFT JOIN inventory_categories ic ON i.category_id = ic.id WHERE i.id = ? LIMIT 1");
            $bt->execute([$iId]);
            $brow = $bt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $brow = []; }
        $iName = (string)($brow['item_name'] ?? '');
        $cName = (string)($brow['cat_name'] ?? '');
        if ($iName !== '') {
            if ($bFrom === 'inventory') {
                $cAnchor = preg_replace('/[^a-z0-9\-]/i', '', (string)($_GET['c'] ?? ''));
                $bUrl = '/farm-stand/inventory.php' . ($cAnchor !== '' ? '#cat-' . $cAnchor : '');
                $bLabel = $cName !== '' ? $cName : 'Farm Stand';
            } else {
                $iSlug = preg_replace('/[^a-z0-9\-]/i', '', (string)($_GET['s'] ?? '')) ?: 'item';
                $bUrl = '/farm-stand/items/' . $iId . '/' . $iSlug . '/';
                $bLabel = $iName;
            }
            $backCtx = '<a class="context-back" href="' . htmlspecialchars($bUrl, ENT_QUOTES) . '"><span class="cb-arrow" aria-hidden="true">&larr;</span> Back to ' . htmlspecialchars($bLabel) . '</a>';
        }
    }
}

$params = [];
$queryStr = "SELECT DISTINCT r.*, c.name AS cuisine, (SELECT ROUND(AVG(rr.rating),1) FROM recipe_ratings rr WHERE rr.recipe_id = r.id) AS avg_rating, (SELECT COUNT(*) FROM recipe_ratings rr2 WHERE rr2.recipe_id = r.id) AS rating_count FROM recipes r LEFT JOIN cuisines c ON r.cuisine_id = c.id ";

// Relational multi-category structural joins mapping execution boundaries
if ($category_id) {
    $queryStr .= " JOIN recipe_categories_junction rcj ON r.id = rcj.recipe_id ";
}
if ($search) {
    $queryStr .= " LEFT JOIN recipe_ingredients ri ON r.id = ri.recipe_id 
                   LEFT JOIN ingredients i ON ri.ingredient_id = i.id ";
}
if ($ingredient_id) {
    $queryStr .= " JOIN recipe_ingredients rim ON r.id = rim.recipe_id ";
}
// cuisines is LEFT JOINed in the base query (used for display + the c.name filter)

$conditions = ["r.status = 'published'"];
if ($category_id) {
    $conditions[] = "rcj.category_id = ?";
    $params[] = $category_id;
}
if ($search) {
    $conditions[] = "(r.title LIKE ? OR r.description LIKE ? OR i.singular_name LIKE ? OR i.plural_name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($cuisine) {
    $conditions[] = "c.name = ?";
    $params[] = $cuisine;
}
if ($ingredient_id) {
    $conditions[] = "rim.ingredient_id = ?";
    $params[] = $ingredient_id;
}

if (!empty($conditions)) { 
    $queryStr .= " WHERE " . implode(" AND ", $conditions); 
}

// Order strictly by the new relational publishing date field parameter
$queryStr .= " ORDER BY r.date_published DESC, r.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

$stmt = $pdo->prepare($queryStr);
$stmt->execute($params);
$recipes = $stmt->fetchAll();

// Construct matching total rows volume calculation profiles securely
$countStr = "SELECT COUNT(DISTINCT r.id) FROM recipes r";
if ($category_id) { $countStr .= " JOIN recipe_categories_junction rcj ON r.id = rcj.recipe_id"; }
if ($search) { $countStr .= " LEFT JOIN recipe_ingredients ri ON r.id = ri.recipe_id LEFT JOIN ingredients i ON ri.ingredient_id = i.id"; }
if ($ingredient_id) { $countStr .= " JOIN recipe_ingredients rim ON r.id = rim.recipe_id"; }
if ($cuisine) { $countStr .= " JOIN cuisines c ON r.cuisine_id = c.id"; }
if (!empty($conditions)) { $countStr .= " WHERE " . implode(" AND ", $conditions); }

$countStmt = $pdo->prepare($countStr);
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Gather categories lookup list arrays to populate selector components
$categories = $pdo->query("SELECT c.id, c.category_name, COUNT(DISTINCT rcj.recipe_id) AS recipe_count
    FROM categories c
    LEFT JOIN recipe_categories_junction rcj ON c.id = rcj.category_id
    GROUP BY c.id
    ORDER BY c.category_name ASC")->fetchAll();

$pageTitle = "Farmhouse Cookbook — Country Recipes | Bat Cave Road Farm";
$pageDescription = "Discover home-grown kitchen preparation methods, baking guides, and seasonal canning recipes designed around fresh local ingredients.";
$canonicalUrl = "https://batcaveroadfarm.com/recipes/";
$pageCSS      = "https://batcaveroadfarm.com/css/min.recipes.css";
require_once '../includes/head.php';
require_once '../includes/header.php';

// CollectionPage + ItemList (reference-style) — list-page schema, recipe-carousel eligible
$__li = []; $__n = 0;
foreach ($recipes as $__r) {
    if (empty($__r['slug'])) continue;
    $__li[] = ['@type' => 'ListItem', 'position' => ++$__n,
        'url'  => 'https://batcaveroadfarm.com/recipes/' . urlencode($__r['slug']) . '/',
        'name' => (string)($__r['title'] ?? '')];
}
if ($__li) {
    echo '<script type="application/ld+json">' . json_encode([
        '@context' => 'https://schema.org', '@type' => 'CollectionPage',
        '@id'      => 'https://batcaveroadfarm.com/recipes/#collection',
        'url'      => 'https://batcaveroadfarm.com/recipes/',
        'name'     => ($pageTitle ?? 'Recipes — Bat Cave Road Farm'),
        'isPartOf' => ['@id' => 'https://batcaveroadfarm.com/#website'],
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($__li), 'itemListElement' => $__li],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
}
?>

<div class="hero hero-photo">
  <span class="hero-deco" aria-hidden="true">🍳</span>
  <div class="hero-content">
    <h1>Farmhouse Cookbook</h1>
    <p>Sourced from our fields to the homestead kitchen hearth</p>
  </div>
</div>
<div class="divider" aria-hidden="true">✦ ✦ ✦</div>

<div class="content-wrap">
  <?= $backCtx ?>
  <form method="GET" class="recipe-filter reveal" role="search" aria-label="Search recipes">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by recipe title or ingredient…" aria-label="Search recipes">
    <select name="category" aria-label="Filter by category">
      <option value="">All Categories</option>
      <?php foreach ($categories as $cat): if ((int)$cat['recipe_count'] === 0 && $category_id != $cat['id']) continue; ?>
        <option value="<?= $cat['id'] ?>" <?= $category_id == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category_name']) ?><?= (int)$cat['recipe_count'] > 0 ? ' (' . (int)$cat['recipe_count'] . ')' : '' ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($cuisine !== ''): ?><input type="hidden" name="cuisine" value="<?= htmlspecialchars($cuisine) ?>"><?php endif; ?>
    <?php if ($ingredient_id): ?><input type="hidden" name="ingredient" value="<?= (int)$ingredient_id ?>"><?php endif; ?>
    <button type="submit" class="cta-btn">Search</button>
  </form>
  <?php
    $__filterTerm = ''; $__filterPre = 'Showing '; $__filterPost = ' recipes';
    if ($cuisine !== '') { $__filterTerm = $cuisine; }
    elseif ($category_id) { foreach ($categories as $c) { if ((int)$c['id'] === $category_id) { $__filterTerm = $c['category_name']; break; } } }
    elseif ($ingredient_id) { $__filterTerm = $ingredientName; $__filterPre = 'Showing recipes with '; $__filterPost = ''; }
    elseif ($search !== '') { $__filterTerm = $search; $__filterPre = 'Showing matches for '; $__filterPost = ''; }
  ?>
  <?php if ($__filterTerm !== ''): ?><p class="recipe-active-filter"><?= $__filterPre ?><strong><?= htmlspecialchars($__filterTerm) ?></strong><?= $__filterPost ?> · <a href="/recipes/">clear</a></p><?php endif; ?>

  <div class="recipe-grid">
    <?php if (empty($recipes)): ?>
      <p class="recipe-empty">No farmhouse recipes match your filter just yet — try a different ingredient or category.</p>
    <?php endif; ?>
    <?php foreach ($recipes as $rec):
        $badge_stmt = $pdo->prepare("SELECT c.id, c.category_name FROM categories c JOIN recipe_categories_junction j ON c.id=j.category_id WHERE j.recipe_id = ? LIMIT 2");
        $badge_stmt->execute([$rec['id']]);
        $badges = $badge_stmt->fetchAll(PDO::FETCH_ASSOC);
        $rUrl = '/recipes/' . urlencode($rec['slug']) . '/';
        $cImg = '';
        foreach (['feature_image','image_url','featured_image','image','photo_url'] as $ik) { if (!empty($rec[$ik])) { $cImg = $rec[$ik]; break; } }
    ?>
      <article class="recipe-card reveal">
        <div>
          <div class="recipe-card-head">
            <?php if ($cImg !== ''): ?>
              <img class="recipe-thumb" src="<?= htmlspecialchars($cImg) ?>"<?= bcrf_img_attrs($cImg) ?> alt="<?= htmlspecialchars($rec['title']) ?>" loading="lazy" decoding="async">
            <?php endif; ?>
            <?php $rCuisine = trim((string)($rec['cuisine'] ?? '')); if ($rCuisine !== '' || $badges): ?><div class="recipe-chips"><?php if ($rCuisine !== ''): ?><a class="recipe-chip recipe-chip--cuisine" href="/recipes/?cuisine=<?= urlencode($rCuisine) ?>"><?= htmlspecialchars($rCuisine) ?></a><?php endif; ?><?php foreach ($badges as $b): ?><a class="recipe-chip" href="/recipes/?category=<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['category_name']) ?></a><?php endforeach; ?></div><?php endif; ?>
          </div>
          <h2 class="recipe-card-title"><a href="<?= $rUrl ?>"><?= htmlspecialchars($rec['title']) ?></a></h2>
          <?php $rsr = bcrf_rating_static_html((float)($rec['avg_rating'] ?? 0), (int)($rec['rating_count'] ?? 0)); if ($rsr !== ''): ?><div class="recipe-card-rate"><?= $rsr ?></div><?php endif; ?>
          <p class="desc"><?= htmlspecialchars(strip_tags((string)($rec['description'] ?? ''))) ?></p>
        </div>
        <div class="recipe-card-foot">
          <span>⏱️ <?= (int)($rec['prep_time'] ?? 0) + (int)($rec['cook_time'] ?? 0) ?> mins</span>
          <a class="view" href="<?= $rUrl ?>">View Card →</a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php
    $pagerClass = 'recipe-pager';
    $pagerLabel = 'Recipe pages';
    $pagerUrl = function (int $p) use ($search, $category_id, $cuisine, $ingredient_id): string {
        return '/recipes/?page=' . $p
            . ($search !== ''  ? '&search='     . urlencode($search)  : '')
            . ($category_id    ? '&category='   . $category_id        : '')
            . ($cuisine !== '' ? '&cuisine='    . urlencode($cuisine) : '')
            . ($ingredient_id  ? '&ingredient=' . $ingredient_id      : '');
    };
    require __DIR__ . '/../includes/pager.php';
  ?>
</div>

<?php require_once '../includes/footer.php'; ?>
