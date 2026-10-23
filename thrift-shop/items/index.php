<?php
require_once '../../config.php';
$pageTitle     = "Thrift Shop Finds - Bat Cave Road Farm";
$metaDesc      = "Browse available thrift shop finds at Bat Cave Road Farm in Old Fort, NC — vintage, antiques, and one-of-a-kind treasures, updated as our shelves change.";
$canonicalUrl  = "https://batcaveroadfarm.com/thrift-shop/items/";
$ogImage       = "https://batcaveroadfarm.com/img/og-thriftshop.jpg";
$pageCSS       = "https://batcaveroadfarm.com/css/min.farmstand.css";

$search = trim($_GET['search'] ?? '');
$items = [];
try {
    $sql = "SELECT i.id, i.name, i.description, i.price, i.image_url, i.stock_quantity
        FROM inventory i
        JOIN inventory_categories ic ON i.category_id = ic.id
        WHERE i.is_active = 1 AND i.stock_quantity > 0 AND (ic.slug = 'thrift' OR LOWER(ic.name) = 'thrift')";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (i.name LIKE ? OR i.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY i.name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $items = [];
}

require_once '../../includes/head.php';
require_once '../../includes/header.php';

// CollectionPage + ItemList (reference-style) — list-page schema for the thrift catalog
$__li = []; $__n = 0;
foreach ($items as $__it) {
    $__id = (int)($__it['id'] ?? 0); if ($__id <= 0) continue;
    $__nm = (string)($__it['name'] ?? '');
    $__li[] = ['@type' => 'ListItem', 'position' => ++$__n,
        'url'  => 'https://batcaveroadfarm.com/thrift-shop/items/' . $__id . '/' . (createSlug($__nm) ?: 'item') . '/',
        'name' => $__nm];
}
if ($__li) {
    echo '<script type="application/ld+json">' . json_encode([
        '@context' => 'https://schema.org', '@type' => 'CollectionPage',
        '@id'      => 'https://batcaveroadfarm.com/thrift-shop/items/#collection',
        'url'      => 'https://batcaveroadfarm.com/thrift-shop/items/',
        'name'     => ($pageTitle ?? 'Thrift Shop Finds — Bat Cave Road Farm'),
        'isPartOf' => ['@id' => 'https://batcaveroadfarm.com/#website'],
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($__li), 'itemListElement' => $__li],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
}
?>

<div class="hero">
  <span class="hero-deco" aria-hidden="true">🫙</span>
  <div class="hero-content">
    <h1>Thrift Shop Finds</h1>
    <p>One-of-a-kind treasures currently on our shelves — first come, first served.</p>
  </div>
</div>
<div class="divider" aria-hidden="true">✦ ✦ ✦</div>

<div class="content-wrap">
  <form method="GET" class="stand-search reveal" role="search" aria-label="Search thrift finds">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search thrift finds…" aria-label="Search thrift finds">
    <button type="submit" class="cta-btn">Search</button>
  </form>

  <div class="stand-grid">
    <?php if (empty($items)): ?>
      <?php if ($search !== ''): ?>
        <p class="stand-empty">No thrift finds match “<?= htmlspecialchars($search) ?>.” Try a different search — or come browse our shelves in person, Tuesday through Sunday!</p>
      <?php else: ?>
        <p class="stand-empty">No thrift items are listed online right now — but our shelves are always full in person. Come visit us Tuesday through Sunday!</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php foreach ($items as $it):
        $pid = (int)($it['id'] ?? 0); $nm = (string)($it['name'] ?? ''); $pr = $it['price'] ?? null;
        $iu = '/thrift-shop/items/' . $pid . '/' . (createSlug($nm) ?: 'item') . '/';
        $img = trim((string)($it['image_url'] ?? ''));
        if ($img !== '' && !preg_match('#^https?://#i', $img) && $img[0] !== '/') { $img = '/' . $img; }
    ?>
      <a class="stand-card" href="<?= $iu ?>">
        <?php if ($img !== ''): ?>
          <img class="thumb" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($nm) ?>" loading="lazy" decoding="async">
        <?php endif; ?>
        <div class="body">
          <div class="title-row"><h2><?= htmlspecialchars($nm) ?></h2></div>
          <p class="desc"><?= htmlspecialchars(mb_strimwidth(strip_tags((string)($it['description'] ?? '')), 0, 140, '…')) ?></p>
        </div>
        <div class="foot">
          <?php if ($pr !== null && $pr !== ''): ?><span class="stock stock-in">$<?= number_format((float)$pr, 2) ?></span><?php else: ?><span></span><?php endif; ?>
          <span class="view">View →</span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <p class="thrift-note" style="text-align:center;color:var(--cream-dark);text-shadow:1px 1px 3px rgba(0,0,0,.6)">All thrift shop items are sold as-is, with no warranty implied or otherwise. No returns.</p>
  <div class="ctaDiv" style="text-align:center;margin-top:20px;padding-bottom:20px">
    <a href="/thrift-shop/" class="cta-btn">← Back to the thrift shop</a>
  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
