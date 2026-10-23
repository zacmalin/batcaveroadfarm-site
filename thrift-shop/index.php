<?php
	require_once '../config.php';
	$pageTitle = "Thrift Shop - Bat Cave Road Farm";
	$metaDesc = "Vintage clothing, cast iron, depression glass, vinyl records, antiques, and estate finds at Bat Cave Road Farm's thrift shop in Old Fort, NC.";
	$canonicalUrl = "https://batcaveroadfarm.com/thrift-shop/";
	$ogImage = "https://batcaveroadfarm.com/img/og-thriftshop.jpg";
	$pageCSS = "https://batcaveroadfarm.com/css/min.farmstand.css";
	$pageJS = "https://batcaveroadfarm.com/js/min.comingsoon.js";
	require('../includes/head.php');
?>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Store",
      "@id": "https://batcaveroadfarm.com/thrift-shop/#thriftshop",
      "name": "Bat Cave Road Farm — Thrift Shop & Antiques",
      "url": "https://batcaveroadfarm.com/thrift-shop/",
      "description": "On-farm thrift shop and antique resale store in Old Fort, NC carrying vintage kitchenware, antique furniture, lamps, clocks, collectibles, estate items, books, toys, and clothing. New inventory arrives weekly from local estates and community donations. Near Asheville, Western North Carolina.",
      "image": "https://batcaveroadfarm.com/img/og-thriftshop.jpg",
      "telephone": <?= json_encode(function_exists('site_tel') ? site_tel('general') : '+18282165488') ?>,
      "address": <?= json_encode(function_exists('site_schema_address') ? site_schema_address() : ['@type' => 'PostalAddress', 'streetAddress' => '4132 Bat Cave Road', 'addressLocality' => 'Old Fort', 'addressRegion' => 'NC', 'postalCode' => '28762', 'addressCountry' => 'US'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      "geo": <?= json_encode(function_exists('site_schema_geo') ? site_schema_geo() : ['@type' => 'GeoCoordinates', 'latitude' => 35.5785104, 'longitude' => -82.1821418], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      "openingHoursSpecification": <?= json_encode(function_exists('site_hours_schema') ? (site_hours_schema('thrift_shop') ?: [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], 'opens' => '10:00', 'closes' => '16:00']]) : [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], 'opens' => '10:00', 'closes' => '16:00']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
      "currenciesAccepted": "USD",
      "paymentAccepted": "Cash, Credit Card, Venmo, PayPal, CashApp",
      "isAccessibleForFree": true,
      "keywords": "thrift store, antique shop, vintage, resale, estate sale, secondhand, Old Fort NC, near Asheville",
      "parentOrganization": {
        "@id": "https://batcaveroadfarm.com/#business"
      },
      "hasOfferCatalog": {
        "@type": "OfferCatalog",
        "name": "Thrift Shop Categories",
        "itemListElement": [
          {
            "@type": "OfferCatalog",
            "name": "Antique & Vintage Furniture",
            "description": "Chairs, tables, dressers, cabinets, and other antique and vintage furniture pieces sourced from local estates."
          },
          {
            "@type": "OfferCatalog",
            "name": "Vintage Kitchenware & Housewares",
            "description": "Cast iron cookware, vintage dishes, glassware, crockery, lamps, clocks, and household collectibles."
          },
          {
            "@type": "OfferCatalog",
            "name": "Books, Toys & Clothing",
            "description": "Used books across all genres, vintage toys, games, and gently used clothing for all ages."
          },
          {
            "@type": "OfferCatalog",
            "name": "Collectibles & Curiosities",
            "description": "One-of-a-kind estate finds, art, decor, and unique collectible items. New inventory added weekly."
          }
        ]
      }
    },
    {
      "@type": "ItemList",
      "name": "Current Thrift Shop Inventory Categories — Bat Cave Road Farm",
      "url": "https://batcaveroadfarm.com/thrift-shop/",
      "description": "Rotating inventory of vintage, antique, and secondhand goods at the Bat Cave Road Farm thrift shop in Old Fort, NC. New items from local estates arrive every week.",
      "itemListElement": [
        {
          "@type": "ListItem",
          "position": 1,
          "name": "Antique & Vintage Furniture",
          "url": "https://batcaveroadfarm.com/thrift-shop/"
        },
        {
          "@type": "ListItem",
          "position": 2,
          "name": "Vintage Kitchenware & Cast Iron",
          "url": "https://batcaveroadfarm.com/thrift-shop/"
        },
        {
          "@type": "ListItem",
          "position": 3,
          "name": "Lamps, Clocks & Home Decor",
          "url": "https://batcaveroadfarm.com/thrift-shop/"
        },
        {
          "@type": "ListItem",
          "position": 4,
          "name": "Books, Toys & Games",
          "url": "https://batcaveroadfarm.com/thrift-shop/"
        },
        {
          "@type": "ListItem",
          "position": 5,
          "name": "Clothing & Accessories",
          "url": "https://batcaveroadfarm.com/thrift-shop/"
        },
        {
          "@type": "ListItem",
          "position": 6,
          "name": "Collectibles & Estate Curiosities",
          "url": "https://batcaveroadfarm.com/thrift-shop/"
        }
      ]
    }
  ]
}
</script>

<?php
	include('../includes/header.php');
?>
        <div id="page-thrift-shop" class="page1">
          
          
        <div id="comingsoon">
        <img id="floating-img"
             src="https://batcaveroadfarm.com/img/comingsoon.png"
             alt="Coming soon"
             width="428" height="323" decoding="async" loading="lazy">
        </div>        <!-- ─── FLOATING IMAGE ─── -->
        
          <div class="hero hero-photo">
            <span class="hero-deco" aria-hidden="true">🫙</span>
            <div class="hero-content">

              <h1>Thrift Shop</h1>
              <p>Treasure hunting at its finest — one person's past becomes your perfect find.</p>
            </div>
          </div>
          <div class="divider" aria-hidden="true">✦ ✦ ✦</div>
          
          <div class="content-wrap">
            <h2 class="section-heading reveal">What You'll Find</h2>
            <div class="card-grid">
              <div class="card reveal">
                <div class="card-img-placeholder">
                    <img class="boximg" src="https://batcaveroadfarm.com/img/kitchenware.webp" alt="Vintage kitchenware estate finds at Bat Cave Road Farm thrift shop — antique cast iron skillets, Atlas mason jars, depression glass plates, stoneware crocks, and floral teapots on rustic wooden shelves in Old Fort, NC" width="500" height="500" decoding="async" loading="lazy">
                </div>
                <div class="card-body">
                  <h3 class="card-title">Vintage Kitchenware</h3>
                  <p class="card-text">Teapots, mason jars, cast iron, crockery, and collectible glassware curated from estate sales and local collections.</p>
                </div>
              </div>
              <div class="card reveal">
                <div class="card-img-placeholder">
                    <img class="boximg" src="https://batcaveroadfarm.com/img/books.webp" alt="Antique books and paper collection for sale at Bat Cave Road Farm thrift shop — out-of-print volumes, vintage cookbooks, old dictionaries, children's books, and handwritten recipe cards on rustic shelves in Old Fort, NC" width="500" height="500" decoding="async" loading="lazy">
                </div>
                <div class="card-body">
                  <h3 class="card-title">Books & Paper</h3>
                  <p class="card-text">A rotating selection of fiction, nonfiction, children's books, vintage cookbooks, and out-of-print gems.</p>
                </div>
              </div>
              <div class="card reveal">
                <div class="card-img-placeholder">
                    <img class="boximg" src="https://batcaveroadfarm.com/img/antique.webp" alt="Antique books and paper collection for sale at Bat Cave Road Farm thrift shop — out-of-print volumes, vintage cookbooks, old dictionaries, children's books, and handwritten recipe cards on rustic shelves in Old Fort, NC" width="500" height="500" decoding="async" loading="lazy">
                </div>
                <div class="card-body">
                  <h3 class="card-title">Antiques, Curiosities & Artwork</h3>
                  <p class="card-text">Furniture, lamps, clocks, toys, original prints, paintings, and one-of-a-kind objects that tell a story. New arrivals every week.</p>
                </div>
              </div>
            </div>
            
            <div class="feature-band reveal">
              <h2><span aria-hidden="true">🛋️</span> New Arrivals Every Week</h2>
              <p>Our thrift shop is always changing. We source from local estates, farm clearing sales, and generous community donations. Every visit is a new adventure — there's truly no telling what you'll discover. Follow our social media for sneak peeks at new items before they hit the shelves.</p>
            </div>

            <?php
            $thriftPreview = [];
            try {
                $tp = $pdo->query("SELECT i.id, i.name, i.description, i.price, i.image_url
                    FROM inventory i JOIN inventory_categories ic ON i.category_id = ic.id
                    WHERE i.is_active = 1 AND i.stock_quantity > 0 AND (ic.slug = 'thrift' OR LOWER(ic.name) = 'thrift')
                    ORDER BY RAND() LIMIT 3");
                $thriftPreview = $tp->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $thriftPreview = []; }
            ?>
            <?php if ($thriftPreview): ?>
            <h2 class="section-heading reveal">Some of What's on Our Shelves</h2>
            <p class="reveal" style="text-align:center;max-width:680px;margin:0 auto 26px;font-family:'Lora',serif;color:var(--cream-dark);text-shadow:1px 1px 3px rgba(0,0,0,.6)">A peek at a few treasures currently in the shop. Our inventory turns over constantly, so these may already have found a new home — come see what's new in person.</p>
            <div class="stand-grid stand-grid--center reveal">
              <?php foreach ($thriftPreview as $it):
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
                    <div class="title-row"><h3><?= htmlspecialchars($nm) ?></h3></div>
                    <p class="desc"><?= htmlspecialchars(mb_strimwidth(strip_tags((string)($it['description'] ?? '')), 0, 120, '…')) ?></p>
                  </div>
                  <div class="foot">
                    <?php if ($pr !== null && $pr !== ''): ?><span class="stock stock-in">$<?= number_format((float)$pr, 2) ?></span><?php else: ?><span></span><?php endif; ?>
                    <span class="view">View →</span>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
            <div class="ctaDiv" style="text-align:center;margin-top:4px">
              <a href="/thrift-shop/items/" class="cta-btn">See more items</a>
            </div>
            <?php endif; ?>

            <div class="two-col reveal">
              <div class="two-col-img">
                  <img class="boximg" src="https://batcaveroadfarm.com/img/thrift1.webp" alt="Inside the Bat Cave Road Farm thrift shop in Old Fort, NC — vintage clothing racks, depression glass, cast iron cookware, vinyl records, graphic tees, and antique housewares on hardwood floors with a rustic checkout counter" width="500" height="375" decoding="async" loading="lazy">
              </div>
              <div class="two-col-text">
                <h3>Donations Welcome</h3>
                <p>We gladly accept gently used furniture, housewares, clothing, books, and collectibles. Donations go toward saving <a href="https://batcaveroadfarm.com/petting-zoo/">animals</a>. Drop off any open day during shop hours — no appointment needed for small loads. Large furniture donations, please call ahead.</p>
                <a href="https://batcaveroadfarm.com/contact-us/#contactform" class="cta-btn">Contact Us</a>
              </div>
            </div>
                             <div class="ctaDiv">
                                    
                    <a href="https://batcaveroadfarm.com/farm-stand/" class="cta-btn">
                            Don't leave without checking the farmstand
                    </a>

                </div>
            <div class="info-box reveal" style="margin-top:40px">
              <h3><span aria-hidden="true">🕐</span> Thrift Shop Hours</h3>
              <?php $hp = function_exists('site_hours_parts') ? site_hours_parts('thrift_shop') : ['days' => 'Tuesday – Sunday', 'time' => '10:00 AM – 4:00 PM', 'closed' => 'Closed Mondays']; ?>
              <ul>
                <li><?= htmlspecialchars($hp['days']) ?>: <?= htmlspecialchars($hp['time']) ?></li>
                <li><?= htmlspecialchars($hp['closed']) ?></li>
                <li>Special sales and pop-up events throughout the year</li>
                <li>We accept cash, card, CashApp, PayPal, and Venmo</li>
                <li>All sales final — no returns</li>
                <li>Need something specific? Ask us — we may have it in the back!</li>
              </ul>
            </div>
          </div>
        </div>
<?php	
	require('../includes/footer.php');
?>