<?php
require_once '../config.php';
require_once '../includes/ingredient_match.php';
$recipeIngredientIndex = bcrf_recipe_ingredient_index($pdo);

// Pull every active item, ordered so we can group by category.
// Only the farm-stand categories belong here (the inventory table also
// holds thrift + other items). Keep this list in sync with the index page.
$search = trim($_GET['search'] ?? '');
$catMap = ['produce' => ['produce'], 'bakery' => ['bakery', 'baked-goods', 'baked goods'], 'pantry' => ['pantry']];
$category = strtolower(trim($_GET['category'] ?? ''));
if (!isset($catMap[$category])) { $category = ''; }

$farmKeys = ['produce', 'bakery', 'baked-goods', 'baked goods', 'pantry'];
$ph = implode(',', array_fill(0, count($farmKeys), '?'));
$params = array_merge($farmKeys, $farmKeys);
$searchSql = '';
if ($search !== '') {
    $searchSql = " AND (i.name LIKE ? OR i.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
try {
    $stmt = $pdo->prepare(
        "SELECT i.*, ic.id AS cat_id, ic.name AS cat_name, ic.slug AS cat_slug,
                ic.icon AS cat_icon, ic.sort_order AS cat_sort
         FROM inventory i
         JOIN inventory_categories ic ON i.category_id = ic.id
         WHERE i.is_active = 1
           AND (LOWER(ic.slug) IN ($ph) OR LOWER(ic.name) IN ($ph))$searchSql
         ORDER BY ic.sort_order ASC, ic.name ASC, i.name ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = [];
}

// Per-category counts (active items, independent of search) for the filter dropdown.
$catCounts = ['produce' => 0, 'bakery' => 0, 'pantry' => 0];
try {
    $ccStmt = $pdo->prepare(
        "SELECT LOWER(ic.slug) AS s, LOWER(ic.name) AS n, COUNT(*) AS cnt
         FROM inventory i
         JOIN inventory_categories ic ON i.category_id = ic.id
         WHERE i.is_active = 1
           AND (LOWER(ic.slug) IN ($ph) OR LOWER(ic.name) IN ($ph))
         GROUP BY ic.id"
    );
    $ccStmt->execute(array_merge($farmKeys, $farmKeys));
    foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $rc) {
        foreach ($catMap as $ckey => $aliases) {
            if (in_array($rc['s'], $aliases, true) || in_array($rc['n'], $aliases, true)) { $catCounts[$ckey] += (int)$rc['cnt']; break; }
        }
    }
} catch (Throwable $e) { /* counts stay zero */ }

// Group items under their category.
$groups = [];
foreach ($rows as $r) {
    $cid = $r['cat_id'];
    if (!isset($groups[$cid])) {
        $groups[$cid] = [
            'name' => $r['cat_name'],
            'icon' => $r['cat_icon'],
            'slug' => $r['cat_slug'],
            'items' => [],
        ];
    }
    $groups[$cid]['items'][] = $r;
}

// Category filter (produce / bakery / pantry) applied after grouping.
if ($category !== '') {
    $aliases = $catMap[$category];
    $groups = array_filter($groups, function ($g) use ($aliases) {
        return in_array(strtolower((string)$g['slug']), $aliases, true)
            || in_array(strtolower((string)$g['name']), $aliases, true);
    });
}

// Pre-compute which items map to recipes, so the legend shows only when relevant.
$recipeMatches = [];
$anyRecipeMatch = false;
foreach ($groups as $g) {
    foreach ($g['items'] as $gi) {
        $m = bcrf_item_recipe_match((string)($gi['name'] ?? ''), $recipeIngredientIndex);
        if ($m) { $recipeMatches[(int)$gi['id']] = $m; $anyRecipeMatch = true; }
    }
}

$pageTitle       = "Farm Stand Inventory | Bat Cave Road Farm";
$pageDescription = "See what's on the shelves today at our Old Fort, NC farm stand — fresh produce, baked goods, and pantry provisions, organized by category.";
$pageCSS         = "https://batcaveroadfarm.com/css/min.farmstand.css";
$canonicalUrl    = "https://batcaveroadfarm.com/farm-stand/inventory.php";
require_once '../includes/head.php';
require_once '../includes/header.php';
?>
<div class="content-wrap">
  <div class="stand-head">
    <h1>What's on Our Shelves</h1>
  </div>

  <form method="GET" class="stand-search reveal" role="search" aria-label="Search the farm stand">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search the stand…" aria-label="Search farm stand items">
    <select name="category" aria-label="Filter by category">
      <option value="">All categories</option>
      <?php foreach (['produce' => 'Produce', 'bakery' => 'Bakery', 'pantry' => 'Pantry'] as $ckey => $clabel):
          $ccnt = $catCounts[$ckey] ?? 0;
          if ($ccnt === 0 && $category !== $ckey) continue;
      ?>
        <option value="<?= $ckey ?>"<?= $category === $ckey ? ' selected' : '' ?>><?= $clabel ?> (<?= $ccnt ?>)</option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="cta-btn">Search</button>
  </form>

  <?php if ($search !== '' || $category !== ''): $resultTotal = 0; foreach ($groups as $g) { $resultTotal += count($g['items']); } ?>
    <p class="stand-results-note"><?= $resultTotal ?> <?= $resultTotal === 1 ? 'item' : 'items' ?><?= $search !== '' ? ' matching “' . htmlspecialchars($search) . '”' : '' ?><?= $category !== '' ? ' in ' . htmlspecialchars(ucfirst($category)) : '' ?></p>
  <?php endif; ?>

  <?php if ($anyRecipeMatch): ?>
    <p class="stand-recipe-legend"><span class="srl-icon" aria-hidden="true"><?= bcrf_recipe_icon() ?></span> The <strong>fork &amp; knife</strong> badge marks items used in our <a href="/recipes/">recipes</a> &mdash; tap a badge to see them.</p>
  <?php endif; ?>

  <?php if (empty($groups)): ?>
    <?php if ($search !== '' || $category !== ''): ?>
      <p class="stand-empty">No items match your search. Try a different term, or browse all categories.</p>
    <?php else: ?>
      <p class="stand-empty">Nothing on the shelves right now — check back soon!</p>
    <?php endif; ?>
  <?php else: ?>
    <?php foreach ($groups as $g):
        $catIcon = trim((string)($g['icon'] ?? ''));
        $catAnchor = createSlug((string)($g['slug'] ?? '')) ?: createSlug((string)$g['name']);
    ?>
      <section class="cat-section" id="cat-<?= htmlspecialchars($catAnchor) ?>">
        <h2><?php if ($catIcon !== ''): ?><span aria-hidden="true"><?= htmlspecialchars($catIcon) ?></span> <?php endif; ?><?= htmlspecialchars($g['name']) ?></h2>
        <div class="stand-grid">
          <?php foreach ($g['items'] as $item):
              $qty   = (float)($item['stock_quantity'] ?? 0);
              $icon  = trim((string)($item['icon'] ?? ''));
              $img   = trim((string)($item['image_url'] ?? ''));
              if ($img !== '' && !preg_match('#^https?://#i', $img) && $img[0] !== '/') { $img = '/' . $img; }
              $price = ($item['price'] !== null && $item['price'] !== '')
                       ? '$' . number_format((float)$item['price'], 2) : '';
              $qtyLabel = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
              $href = '/farm-stand/items/' . (int)$item['id'] . '/' . (createSlug((string)($item['name'] ?? '')) ?: 'item') . '/';
              $recipeMatch = $recipeMatches[(int)$item['id']] ?? null;
          ?>
            <div class="stand-cell">
            <a class="stand-card reveal" href="<?= htmlspecialchars($href) ?>">
              <div>
                <?php if ($img !== ''): ?>
                  <img class="thumb" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($item['name']) ?>" loading="lazy" decoding="async">
                <?php endif; ?>
                <div class="body">
                  <div class="title-row">
                    <?php if ($icon !== ''): ?><span class="ic" aria-hidden="true"><?= htmlspecialchars($icon) ?></span><?php endif; ?>
                    <h3><?= htmlspecialchars($item['name']) ?></h3>
                  </div>
                  <?php if (!empty($item['description'])): ?>
                    <p class="desc"><?= htmlspecialchars(strip_tags($item['description'])) ?></p>
                  <?php endif; ?>
                  <?php if ($price !== ''): ?><p class="item-price"><?= $price ?></p><?php endif; ?>
                  <?php if ($qty <= 0): ?>
                    <span class="stock stock-out">Out of stock — back soon</span>
                  <?php elseif ($qty < 5): ?>
                    <span class="stock stock-low">Only <?= $qtyLabel ?> left at stand</span>
                  <?php else: ?>
                    <span class="stock stock-in">In stock</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="foot">
                <span class="view">View item →</span>
              </div>
            </a>
            <?php if ($recipeMatch): ?><a class="stand-recipe-badge" href="/recipes/?ingredient=<?= (int)$recipeMatch['id'] ?>&amp;from=inventory&amp;i=<?= (int)$item['id'] ?>&amp;c=<?= htmlspecialchars($catAnchor, ENT_QUOTES) ?>" title="Used in <?= (int)$recipeMatch['recipe_count'] ?> recipe<?= (int)$recipeMatch['recipe_count'] === 1 ? '' : 's' ?>" aria-label="See <?= (int)$recipeMatch['recipe_count'] ?> recipe<?= (int)$recipeMatch['recipe_count'] === 1 ? '' : 's' ?> using <?= htmlspecialchars($item['name']) ?>"><?= bcrf_recipe_icon() ?><span><?= (int)$recipeMatch['recipe_count'] ?></span></a><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
