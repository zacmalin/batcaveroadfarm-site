<?php
require_once '../config.php';
require_once '../includes/img_dims.php';

if (!function_exists('bcrfZooShop')) {
function bcrfZooShop(PDO $pdo): void {
    try {
        $items = $pdo->query(
            "SELECT i.* FROM inventory i
             JOIN inventory_categories ic ON i.category_id = ic.id
             WHERE i.is_active = 1 AND (LOWER(ic.name) = 'zoo' OR LOWER(ic.slug) = 'zoo')
             ORDER BY i.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $items = []; }
    if (!$items) return; // no Zoo items -> render nothing (no empty heading)

    $mkSlug = function (string $s): string {
        if (function_exists('createSlug')) { $r = createSlug($s); return $r !== '' ? $r : 'item'; }
        $s = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($s)));
        $s = trim((string)$s, '-');
        return $s !== '' ? $s : 'item';
    };
    $mkImg = function ($v): string {
        $v = trim((string)$v);
        if ($v === '') return '';
        if (preg_match('#^https?://#i', $v) || $v[0] === '/') return $v;
        return '/' . $v;
    };
    ?>
    <style>
    .zoo-shop{margin:0 auto 10px;max-width:1100px}
    .zoo-shop-grid{display:flex;flex-wrap:wrap;justify-content:center;gap:16px}
    .zoo-card{display:flex;flex-direction:column;flex:0 1 200px;max-width:200px;background:#fffaf0;border:1px solid #e6d8bf;border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;box-shadow:0 2px 6px rgba(0,0,0,.08);transition:transform .12s ease,box-shadow .12s ease}
    .zoo-card:hover{transform:translateY(-3px);box-shadow:0 8px 18px rgba(0,0,0,.14)}
    .zoo-card-img{width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;display:block}
    .zoo-card-body{padding:10px 12px;display:flex;flex-direction:column;gap:4px}
    .zoo-card-body h3{margin:0;font-size:.95rem;line-height:1.2;color:var(--brown-dark,#3b2010)}
    .zoo-card-desc{margin:0;font-size:.78rem;color:#6b5840;line-height:1.3}
    .zoo-card-price{margin:2px 0 0;font-weight:700;color:var(--green,#4a6741)}
    .zoo-stock{display:inline-block;align-self:flex-start;margin-top:2px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:2px 8px;border-radius:999px}
    .zoo-stock-out{background:#f3e3e0;color:#8b2018;border:1px solid #e3c4bf}
    </style>
    <h2 class="section-heading reveal"><span aria-hidden="true">🥕</span> Feed the Animals</h2>
    <p class="section-intro reveal">Grab feed and treats for your visit — available at the farm stand.</p>
    <div class="zoo-shop reveal">
      <div class="zoo-shop-grid">
        <?php foreach ($items as $it):
            $name  = (string)($it['name'] ?? '');
            $id    = (int)($it['id'] ?? 0);
            $href  = '/farm-stand/items/' . $id . '/' . $mkSlug($name) . '/?from=zoo';
            $imgU  = $mkImg($it['image_url'] ?? '');
            $qty   = (float)($it['stock_quantity'] ?? 0);
            $price = ($it['price'] !== null && $it['price'] !== '') ? '$' . number_format((float)$it['price'], 2) : '';
            $desc  = trim(strip_tags((string)($it['description'] ?? '')));
        ?>
        <a class="zoo-card" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>">
          <?php if ($imgU !== ''): ?>
            <img class="zoo-card-img" src="<?= htmlspecialchars($imgU, ENT_QUOTES) ?>"<?= bcrf_img_attrs($imgU) ?> alt="<?= htmlspecialchars($name, ENT_QUOTES) ?>" loading="lazy" decoding="async">
          <?php endif; ?>
          <div class="zoo-card-body">
            <h3><?= htmlspecialchars($name) ?></h3>
            <?php if ($desc !== ''): ?><p class="zoo-card-desc"><?= htmlspecialchars(mb_strimwidth($desc, 0, 90, '…')) ?></p><?php endif; ?>
            <?php if ($price !== ''): ?><p class="zoo-card-price"><?= $price ?></p><?php endif; ?>
            <?php if ($qty <= 0): ?><span class="zoo-stock zoo-stock-out">Out of stock</span><?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}
}

if (!function_exists('bcrfBreedCards')) {
function bcrfBreedCards(PDO $pdo): void {
    try {
        $breeds = $pdo->query(
            "SELECT * FROM breeds WHERE is_active = 1 ORDER BY breed_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $breeds = []; }
    // representative photo per breed (first active animal that has an image)
    $animalImg = [];
    try {
        foreach ($pdo->query(
            "SELECT breed_id, MIN(photo_url) AS img FROM animals
             WHERE is_active = 1 AND photo_url IS NOT NULL AND photo_url <> ''
             GROUP BY breed_id") as $r) {
            $animalImg[(int)$r['breed_id']] = $r['img'];
        }
    } catch (Throwable $e) {}
    if (!$breeds) {
        echo '<p class="stand-empty">Our animals are settling in — check back soon!</p>';
        return;
    }
    $norm = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return '';
        if (preg_match('#^https?://#i', $v) || $v[0] === '/') return $v;
        return '/' . $v;
    };
    echo '<div class="animal-grid">';
    foreach ($breeds as $b) {
        $id   = (int)$b['id'];
        $name = htmlspecialchars((string)($b['breed_name'] ?? ''), ENT_QUOTES);
        $slug = trim((string)($b['slug'] ?? '')) !== '' ? $b['slug'] : createSlug((string)($b['breed_name'] ?? ''));
        $icon = trim((string)($b['icon'] ?? '')) ?: "\u{1F43E}";
        $tag  = trim((string)($b['tag_line'] ?? ''));
        if ($tag === '' && !empty($b['description'])) {
            $tag = mb_strimwidth(trim(strip_tags((string)$b['description'])), 0, 90, '…');
        }
        $img = $norm((string)($b['image_url'] ?? ''));
        if ($img === '' && isset($animalImg[$id])) { $img = $norm($animalImg[$id]); }
        $href = '/petting-zoo/breeds/' . $id . '/' . $slug . '/';
        echo '<a class="animal-tile reveal" href="' . htmlspecialchars($href, ENT_QUOTES) . '">';
        if ($img !== '') {
            echo '<img class="boximg1" src="' . htmlspecialchars($img, ENT_QUOTES) . '"' . bcrf_img_attrs($img) . ' alt="' . $name . '" loading="lazy" decoding="async">';
        }
        echo '<div class="tile-body">'
           . '<span class="tile-emoji" aria-hidden="true">' . htmlspecialchars($icon, ENT_QUOTES) . '</span>'
           . '<h3>' . $name . '</h3>';
        if ($tag !== '') { echo '<p>' . htmlspecialchars($tag, ENT_QUOTES) . '</p>'; }
        echo '<span class="tile-cta">Meet them &rarr;</span>';
        echo '</div></a>';
    }
    echo '</div>';
}
}

	$pageTitle    = "Petting Zoo | Bat Cave Road Farm — Old Fort, NC";
	$preloadImage = "https://batcaveroadfarm.com/img/animals2.webp";
	$metaDesc = "Meet goats, pigs, donkeys, chickens, ducks, turkeys, and a rare white peacock at Bat Cave Road Farm petting zoo in Old Fort, NC near Asheville.";
	$canonicalUrl = "https://batcaveroadfarm.com/petting-zoo/";
	$ogImage = "https://batcaveroadfarm.com/img/og-pettingzoo.jpg";
	$pageCSS = "https://batcaveroadfarm.com/css/min.pettingzoo.css";
	require('../includes/head.php');
?>
	

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "TouristAttraction",
      "@id": "https://batcaveroadfarm.com/petting-zoo/#attraction",
      "name": "Bat Cave Road Farm Petting Zoo",
      "url": "https://batcaveroadfarm.com/petting-zoo/",
      "description": "Hands-on petting zoo with goats, donkeys, peacocks, pigs, ducks, turkeys, and chickens in Old Fort, NC — near Asheville, Western North Carolina.",
      "image": "https://batcaveroadfarm.com/img/og-pettingzoo.jpg",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "4132 Bat Cave Road",
        "addressLocality": "Old Fort",
        "addressRegion": "NC",
        "postalCode": "28762",
        "addressCountry": "US"
      },
      "openingHoursSpecification": [
        {
          "@type": "OpeningHoursSpecification",
          "dayOfWeek": [
            "Tuesday",
            "Wednesday",
            "Thursday",
            "Friday",
            "Saturday",
            "Sunday"
          ],
          "opens": "10:00",
          "closes": "16:00",
          "description": "Last admission 30 minutes before closing at 4:00 PM"
        }
      ],
      "isPartOf": {
        "@id": "https://batcaveroadfarm.com/#business"
      },
      "offers": [
        {
          "@type": "Offer",
          "name": "Adult Admission",
          "description": "General admission for adults to the Bat Cave Road Farm petting zoo",
          "price": "8.00",
          "priceCurrency": "USD",
          "eligibleCustomerType": "https://schema.org/Adult",
          "url": "https://batcaveroadfarm.com/petting-zoo/",
          "availability": "https://schema.org/InStock",
          "validFrom": "2025-01-01"
        },
        {
          "@type": "Offer",
          "name": "Child Admission (Ages 3–12)",
          "description": "Admission for children ages 3 to 12",
          "price": "5.00",
          "priceCurrency": "USD",
          "url": "https://batcaveroadfarm.com/petting-zoo/",
          "availability": "https://schema.org/InStock",
          "validFrom": "2025-01-01"
        },
        {
          "@type": "Offer",
          "name": "Under 3 — Free",
          "description": "Children under 3 years old are admitted free",
          "price": "0.00",
          "priceCurrency": "USD",
          "url": "https://batcaveroadfarm.com/petting-zoo/",
          "availability": "https://schema.org/InStock",
          "validFrom": "2025-01-01"
        },
        {
          "@type": "Offer",
          "name": "Animal Feed Bag",
          "description": "Bag of animal feed available for purchase at the petting zoo entrance",
          "price": "5.00",
          "priceCurrency": "USD",
          "url": "https://batcaveroadfarm.com/petting-zoo/",
          "availability": "https://schema.org/InStock",
          "validFrom": "2025-01-01"
        }
      ]
    },
    {
      "@type": "Service",
      "@id": "https://batcaveroadfarm.com/petting-zoo/#groupvisits",
      "serviceType": "Group visits, birthday parties, and private farm events",
      "name": "Birthday Parties & Group Farm Visits — Bat Cave Road Farm",
      "description": "Host your birthday party, school field trip, homeschool group, or family reunion at Bat Cave Road Farm. Group packages include guided tours, animal feeding sessions, and private barn access for up to 20 guests.",
      "url": "https://batcaveroadfarm.com/petting-zoo/",
      "image": "https://batcaveroadfarm.com/img/og-pettingzoo.jpg",
      "provider": {
        "@type": "LocalBusiness",
        "@id": "https://batcaveroadfarm.com/#business",
        "name": "Bat Cave Road Farm",
        "url": "https://batcaveroadfarm.com/"
      },
      "areaServed": "Western North Carolina — Old Fort, Asheville & McDowell County",
      "offers": {
        "@type": "Offer",
        "name": "Group Visit Package",
        "description": "Guided farm tour, animal feeding session, and private barn access for groups up to 20. Call for pricing.",
        "url": "https://batcaveroadfarm.com/contact-us/",
        "availability": "https://schema.org/InStock",
        "validFrom": "2025-01-01"
      },
      "audience": {
        "@type": "Audience",
        "audienceType": "Families, School Groups, Birthday Parties, Homeschool Groups"
      }
    }
  ]
}
</script>

<?php
	include('../includes/header.php');
?>
            <div id="page-petting-zoo" class="page1 active">
              <div class="hero hero-photo">
                <span class="hero-deco" aria-hidden="true">🐐</span>
                <div class="hero-content">
                  <h1>Petting Zoo</h1>
                  <p>Meet our friendly residents and make memories that last a lifetime.</p>
                </div>
              </div>
              <div class="divider" aria-hidden="true">✦ ✦ ✦</div>
            
              <div class="content-wrap">
                <h2 class="section-heading reveal">Meet Our Animals</h2>
                <p class="section-intro reveal">Tap any breed to meet our animals and learn more &rarr;</p>
                <?php bcrfBreedCards($pdo); ?>
                <div class="ctaDiv">
                    <a href="https://batcaveroadfarm.com/gallery/" class="cta-btn">
                            See more photos in the Gallery
                    </a>
                    <button type="button" class="cta-btn" data-subscribe-open><span aria-hidden="true">🐣</span> Get baby animal updates</button>
              	</div>  
             <div class="feature-band reveal">
                  <h2><span aria-hidden="true">🌾</span> Rules for a Happy Visit</h2>
                  <p>Please be gentle with all animals. Children under 10 must be accompanied by an adult in the petting zoo area. No <a href="https://batcaveroadfarm.com/faq/#dressCode" class="ulLink">open-toed shoes in animal areas.</a>  Animal feed is available for purchase at the entrance. We ask that all visitors <a href="https://batcaveroadfarm.com/faq/#handwashing" class="ulLink">wash their hands</a> after visiting the animals.</p>
                </div>
            
                <div class="two-col reveal">
                  <div class="two-col-img">
                      <img src="https://batcaveroadfarm.com/img/animals2.webp" alt="Collage of farm animals at Bat Cave Road Farm petting zoo in Old Fort, NC — peacock, pig, donkey, chickens, and goats pictured in gold frames hanging on a rustic barn wall near Asheville, Western North Carolina" width="500" height="375" decoding="async" loading="lazy">
                  </div>
                  <div class="two-col-text">
                    <h3>Birthday Parties & School Groups</h3>
                    <p>Looking for a truly memorable birthday party or field trip? We offer group packages that include guided tours, animal feeding, and private barn access. Up to 20 guests can be accommodated. Tell us about your event and we'll put together a custom package!</p>
                    <div class="cta-pair">
                      <a href="https://batcaveroadfarm.com/private-events/" class="cta-btn">Plan a Private Event</a>
                      <a href="https://batcaveroadfarm.com/booking/" class="cta-btn">Book a Group Visit</a>
                      <a href="https://batcaveroadfarm.com/passes/" class="cta-btn">🪪 Annual Passes</a>
                    </div>
                  </div>
                </div>
            
                <?php if (function_exists('bcrfZooShop')) bcrfZooShop($pdo); ?>

                <?php if (!empty($navShowShop)): ?>
                <div class="feature-band reveal">
                  <h2><span aria-hidden="true">🧢</span> Take Home a Souvenir</h2>
                  <p>Had a blast with the animals? Take a little of the farm home with you — hats, tees, and stickers featuring your favorite Bat Cave Road Farm critters. Every purchase helps keep them fed.</p>
                  <div class="ctaDiv">
                    <a href="https://batcaveroadfarm.com/shop/" class="cta-btn"><span aria-hidden="true">🛍️</span> Visit the Farm Store</a>
                  </div>
                </div>
                <?php endif; ?>

                <div class="info-box reveal" style="margin-top:40px">
                  <h3><span aria-hidden="true">🕐</span> Petting Zoo Hours & Admission</h3>
                  <ul>
                    <li>Appointments available</li>
                    <li>Open Tuesday – Sunday, 10:00 AM – 4:00 PM</li>
                    <li>Adults: $8 · Children (3 - 12): $5 - Under 3: Free</li>
                    <li>Group rates available · call ahead to arrange</li>
                    <li>Last admission 30 minutes before closing</li>
                  </ul>
                </div>
                                <div class="ctaDiv">
                                    
                    <a href="https://batcaveroadfarm.com/farm-stand/" class="cta-btn">
                            After the animals, grab something fresh
                    </a>
                    <a href="https://batcaveroadfarm.com/thrift-shop/" class="cta-btn">
                            or browse the thrift shop
                    </a>

                </div>
              </div>
            </div>
<?php	
	require('../includes/footer.php');
?>
