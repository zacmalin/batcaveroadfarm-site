<?php
    $pageTitle       = "Plan Your Visit | Bat Cave Road Farm — Old Fort, NC";
    $metaDesc        = "Plan your visit to Bat Cave Road Farm in Old Fort, NC. Hours, directions, parking, and what to expect at our petting zoo, farmstand, and thrift shop near Asheville. Open Tue–Sun, 10AM–4PM.";
    $canonicalUrl    = "https://batcaveroadfarm.com/plan-your-visit/";
    $ogImage         = "https://batcaveroadfarm.com/img/og-home.jpg";
    require('../includes/head.php');
    include('../includes/header.php');

    // Live hours from site_info (single source of truth)
    $bhours    = function_exists('site_hours_summary') ? site_hours_summary('general') : [];
    $openDays  = $bhours['open_days']  ?? 'Tuesday – Sunday';
    $openTime  = $bhours['open_time']  ?? '10:00 AM – 4:00 PM';
    $closedTxt = $bhours['closed_phrase'] ?? 'Closed Mondays.';

    // Admission pricing from inventory — single source of truth shared with /tickets/
    $visitTypes = [];
    try {
        $visitTypes = $pdo->query(
            "SELECT i.name, i.price FROM inventory i
             JOIN inventory_categories ic ON ic.id = i.category_id
             WHERE ic.slug = 'admissions' AND i.is_active = 1
             ORDER BY i.price DESC, i.name ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* Admissions category not yet created */ }
?>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebPage","name":"Plan Your Visit — Bat Cave Road Farm","url":"https://batcaveroadfarm.com/plan-your-visit/","description":"Hours, directions, parking, and what to expect at Bat Cave Road Farm in Old Fort, NC — petting zoo, farmstand, and thrift shop near Asheville.","about":{"@id":"https://batcaveroadfarm.com/#business"},"isPartOf":{"@id":"https://batcaveroadfarm.com/#website"}}
</script>

        <div id="page-plan-your-visit" class="page1">
          <div class="hero hero-photo">
            <span class="hero-deco" aria-hidden="true">📍</span>
            <div class="hero-content">
              <h1>Plan Your Visit</h1>
              <p>Everything you need to know before you head out to the farm.</p>
              <p style="margin-top:14px;"><a class="cta-btn" href="https://batcaveroadfarm.com/booking/">🎟️ Book a Visit →</a></p>
            </div>
          </div>

          <div class="content-wrap">

            <section class="visit-info" aria-label="Hours and location">
              <div class="visit-info-block">
                <h2>🕒 Hours</h2>
                <p><strong><?= htmlspecialchars($openDays) ?></strong><br><?= htmlspecialchars($openTime) ?></p>
                <?php if ($closedTxt !== ''): ?><p><?= htmlspecialchars($closedTxt) ?></p><?php endif; ?>
              </div>
              <div class="visit-info-block">
                <h2>📌 Find Us</h2>
                <p><a rel="noopener" target="_blank" href="https://maps.google.com/?q=4132 Bat Cave Road, Old Fort, North Carolina, 28762">4132 Bat Cave Road<br>Old Fort, NC 28762</a></p>
                <p><a href="tel:+18282165488">(828) 216-5488</a></p>
              </div>
            </section>

            <?php if (!empty($visitTypes)): ?>
            <h2 class="visit-section-title">Admission &amp; pricing</h2>
            <div class="visit-pricing">
              <?php foreach ($visitTypes as $vt):
                $price = (float)$vt['price'];
                $priceLabel = $price <= 0 ? 'Free' : '$' . number_format($price, 2 - (fmod($price, 1) === 0.0 ? 2 : 0));
              ?>
              <div class="visit-price-row">
                <span class="visit-price-label"><?= htmlspecialchars($vt['name']) ?></span>
                <span class="visit-price-amount"><?= htmlspecialchars($priceLabel) ?></span>
              </div>
              <?php endforeach; ?>
              <p class="visit-price-note">Walk-ins for groups of 5 or fewer are welcome during open hours — no booking needed.</p>
              <p style="margin-top:14px;text-align:center;">
                <a class="cta-btn" href="/tickets/">Buy tickets online →</a>
              </p>
            </div>
            <?php endif; ?>

            <h2 class="visit-section-title">Walk-ins &amp; reservations</h2>
            <div class="visit-booking-policy">
              <div class="visit-policy-row">
                <span class="visit-policy-icon">🚶</span>
                <div>
                  <strong>Walk-ins welcome</strong> for groups of <strong>5 or fewer</strong> during open hours. No booking required — just show up!
                </div>
              </div>
              <div class="visit-policy-row">
                <span class="visit-policy-icon">📅</span>
                <div>
                  <strong>Book ahead</strong> for groups of <strong>6 or more</strong>, guided tours, and private events. Booking guarantees your spot and time slot.
                </div>
              </div>
              <div class="visit-policy-row">
                <span class="visit-policy-icon">🎈</span>
                <div>
                  <strong>Private events</strong> (birthday parties, group buyouts) are by request — we'll confirm details and pricing personally.
                </div>
              </div>
              <p style="margin-top:16px;display:flex;flex-wrap:wrap;gap:12px;justify-content:center;">
                <a class="cta-btn" href="/booking/">Book your visit →</a>
                <a class="cta-btn" href="/private-events/" style="background:rgba(201,146,58,.15);border:2px solid #c9923a;color:#5c3317">Plan a private event →</a>
                <a class="cta-btn" href="/passes/" style="background:rgba(201,146,58,.15);border:2px solid #c9923a;color:#5c3317">🪪 Annual passes →</a>
              </p>
            </div>

            <h2 class="visit-section-title">What you'll find</h2>
            <div class="visit-grid">
              <a class="visit-tile" href="/petting-zoo/">
                <span class="visit-tile-emoji" aria-hidden="true">🐐</span>
                <span class="visit-tile-name">Petting Zoo</span>
                <span class="visit-tile-blurb">Goats, pigs, ducks, chickens, peacocks, and donkeys — all friendly and hands-on.</span>
              </a>
              <a class="visit-tile" href="/farm-stand/">
                <span class="visit-tile-emoji" aria-hidden="true">🥕</span>
                <span class="visit-tile-name">Farmstand</span>
                <span class="visit-tile-blurb">Seasonal vegetables, farm eggs, honey, baked goods, and cut flowers.</span>
              </a>
              <a class="visit-tile" href="/thrift-shop/">
                <span class="visit-tile-emoji" aria-hidden="true">🛋️</span>
                <span class="visit-tile-name">Thrift Shop</span>
                <span class="visit-tile-blurb">Vintage finds, antiques, glassware, vinyl, and one-of-a-kind treasures.</span>
              </a>
            </div>

            <h2 class="visit-section-title">Good to know</h2>
            <ul class="visit-amenities">
              <li>🅿️ Free on-site parking</li>
              <li>🚼 Stroller accessible</li>
              <li>🐕 Dog friendly</li>
              <li>🧼 Hand-washing stations</li>
              <li>💵 Cash, card, Venmo, PayPal &amp; CashApp accepted</li>
              <li>👨‍👩‍👧‍👦 Family-friendly — bring the kids!</li>
            </ul>

            <section class="visit-getting-here" aria-label="Getting here">
              <h2 class="visit-section-title">Getting here</h2>
              <p>Bat Cave Road Farm sits just outside Old Fort in McDowell County, an easy drive from Asheville and the surrounding Western North Carolina mountains — right off the I-40 corridor. See our <a href="/directions/">Map &amp; Directions page</a> for written directions and drive times from towns across the region.</p>
              <p><a class="cta-btn" href="/directions/">Map &amp; directions →</a></p>
            </section>

            <section class="visit-local" aria-label="While you're in the area">
              <h2>While you're in the area</h2>
              <p>Make a day of it — some nearby Old Fort &amp; Asheville-area favorites:</p>
              <ul>
                <li><a href="https://exploreasheville.com/" target="_blank" rel="noopener">Explore Asheville</a></li>
                <li><a href="https://www.destinationmcdowell.com/" target="_blank" rel="noopener">Destination McDowell</a></li>
                <li><a href="https://www.dncr.nc.gov/visit/trip-ideas/experience-western-north-carolina" target="_blank" rel="noopener">Experience Western NC</a></li>
                <li><a href="https://www.mgmnc.org/" target="_blank" rel="noopener">The Mountain Gateway Museum</a></li>
                <li><a href="https://www.destinationmcdowell.com/attractions/historic-old-fort-train-depot-museum/" target="_blank" rel="noopener">Old Fort Railroad Museum</a></li>
                <li><a href="https://www.modernalchemist.com/" target="_blank" rel="noopener">Modern Alchemist</a></li>
                <li><a href="https://hillmanbeer.com/old-fort-taproom/" target="_blank" rel="noopener">Hillman Beer — Old Fort Taproom</a></li>
                <li><a href="https://www.seekercoffeenc.com/" target="_blank" rel="noopener">Seeker Coffee</a></li>
              </ul>
            </section>

            <section class="visit-questions" aria-label="Questions">
              <h2 class="visit-section-title">Questions before you come?</h2>
              <p>Ready to come out? <a href="https://batcaveroadfarm.com/booking/">Book your visit</a> — or check our <a href="/faq/">frequently asked questions</a> or <a href="/contact-us/">get in touch</a>. We're happy to help you plan the perfect farm day.</p>
            </section>

          </div>
        </div>

<?php require('../includes/footer.php'); ?>
