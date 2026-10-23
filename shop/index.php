<?php
require_once '../config.php';

/* ---------- helpers (guarded) ---------- */
if (!function_exists('bcrf_abs_url')) {
function bcrf_abs_url(string $path, string $base = 'https://batcaveroadfarm.com'): string {
    $path = trim($path);
    if ($path === '') { return ''; }
    if (preg_match('#^https?://#i', $path)) { return $path; }
    if ($path[0] !== '/') { $path = '/' . $path; }
    return $base . $path;
}
}

if (!function_exists('bcrf_inventory_by_cat')) {
/**
 * Fetch active, in-stock inventory rows for the given category slugs/names.
 * Same query shape as the farmstand's bcrfStandBoxes, but returns rows so the
 * page can reuse them for both JSON-LD and the visual grid (one query).
 */
function bcrf_inventory_by_cat(PDO $pdo, array $keys, int $limit = 60): array {
    $keys = array_values(array_unique(array_map(fn($k) => strtolower(trim($k)), $keys)));
    if (!$keys) { return []; }
    $ph  = implode(',', array_fill(0, count($keys), '?'));
    $sql = "SELECT i.*, ic.icon AS category_icon, ic.slug AS category_slug
            FROM inventory i
            JOIN inventory_categories ic ON i.category_id = ic.id
            WHERE i.is_active = 1 AND i.stock_quantity > 0
              AND (LOWER(ic.slug) IN ($ph) OR LOWER(ic.name) IN ($ph))
            ORDER BY i.name ASC
            LIMIT " . (int)$limit;
    try {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge($keys, $keys));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}
}

if (!function_exists('bcrf_shop_grid')) {
/**
 * Render inventory rows as merch boxes: image as background, icon overlay,
 * name underneath. Mirrors the farmstand produce-grid markup/classes exactly.
 */
function bcrf_shop_grid(array $rows): void {
    if (!$rows) {
        echo '<p class="produce-empty reveal">New merch dropping soon &mdash; check back!</p>';
        return;
    }
    echo '<div class="produce-grid">';
    foreach ($rows as $it) {
        $name = htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES);
        $icon = trim((string)($it['icon'] ?? ''));
        if ($icon === '') { $icon = trim((string)($it['category_icon'] ?? '')); }
        if ($icon === '') { $icon = "\u{1F9E2}"; } // 🧢 default
        $img = trim((string)($it['image_url'] ?? ''));
        if ($img !== '' && !preg_match('#^https?://#i', $img) && $img[0] !== '/') { $img = '/' . $img; }
        $hasImg = $img !== '';
        $cls   = 'produce-item reveal' . ($hasImg ? ' has-img' : '');
        $style = $hasImg ? ' style="background-image:url(\'' . htmlspecialchars($img, ENT_QUOTES) . '\')"' : '';
        $href  = '/farm-stand/items/' . (int)$it['id'] . '/' . (createSlug((string)($it['name'] ?? '')) ?: 'item') . '/';
        echo '<a class="' . $cls . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '"' . $style . '>'
           . '<span class="emoji" aria-hidden="true">' . htmlspecialchars($icon, ENT_QUOTES) . '</span>'
           . '<span class="produce-name">' . $name . '</span>'
           . '</a>';
    }
    echo '</div>';
}
}

/* ---------- data (one query, reused for schema + grid) ---------- */
$merchRows = bcrf_inventory_by_cat($pdo, ['merchandise', 'merch']);

/* ---------- page meta ---------- */
$pageTitle    = "Shop — Bat Cave Road Farm";
$metaDesc     = "Bat Cave Road Farm merch — hats, shirts, stickers, and farm-branded goods. Take a little piece of the farm home with you. Old Fort, NC, near Asheville.";
$canonicalUrl = "https://batcaveroadfarm.com/shop/";
$ogImage      = "https://batcaveroadfarm.com/img/og-shop.jpg";
$pageCSS      = "https://batcaveroadfarm.com/css/min.farmstand.css";
include('../includes/head.php');

/* ---------- JSON-LD (Store + dynamic Products from inventory) ---------- */
$ldProducts = [];
$ldOffers   = [];
foreach ($merchRows as $it) {
    $pid     = 'https://batcaveroadfarm.com/shop/#merch-' . (int)$it['id'];
    $img     = bcrf_abs_url((string)($it['image_url'] ?? ''));
    $inStock = ((int)($it['stock_quantity'] ?? 0)) > 0;
    $prod = [
        '@type'    => 'Product',
        '@id'      => $pid,
        'name'     => (string)($it['name'] ?? ''),
        'brand'    => ['@type' => 'Brand', 'name' => 'Bat Cave Road Farm'],
        'category' => 'Merchandise',
        'offers'   => [
            '@type'         => 'Offer',
            'seller'        => ['@id' => 'https://batcaveroadfarm.com/#business'],
            'availability'  => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'itemCondition' => 'https://schema.org/NewCondition',
            'priceCurrency' => 'USD',
        ],
    ];
    if ($img !== '') { $prod['image'] = $img; }
    $ldProducts[] = $prod;
    $ldOffers[]   = ['@type' => 'Offer', 'itemOffered' => ['@id' => $pid]];
}

$storeNode = [
    '@type'       => 'Store',
    '@id'         => 'https://batcaveroadfarm.com/shop/#shop',
    'name'        => 'Bat Cave Road Farm — Farm Store',
    'url'         => 'https://batcaveroadfarm.com/shop/',
    'description' => 'Farm-branded merchandise from Bat Cave Road Farm in Old Fort, NC — hats, shirts, stickers, and more. Available at the farm near Asheville, Western North Carolina.',
    'image'       => 'https://batcaveroadfarm.com/img/og-shop.jpg',
    'telephone'   => function_exists('site_phone_e164') ? site_phone_e164('general') : '+18282165488',
    'address'     => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => function_exists('site_setting') ? site_setting('address_street','4132 Bat Cave Road') : '4132 Bat Cave Road',
        'addressLocality' => function_exists('site_setting') ? site_setting('address_city','Old Fort') : 'Old Fort',
        'addressRegion'   => function_exists('site_setting') ? site_setting('address_state','NC') : 'NC',
        'postalCode'      => function_exists('site_setting') ? site_setting('address_zip','28762') : '28762',
        'addressCountry'  => 'US',
    ],
    'geo' => [
        '@type'     => 'GeoCoordinates',
        'latitude'  => function_exists('site_setting') ? (float)site_setting('geo_lat') : 35.5785104,
        'longitude' => function_exists('site_setting') ? (float)site_setting('geo_lng') : -82.1821418,
    ],
    'parentOrganization' => ['@id' => 'https://batcaveroadfarm.com/#business'],
    'currenciesAccepted' => 'USD',
    'paymentAccepted'    => 'Cash, Credit Card, Venmo, PayPal, CashApp',
];
if ($ldOffers) {
    $storeNode['hasOfferCatalog'] = [
        '@type'           => 'OfferCatalog',
        'name'            => 'Farm Merchandise',
        'itemListElement' => $ldOffers,
    ];
}

$ld = ['@context' => 'https://schema.org', '@graph' => array_merge([$storeNode], $ldProducts)];
echo "\n<script type=\"application/ld+json\">\n"
   . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
   . "\n</script>\n";

include('../includes/header.php');
?>
        <div id="page-shop" class="page1">
          <div class="hero">
            <span class="hero-deco">🧢</span>
            <div class="hero-content">
              <h1>Farm Store</h1>
              <p>Take a little piece of the farm home with you.</p>
            </div>
          </div>
          <div class="divider">✦ ✦ ✦</div>

          <div class="content-wrap">
            <h2 class="section-heading reveal">Merch &amp; Goods</h2>
            <p class="section-intro reveal">Tap any item for details &amp; pricing &rarr;</p>

            <?php bcrf_shop_grid($merchRows); ?>

            <div class="feature-band reveal">
              <h2>🧢 Wear the Farm</h2>
              <p>Hats, tees, stickers, and farm-branded goods — designed on the farm and made to last. Every purchase helps keep the animals fed and the fields growing. Quantities are limited and designs rotate, so grab your favorites while they're here.</p>
            </div>

            <div class="ctaDiv">
              <a href="https://batcaveroadfarm.com/farm-stand/" class="cta-btn">Visit the Farmstand</a>
              <a href="https://batcaveroadfarm.com/petting-zoo/" class="cta-btn">Meet the animals</a>
            </div>

            <div class="info-box reveal" style="margin-top:40px">
              <h3>🛍️ Where to Buy</h3>
              <ul>
                <li>Available at the farmstand and during farm visits</li>
                <li>We accept cash, card, CashApp, PayPal, and Venmo</li>
                <li>Designs &amp; stock rotate — follow us for new drops</li>
                <li>
                    <?php $fsoc = function_exists('site_socials') ? array_values(array_filter(site_socials(), fn($x) => !empty($x['url']))) : [];
                    if ($fsoc): foreach ($fsoc as $soc): ?>
                    <a href="<?= htmlspecialchars($soc['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                        <?php if (!empty($soc['image'])): ?><img class="socialicon" src="<?= htmlspecialchars($soc['image'], ENT_QUOTES) ?>" alt="Bat Cave Road Farm on <?= htmlspecialchars($soc['name']) ?>" width="64" height="64" decoding="async" loading="lazy"> <?php endif; ?><?= htmlspecialchars($soc['name']) ?>
                    </a>
                    <?php endforeach; else: ?>
                    <a href="https://www.facebook.com/batcaveroadfarm" target="_blank" rel="noopener">
                        <img class="socialicon" src="https://batcaveroadfarm.com/img/facebook.png" alt="Bat Cave Road Farm on Facebook" width="64" height="64" decoding="async" loading="lazy"> Facebook
                    </a>
                    <a href="https://www.instagram.com/batcaveroadfarm" target="_blank" rel="noopener">
                        <img class="socialicon" src="https://batcaveroadfarm.com/img/instagram.png" alt="Bat Cave Road Farm on Instagram" width="64" height="64" decoding="async" loading="lazy"> Instagram
                    </a>
                    <?php endif; ?>
                </li>
              </ul>
            </div>
          </div>
        </div>
<?php
    require('../includes/footer.php');
?>
