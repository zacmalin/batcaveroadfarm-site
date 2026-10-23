<?php
require_once '../../config.php';

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
    $metaDesc     = "This thrift item is no longer available.";
    $canonicalUrl = "https://batcaveroadfarm.com/thrift-shop/items/";
    $pageCSS      = "https://batcaveroadfarm.com/css/min.farmstand.css";
    require_once '../../includes/head.php';
    require_once '../../includes/header.php';
    ?>
    <div class="content-wrap">
      <div class="item-detail" style="padding:34px">
        <h1 style="font-family:'Rye',serif;color:var(--brown-dark)">This treasure found a home</h1>
        <p style="font-family:'Lora',serif;color:#3f2a14">Our thrift inventory turns over quickly, so this item may already be gone. There's always something new waiting — <a href="/thrift-shop/items/" style="color:var(--red-barn);font-weight:700">browse what's available now →</a></p>
      </div>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

$itemId  = (int)($item['id'] ?? $id);
$name    = (string)($item['name'] ?? '');
$price   = $item['price'] ?? null;
$inStock = (float)($item['stock_quantity'] ?? 0) > 0;
$sizeStr = '';
if (!empty($item['size_value'])) {
    $sizeStr = trim($item['size_value'] . ' ' . ($item['size_unit'] ?? ''));
}
// description may be plain text or admin-authored HTML — render HTML if tags are present, else preserve line breaks
$descRaw = (string)($item['description'] ?? '');
$descOut = ($descRaw !== strip_tags($descRaw)) ? $descRaw : nl2br(htmlspecialchars($descRaw));
$img = trim((string)($item['image_url'] ?? ''));
if ($img !== '' && !preg_match('#^https?://#i', $img) && $img[0] !== '/') { $img = '/' . $img; }

$pageTitle    = $name . " - Thrift Shop - Bat Cave Road Farm";
$metaDesc     = mb_strimwidth(trim(strip_tags($descRaw)) !== '' ? strip_tags($descRaw) : $name, 0, 155, '…');
$itemSlug     = createSlug($name) ?: 'item';
$canonicalUrl = "https://batcaveroadfarm.com/thrift-shop/items/" . (int)$itemId . "/" . $itemSlug . "/";
$breadcrumbs = [
    ['label' => 'Thrift Shop', 'url' => '/thrift-shop/'],
    ['label' => 'Items', 'url' => '/thrift-shop/items/'],
    ['label' => $name],
];
$ogImage      = !empty($item['image_url']) ? $item['image_url'] : "https://batcaveroadfarm.com/img/og-thriftshop.jpg";
$pageCSS      = "https://batcaveroadfarm.com/css/min.farmstand.css";
if ($img !== '') { $preloadImage = $img; }
$pageJS       = "https://batcaveroadfarm.com/js/min.lightbox.js";

require_once '../../includes/head.php';
require_once '../../includes/header.php';
?>

<div class="content-wrap">
  <div class="item-detail reveal">
    <div class="item-grid">
      <?php if ($img !== ''): ?>
        <img class="item-photo img-zoom" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($name . ' — Bat Cave Road Farm thrift shop in Old Fort, NC') ?>" fetchpriority="high" decoding="async">
      <?php endif; ?>
      <div class="item-info">
        <span class="item-pill"><?= htmlspecialchars($item['category'] ?? 'Thrift Find') ?></span>
        <h1><?= htmlspecialchars($name) ?></h1>
        <?php if ($price !== null && $price !== ''): ?>
          <p class="item-price">$<?= number_format((float)$price, 2) ?></p>
        <?php endif; ?>
        <?php if ($descRaw !== ''): ?><div class="item-desc"><?= $descOut ?></div><?php endif; ?>
        <div class="item-stock-row">
          <span><?php if (!empty($item['sku'])): ?>SKU: <?= htmlspecialchars($item['sku']) ?><?php endif; ?></span>
          <?php if ($inStock): ?>
            <span class="stock stock-in">✨ Available — one of a kind</span>
          <?php else: ?>
            <span class="stock stock-out">🏺 Found a home</span>
          <?php endif; ?>
        </div>
        <a class="cta-btn" href="/thrift-shop/items/" style="margin-top:18px">← Back to thrift finds</a>
        <p class="thrift-note">All thrift shop items are sold as-is, with no warranty implied or otherwise. No returns.</p>
      </div>
    </div>
  </div>
</div>

<?php if ($img !== ''): ?>
<div class="lightbox" id="thriftLightbox" role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars($name) ?>">
  <button type="button" class="lightbox-close" aria-label="Close">&times;</button>
  <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($name) ?>" loading="lazy" decoding="async">
</div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
