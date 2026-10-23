<?php
require_once '../config.php';

if (!function_exists('bcrfStandBoxes')) {
/**
 * Render up to $limit in-stock inventory items (matching the given category
 * slugs/names) as "what's in season" boxes: item image as the box background,
 * an icon (item icon -> category icon -> default box), name underneath.
 */
function bcrfStandBoxes(PDO $pdo, array $keys, string $label = '', int $limit = 14): void {
    $keys = array_values(array_unique(array_map(fn($k) => strtolower(trim($k)), $keys)));
    if (!$keys) { return; }
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
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    if (!$rows) {
        echo '<p class="produce-empty reveal">Fresh selection coming soon &mdash; check back!</p>';
        return;
    }
    echo '<div class="produce-grid">';
    foreach ($rows as $it) {
        $name = htmlspecialchars((string)($it['name'] ?? ''), ENT_QUOTES);
        $icon = trim((string)($it['icon'] ?? ''));
        if ($icon === '') { $icon = trim((string)($it['category_icon'] ?? '')); }
        if ($icon === '') { $icon = "\u{1F4E6}"; }
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

    if ($label !== '') {
        $catSlug = createSlug((string)($rows[0]['category_slug'] ?? '')) ?: createSlug($keys[0]);
        echo '<div class="produce-more">'
           . '<a class="cta-btn cta-btn--sm" href="/farm-stand/inventory.php#cat-' . htmlspecialchars($catSlug, ENT_QUOTES) . '">See all ' . htmlspecialchars($label, ENT_QUOTES) . ' &rarr;</a>'
           . '</div>';
    }
}
}

	$pageTitle = "Farmstand - Bat Cave Road Farm";
	$metaDesc = "Fresh seasonal vegetables, sourdough bread, farm eggs, local honey, homemade jams, and cut flowers — straight from our farm in Old Fort, NC.";
	$canonicalUrl = "https://batcaveroadfarm.com/farm-stand/";
	$ogImage = "https://batcaveroadfarm.com/img/og-farmstand.jpg";
	$pageCSS= "https://batcaveroadfarm.com/css/min.farmstand.css";
	$pageJS = "https://batcaveroadfarm.com/js/min.comingsoon.js";
	include('../includes/head.php');
?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "GroceryStore",
      "@id": "https://batcaveroadfarm.com/farm-stand/#farmstand",
      "name": "Bat Cave Road Farm — Farmstand",
      "url": "https://batcaveroadfarm.com/farm-stand/",
      "description": "On-farm seasonal farmstand in Old Fort, NC selling fresh-baked sourdough bread, pastries, farm-fresh eggs, seasonal vegetables and fruit, local honey, homemade jams, pickles, and cut flowers. Near Asheville, Western North Carolina.",
      "image": "https://batcaveroadfarm.com/img/og-farmstand.jpg",
      "telephone": <?= json_encode(function_exists('site_phone_e164') ? site_phone_e164('general') : '+18282165488') ?>,
      "address": {
        "@type": "PostalAddress",
        "streetAddress": <?= json_encode(function_exists('site_setting') ? site_setting('address_street','4132 Bat Cave Road') : '4132 Bat Cave Road') ?>,
        "addressLocality": <?= json_encode(function_exists('site_setting') ? site_setting('address_city','Old Fort') : 'Old Fort') ?>,
        "addressRegion": <?= json_encode(function_exists('site_setting') ? site_setting('address_state','NC') : 'NC') ?>,
        "postalCode": <?= json_encode(function_exists('site_setting') ? site_setting('address_zip','28762') : '28762') ?>,
        "addressCountry": "US"
      },
      "geo": {
        "@type": "GeoCoordinates",
        "latitude": <?= json_encode(function_exists('site_setting') ? (float)site_setting('geo_lat') : 35.5785104) ?>,
        "longitude": <?= json_encode(function_exists('site_setting') ? (float)site_setting('geo_lng') : -82.1821418) ?>
      },
      "openingHoursSpecification": <?= json_encode(function_exists('site_hours_schema') ? site_hours_schema('farm_stand') : [['@type'=>'OpeningHoursSpecification','dayOfWeek'=>['Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],'opens'=>'10:00','closes'=>'16:00']], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,
      "currenciesAccepted": "USD",
      "paymentAccepted": "Cash, Credit Card, Venmo, PayPal, CashApp",
      "isAccessibleForFree": true,
      "parentOrganization": {
        "@id": "https://batcaveroadfarm.com/#business"
      },
      "hasOfferCatalog": {
        "@type": "OfferCatalog",
        "name": "Farmstand Products",
        "itemListElement": [
          {
            "@type": "OfferCatalog",
            "name": "Sourdough Bread",
            "description": "Naturally leavened sourdough bread baked fresh on-farm in Old Fort, NC. Available at the farmstand Tuesday through Sunday while supplies last."
          },
          {
            "@type": "OfferCatalog",
            "name": "Farm-Fresh Eggs",
            "description": "Fresh eggs from free-range hens raised on-farm at Bat Cave Road Farm in Old Fort, NC."
          },
          {
            "@type": "OfferCatalog",
            "name": "Local Honey",
            "description": "Raw local honey sourced from Western North Carolina bees, available at the Bat Cave Road Farm farmstand in Old Fort, NC."
          },
          {
            "@type": "OfferCatalog",
            "name": "Seasonal Vegetables & Fruit",
            "description": "Freshly harvested seasonal vegetables and fruit grown on-farm at Bat Cave Road Farm, Old Fort, NC. Selection changes weekly based on harvest."
          },
          {
            "@type": "OfferCatalog",
            "name": "Fresh Cut Flowers",
            "description": "Seasonal cut flowers grown on-farm and available fresh at the Bat Cave Road Farm farmstand in Old Fort, NC."
          },
          {
            "@type": "OfferCatalog",
            "name": "Homemade Jams & Pickles",
            "description": "Small-batch handmade jams, jellies, and pickles made from farm-grown and locally sourced ingredients at Bat Cave Road Farm in Old Fort, NC."
          }
        ]
      }
    }
  ]
}
</script>

<?php
	include('../includes/header.php');
?>
        <div id="comingsoon">
        <img id="floating-img"
             src="https://batcaveroadfarm.com/img/comingsoon.png"
             alt="Coming soon"
             width="428" height="323" decoding="async" loading="lazy">
        </div>
        <div id="page-farmstand" class="page1">
          <div class="hero hero-photo">
            <span class="hero-deco">🌽</span>
            <div class="hero-content">
              <h1>Farm Stand</h1>
              <p>Fresh from our fields. Baked in our kitchen. Made with love.</p>
            </div>
          </div>
          <div class="divider">✦ ✦ ✦</div>
        
          <div class="content-wrap">
            <h2 class="section-heading reveal">What's In Season</h2>
        
            <p class="section-intro reveal">Tap any item for details &amp; pricing &rarr;</p>
        
            <?php bcrfStandBoxes($pdo, ['produce'], 'Produce'); ?>
        
            <div class="feature-band reveal">
              <h2>🍞 Baked Goods & Pantry</h2>
              <p>Every week we bake fresh sourdough loaves, baguettes, and seasonal pastries. Our pantry shelves are stocked with homemade jams, fermented pickles, local honey, farm eggs, and canned goods made from our own harvests. Limited quantities — arrive early!</p>
            </div>

            <h3 class="produce-subhead reveal">Baked Goods</h3>
            <?php bcrfStandBoxes($pdo, ['bakery', 'baked-goods', 'baked goods'], 'Baked Goods'); ?>

            <h3 class="produce-subhead reveal">Pantry</h3>
            <?php bcrfStandBoxes($pdo, ['pantry'], 'Pantry'); ?>


            <div class="two-col reveal">
              <div class="two-col-text">
                <h3>CSA Shares & Weekly Boxes</h3>
                <p>Join our Community Supported Agriculture program and receive a curated weekly box of seasonal produce, baked goods, and pantry staples picked up fresh at the farm. Subscriptions open each spring.</p>
                <a href="https://batcaveroadfarm.com/contact-us/#contactform" class="cta-btn">Ask About CSA</a>
              </div>
              <div class="two-col-img">
                  <img src="https://batcaveroadfarm.com/img/farmstandtable.webp" alt="Fresh-baked goods and homemade preserves at Bat Cave Road Farm farmstand in Old Fort, NC — sourdough bread, blueberry muffins, croissants, and jars of strawberry rhubarb jam, raspberry jam, peach ginger, blackberry jelly, and honey alongside a fresh-cut flower bouquet" width="500" height="285" decoding="async" loading="lazy">
              </div>
            </div>
                 <div class="ctaDiv">
                                    
                    <a href="https://batcaveroadfarm.com/petting-zoo/" class="cta-btn">
                            While you're here, meet the animals
                    </a>
                    <button type="button" class="cta-btn" data-subscribe-open><span aria-hidden="true">🌾</span> Get fresh harvest alerts</button>
                    <a href="https://batcaveroadfarm.com/shop/" class="cta-btn"><span aria-hidden="true">🧢</span> Shop farm merch</a>

                </div>
            <div class="info-box reveal" style="margin-top:40px">
              <h3>🕐 Farmstand Hours</h3>
              <?php $hp = function_exists('site_hours_parts') ? site_hours_parts('farm_stand') : ['days' => 'Tuesday – Sunday', 'time' => '10:00 AM – 4:00 PM', 'closed' => 'Closed Mondays']; ?>
              <ul>
                <li><?= htmlspecialchars($hp['days']) ?>: <?= htmlspecialchars($hp['time']) ?></li>
                <li><?= htmlspecialchars($hp['closed']) ?></li>
                <li>We accept cash, card, CashApp, PayPal, and Venmo</li>
                <li>Follow us on social media for daily availability updates</li>
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
                <li>
                                    <a href="https://batcaveroadfarm.com/faq/#farmstand" class="ulLink">
                        Check our FAQ for frequently asked questions.
                    </a>
                </li>
              </ul>
            </div>
          </div>
        </div>
<?php	
	require('../includes/footer.php');
?>
