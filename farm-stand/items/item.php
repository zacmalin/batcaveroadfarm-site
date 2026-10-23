<?php
require_once '../../config.php';
require_once '../../includes/ingredient_match.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$item = false;
if ($id) {
    $st = $pdo->prepare("SELECT i.id, i.name, i.description, i.price, i.image_url, i.stock_quantity, i.sku,
            i.size_value, u.name AS size_unit,
            ic.name AS category, ic.slug AS category_slug, ic.icon AS category_icon
        FROM inventory i
        LEFT JOIN inventory_categories ic ON i.category_id = ic.id
        LEFT JOIN measurement_units u     ON i.size_unit_id = u.id
        WHERE i.id = ? AND i.is_active = 1");
    $st->execute([$id]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$item) {
    http_response_code(404);
    $pageTitle    = "Item Not Found - Bat Cave Road Farm";
    $metaDesc     = "This farm stand item is no longer available.";
    $canonicalUrl = "https://batcaveroadfarm.com/farm-stand/";
    $pageCSS      = "https://batcaveroadfarm.com/css/min.farmstand.css";
    require_once '../../includes/head.php';
    require_once '../../includes/header.php';
    ?>
    <div class="content-wrap">
      <div class="item-detail" style="padding:34px">
        <h1 style="font-family:'Rye',serif;color:var(--brown-dark)">Not on the shelves right now</h1>
        <p style="font-family:'Lora',serif;color:#3f2a14">Our stand changes with the harvest, so this item may be out for now. Come see what's fresh today — <a href="/farm-stand/inventory.php" style="color:var(--red-barn);font-weight:700">browse the farm stand →</a></p>
      </div>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

$itemId  = (int)($item['id'] ?? $id);
$name    = (string)($item['name'] ?? '');
$price   = $item['price'] ?? null;
$qty     = (float)($item['stock_quantity'] ?? 0);
$inStock = $qty > 0;
$qtyLabel = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
$sizeStr = '';
if (!empty($item['size_value'])) {
    $sizeStr = trim($item['size_value'] . ' ' . ($item['size_unit'] ?? ''));
}
// description may be plain text or admin-authored HTML — render HTML if tags are present, else preserve line breaks
$descRaw = (string)($item['description'] ?? '');
$descOut = ($descRaw !== strip_tags($descRaw)) ? $descRaw : nl2br(htmlspecialchars($descRaw));
$img = trim((string)($item['image_url'] ?? ''));
if ($img !== '' && !preg_match('#^https?://#i', $img) && $img[0] !== '/') { $img = '/' . $img; }

$catName = $item['category'] ?? 'Farm Stand';
$catSlug = createSlug((string)($item['category_slug'] ?? '')) ?: createSlug($catName);

// Map the item's category to the inventory filter key (bakery also covers baked-goods).
$standCatMap = ['produce' => ['produce'], 'bakery' => ['bakery', 'baked-goods', 'baked goods'], 'pantry' => ['pantry']];
$catFilterKey = '';
$catNeedle = strtolower(trim((string)($item['category_slug'] ?? '')));
$catNameNeedle = strtolower(trim((string)$catName));
foreach ($standCatMap as $__k => $__aliases) {
    if ($__k === $catNeedle || $__k === $catNameNeedle || in_array($catNeedle, $__aliases, true) || in_array($catNameNeedle, $__aliases, true)) { $catFilterKey = $__k; break; }
}
$catUrl = $catFilterKey !== '' ? '/farm-stand/inventory.php?category=' . $catFilterKey : '/farm-stand/inventory.php';
// Arrived from the petting zoo? Point the "Zoo" category links back there, not the stand.
$fromZoo = (($_GET['from'] ?? '') === 'zoo');
if ($fromZoo) { $catUrl = '/petting-zoo/'; }

$recipeIngredientIndex = bcrf_recipe_ingredient_index($pdo);
$recipeMatch = bcrf_item_recipe_match((string)($item['name'] ?? ''), $recipeIngredientIndex);

$pageTitle    = $name . " - Farm Stand - Bat Cave Road Farm";
$metaDesc     = mb_strimwidth(trim(strip_tags($descRaw)) !== '' ? strip_tags($descRaw) : $name, 0, 155, '…');
$itemSlug     = createSlug($name) ?: 'item';
$canonicalUrl = "https://batcaveroadfarm.com/farm-stand/items/" . (int)$itemId . "/" . $itemSlug . "/";
$breadcrumbs = [['label' => 'Farm Stand', 'url' => '/farm-stand/']];
if (!empty($catName)) { $breadcrumbs[] = ['label' => $catName, 'url' => $fromZoo ? '/petting-zoo/' : '/farm-stand/inventory.php#cat-' . $catSlug]; }
$breadcrumbs[] = ['label' => $name];

// Context "back to {recipe}" when arriving from a recipe's in-stock icon
$backCtx = '';
if (($_GET['from'] ?? '') === 'recipe' && !empty($_GET['r'])) {
    $rSlug = preg_replace('/[^a-z0-9\-]/i', '', (string)$_GET['r']);
    if ($rSlug !== '') {
        try { $bt = $pdo->prepare("SELECT title FROM recipes WHERE slug = ? AND status = 'published' LIMIT 1"); $bt->execute([$rSlug]); $rTitle = (string)($bt->fetchColumn() ?: ''); }
        catch (Throwable $e) { $rTitle = ''; }
        if ($rTitle !== '') {
            $backCtx = '<a class="context-back" href="/recipes/' . htmlspecialchars($rSlug, ENT_QUOTES) . '/"><span class="cb-arrow" aria-hidden="true">&larr;</span> Back to ' . htmlspecialchars($rTitle) . '</a>';
        }
    }
} elseif (($_GET['from'] ?? '') === 'zoo') {
    $backCtx = '<a class="context-back" href="/petting-zoo/"><span class="cb-arrow" aria-hidden="true">&larr;</span> Back to the Petting Zoo</a>';
}
$ogImage      = !empty($item['image_url']) ? $item['image_url'] : "https://batcaveroadfarm.com/img/og-farmstand.jpg";
$pageCSS      = "https://batcaveroadfarm.com/css/min.farmstand.css";
if ($img !== '') { $preloadImage = $img; }
$pageJS       = "https://batcaveroadfarm.com/js/min.lightbox.js";

require_once '../../includes/head.php';
require_once '../../includes/header.php';
?>
<?php
// ── Product structured data (Google rich results) ──────────────────────────
// Only output when there's a real price: Google rejects Product offers without one.
if ($price !== null && $price !== '' && is_numeric($price) && (float)$price > 0) {
    $ldImg = $img !== '' ? $img : '/img/og-farmstand.jpg';
    if (!preg_match('#^https?://#i', $ldImg)) { $ldImg = 'https://batcaveroadfarm.com' . $ldImg; }
    $ldProduct = [
        '@context'    => 'https://schema.org',
        '@type'       => 'Product',
        '@id'         => $canonicalUrl . '#product',
        'name'        => $name,
        'description' => trim(strip_tags($descRaw)) !== '' ? mb_strimwidth(trim(strip_tags($descRaw)), 0, 5000, '…') : $name . ' from the Bat Cave Road Farm farm stand in Old Fort, NC.',
        'image'       => [$ldImg],
        'brand'       => ['@type' => 'Brand', 'name' => 'Bat Cave Road Farm'],
        'category'    => (string)$catName,
        'url'         => $canonicalUrl,
        'offers'      => [
            '@type'         => 'Offer',
            'url'           => $canonicalUrl,
            'price'         => number_format((float)$price, 2, '.', ''),
            'priceCurrency' => 'USD',
            'availability'  => $inStock ? 'https://schema.org/InStoreOnly' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'seller'        => ['@id' => 'https://batcaveroadfarm.com/#business'],
        ],
    ];
    if (!empty($item['sku'])) { $ldProduct['sku'] = (string)$item['sku']; }
    echo '<script type="application/ld+json">' . json_encode($ldProduct, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "</script>\n";
}
?>

<div class="content-wrap">
  <?= $backCtx ?>
  <div class="item-detail reveal">
    <div class="item-grid">
      <?php if ($img !== ''): ?>
        <img class="item-photo img-zoom" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($name . ' — Bat Cave Road Farm farm stand in Old Fort, NC') ?>" fetchpriority="high" decoding="async">
      <?php endif; ?>
      <div class="item-info">
        <a class="item-pill" href="<?= htmlspecialchars($catUrl) ?>"><?= htmlspecialchars($catName) ?></a>
        <h1><?= htmlspecialchars($name) ?></h1>
        <?php if ($price !== null && $price !== ''): ?>
          <p class="item-price">$<?= number_format((float)$price, 2) ?><?php if ($sizeStr !== ''): ?> <span class="item-per">/ <?= htmlspecialchars($sizeStr) ?></span><?php endif; ?></p>
        <?php elseif ($sizeStr !== ''): ?>
          <p class="item-breed">Size: <?= htmlspecialchars($sizeStr) ?></p>
        <?php endif; ?>
        <?php if ($descRaw !== ''): ?><div class="item-desc"><?= $descOut ?></div><?php endif; ?>
        <div class="item-stock-row">
          <span><?php if (!empty($item['sku'])): ?>SKU: <?= htmlspecialchars($item['sku']) ?><?php endif; ?></span>
          <?php if (!$inStock): ?>
            <span class="stock stock-out">Out of stock — back soon</span>
          <?php elseif ($qty < 5): ?>
            <span class="stock stock-low">Only <?= $qtyLabel ?> left at stand</span>
          <?php else: ?>
            <span class="stock stock-in">In stock at the stand</span>
          <?php endif; ?>
        </div>
        <?php if ($recipeMatch): ?>
        <a class="item-recipe-card" href="/recipes/?ingredient=<?= (int)$recipeMatch['id'] ?>&amp;from=stand&amp;i=<?= (int)$itemId ?>&amp;s=<?= urlencode(createSlug($name)) ?>">
          <span class="irc-icon" aria-hidden="true"><?= bcrf_recipe_icon() ?></span>
          <span class="irc-text">Featured in <strong><?= (int)$recipeMatch['recipe_count'] ?></strong> of our recipe<?= (int)$recipeMatch['recipe_count'] === 1 ? '' : 's' ?> &mdash; see <?= (int)$recipeMatch['recipe_count'] === 1 ? 'it' : 'them' ?> &rarr;</span>
        </a>
        <?php endif; ?>
        <div class="item-actions">
          <a class="cta-btn" href="/farm-stand/inventory.php#cat-<?= htmlspecialchars($catSlug) ?>">← Back to <?= htmlspecialchars($catName) ?></a>
          <a class="ulLink" href="/farm-stand/">All of the farm stand</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($img !== ''): ?>
<div class="lightbox" id="standLightbox" role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars($name) ?>">
  <button type="button" class="lightbox-close" aria-label="Close">&times;</button>
  <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($name) ?>" loading="lazy" decoding="async">
</div>
<?php endif; ?>

<?php
require_once '../../includes/related.php';
$__kwI = bcrf_rel_keywords((string)($item['name'] ?? ''), [(string)($item['category'] ?? '')]);
$__cardsI = [];
foreach (bcrf_related_posts($pdo, $__kwI, 2) as $__c) { $__c['kind'] = 'Journal'; $__cardsI[] = $__c; }
$__cardsI[] = bcrf_visit_card();
echo bcrf_related_render('More from the farm', $__cardsI);
?>
<?php require_once '../../includes/footer.php'; ?>
