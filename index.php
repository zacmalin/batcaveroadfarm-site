<?php
	require_once __DIR__ . '/config.php';
	$pageTitle = "Bat Cave Road Farm — Petting Zoo & Farmstand | Old Fort, NC";
	$preloadImage = "https://batcaveroadfarm.com/img/animals1.webp";
	
	include __DIR__ . '/includes/head.php';
?>



<?php
	include __DIR__ . '/includes/header.php';

	
?>
        <div id="page-home" class="page1 active">
            <div class="hero hero-photo">
                <span class="hero-deco" aria-hidden="true">🌾</span>
                <div class="hero-content">
                    <h1>Petting Zoo, Farmstand &amp; Thrift Shop in Old Fort, NC</h1>
                    <p>A family-owned slice of countryside magic — where <a href="https://batcaveroadfarm.com/farm-stand/">fresh food</a>, <a href="https://batcaveroadfarm.com/petting-zoo/">friendly animals</a>, and <a href="https://batcaveroadfarm.com/thrift-shop/">found treasures</a> come together.</p>
                </div>
            </div>
            <div class="divider" aria-hidden="true">✦ ✦ ✦</div>
          
            <div class="content-wrap">
                <h2 class="section-heading reveal">Everything We Offer</h2>
        
                <div class="card-grid">
                    <a class="card cardLink reveal" href="https://batcaveroadfarm.com/petting-zoo/">
                        <div class="card-img-placeholder">
                            <img class="boximg" src="https://batcaveroadfarm.com/img/animals1.webp" alt="White peacock displaying full tail feathers surrounded by goats, a pig, donkey, and wild turkeys at the Bat Cave Road Farm petting zoo in Old Fort, NC — family-friendly farm animal experience near Asheville, Western North Carolina" width="500" height="281" decoding="async" fetchpriority="high">
                        </div>
                        <div class="card-body">
                            <h3 class="card-title">Petting Zoo</h3>
                            <p class="card-text">Get up close with goats, pigs, ducks, chickens, peacocks, donkeys and more every day. A hands-on adventure the whole family will love.</p>
                            <span class="cta-btn wideBTN">Meet the animals</span>
                        </div>
                    </a>
                    <a class="card cardLink reveal" href="https://batcaveroadfarm.com/farm-stand/">
                        <div class="card-img-placeholder">
                            <img class="boximg" src="https://batcaveroadfarm.com/img/farmstand1.webp" alt="Bat Cave Road Farmstand in Old Fort, NC — fresh seasonal vegetables, sourdough bread, farm eggs, local honey, homemade jams, and cut flower bouquets with the Blue Ridge Mountains in the background" width="500" height="273" decoding="async" loading="lazy">
                        </div>
                        <div class="card-body">
                            <h3 class="card-title">Farmstand</h3>
                            <p class="card-text">Seasonal vegetables, fresh-baked breads, farm eggs, honey, jams, and hand-harvested flowers — straight from our farm to you.</p>
                            <span class="cta-btn wideBTN">Visit the stand</span>
                        </div>
                    </a>
                    <a class="card cardLink reveal" href="https://batcaveroadfarm.com/thrift-shop/">
                        <div class="card-img-placeholder">
                            <img class="boximg" src="https://batcaveroadfarm.com/img/thrift1.webp" alt="Inside the Bat Cave Road Farm thrift shop in Old Fort, NC — vintage clothing racks, depression glass, cast iron cookware, vinyl records, graphic tees, and antique housewares on hardwood floors with a rustic checkout counter" width="500" height="375" decoding="async" loading="lazy">
                        </div>
                        <div class="card-body">
                            <h3 class="card-title">Thrift Shop</h3>
                            <p class="card-text">Unique vintage finds, antiques, collectibles, and one-of-a-kind treasures curated with care in our charming on-farm shop.</p>
                            <span class="cta-btn wideBTN">Browse the shop</span>
                        </div>
                    </a>
                </div>

                <div class="feature-band reveal">
                    <h2><span aria-hidden="true">🌿</span> Rooted in the Land Since 2023</h2>
                    <p>We believe in honest work, good food, and the joy of connecting with the natural world. Whether you're here to fill your basket or your heart, there's always something waiting for you.</p>
                    <a href="https://batcaveroadfarm.com/about/" class="cta-btn">
                        Meet the family
                    </a>
                </div>

                <?php
                $latestPost = $latestRecipe = $favRecipe = false;
                try { $latestPost = $pdo->query("SELECT * FROM blog_posts WHERE status = 'published' ORDER BY COALESCE(date_published, created_at) DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                try { $latestRecipe = $pdo->query("SELECT * FROM recipes WHERE status = 'published' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
                try {
                    $excl = $latestRecipe ? (int)$latestRecipe['id'] : 0;
                    $favRecipe = $pdo->query("SELECT * FROM recipes WHERE id <> $excl AND status = 'published' ORDER BY RAND() LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
                $homeNorm = function ($v) { $v = trim((string)$v); if ($v === '') return ''; if (preg_match('#^https?://#i', $v) || $v[0] === '/') return $v; return '/' . $v; };
                $homeRecImg = function ($r) { foreach (['feature_image','image_url','featured_image','image','photo_url'] as $k) { if (!empty($r[$k])) return $r[$k]; } return ''; };
                ?>
                <?php if ($latestPost || $latestRecipe): ?>
                <div class="home-feature-cols reveal">
                  <?php if ($latestPost):
                      $bImg = $homeNorm($latestPost['feature_image'] ?? $latestPost['image_url'] ?? '');
                      $bUrl = '/blog/' . rawurlencode($latestPost['slug']) . '/';
                      $bWhen = !empty($latestPost['date_published']) ? $latestPost['date_published'] : ($latestPost['created_at'] ?? '');
                      $bDate = $bWhen ? date('F j, Y', strtotime($bWhen)) : '';
                      $bExc = trim(preg_replace('/\s+/', ' ', strip_tags((string)($latestPost['article'] ?? ''))));
                      $bExc = mb_strlen($bExc) > 160 ? mb_substr($bExc, 0, 160) . '…' : $bExc;
                  ?>
                  <div class="home-col">
                  <article class="home-blog-card">
                    <?php if ($bImg !== ''): ?><a class="hbc-imgwrap" href="<?= htmlspecialchars($bUrl) ?>"><img src="<?= htmlspecialchars($bImg) ?>" alt="<?= htmlspecialchars($latestPost['title']) ?>" loading="lazy" decoding="async"></a><?php endif; ?>
                    <div class="hbc-body">
                      <span class="home-eyebrow">From the Journal</span>
                      <h3><a href="<?= htmlspecialchars($bUrl) ?>"><?= htmlspecialchars($latestPost['title']) ?></a></h3>
                      <?php if ($bDate): ?><p class="hbc-date"><?= htmlspecialchars($bDate) ?></p><?php endif; ?>
                      <?php if ($bExc): ?><p class="hbc-excerpt"><?= htmlspecialchars($bExc) ?></p><?php endif; ?>
                      <a class="hbc-readmore" href="<?= htmlspecialchars($bUrl) ?>">Read full article &rarr;</a>
                    </div>
                  </article>
                  <a class="home-col-link" href="/blog/">Go to the blog &rarr;</a>
                  </div>
                  <?php endif; ?>

                  <div class="home-col">
                  <div class="home-recipe-col">
                    <?php
                    $rows = [];
                    if ($latestRecipe) { $rows[] = ['Latest Recipe', $latestRecipe]; }
                    if ($favRecipe && (!$latestRecipe || $favRecipe['id'] != $latestRecipe['id'])) { $rows[] = ['Farm Favorite', $favRecipe]; }
                    foreach ($rows as $row):
                        $label = $row[0]; $r = $row[1];
                        $ri = $homeNorm($homeRecImg($r));
                        $rUrl = '/recipes/' . rawurlencode($r['slug']) . '/';
                        $mins = (int)($r['prep_time'] ?? 0) + (int)($r['cook_time'] ?? 0);
                    ?>
                      <a class="home-recipe-row" href="<?= htmlspecialchars($rUrl) ?>">
                        <?php if ($ri !== ''): ?><img src="<?= htmlspecialchars($ri) ?>" alt="<?= htmlspecialchars($r['title']) ?>" loading="lazy" decoding="async"><?php endif; ?>
                        <div class="hrr-body">
                          <span class="home-eyebrow"><?= htmlspecialchars($label) ?></span>
                          <h3><?= htmlspecialchars($r['title']) ?></h3>
                          <?php if ($mins > 0): ?><p class="hrr-meta"><span aria-hidden="true">⏱️</span> <?= $mins ?> mins</p><?php endif; ?>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                  <a class="home-col-link" href="/recipes/">More recipes &rarr;</a>
                  </div>
                </div>
                <?php endif; ?>
        
                <div class="info-grid">
                    <div class="info-tile reveal">
                        <div class="icon" aria-hidden="true">🕐</div>
                        <h3>Farm Hours</h3>
                        <p>Tuesday – Sunday<br>10:00 AM – 4:00 PM<br>Closed Mondays</p>
                    </div>
                    <div class="info-tile reveal">
                        <div class="icon" aria-hidden="true">📍</div>
                        <h3>Location</h3>
                        <p>
                            <a rel="noopener" target="_blank" href="https://maps.google.com/?q=4132 Bat Cave Road, Old Fort, North Carolina, 28762">4132 Bat Cave Road<br>Old Fort, NC 28762</a>
                        </p>

                        <p style="margin-top:10px"><a class="cta-btn" href="/directions/">Directions →</a></p>
                    </div>
                    <div class="info-tile reveal">
                        <div class="icon" aria-hidden="true">📞</div>
                        <h3>Call Us</h3>
                        <p>
                            <a href="tel:+18282165488">(828) 216-5488</a>
                            <br>We'd love to hear from you!
                        </p>
                    </div>
                    <div class="info-tile reveal">
                        <div class="icon" aria-hidden="true">📧</div>
                        <h3>Email</h3>
                        <p>
                            <a href="mailto:support@batcaveroadfarm.com">support@batcaveroadfarm.com</a>
                            <br>We reply within 24 hours.
                        </p>
                    </div>
                </div> 
                <div class="ctaDiv">
                    <div class="chalkboard-frame">
                        <div class="chalkboard">
                            <h2 class="chalk chalk-title">Other things to do around western North Carolina</h2>
                            <hr class="chalk-rule">

                            <div class="chalk chalk-body">
                                <div class="grid-container">
                                    <div class="column">
                                        <h3 class="chalk">Planning:</h3>
                                        <ul class="chalk">
                                            <li>
                                                <a href="https://exploreasheville.com/" target="_blank" rel="noopener">
                                                    ExploreAsheville.com
                                                </a>
                                            </li>
                                            <li>
                                                <a href="https://www.destinationmcdowell.com/" target="_blank" rel="noopener">
                                                    Destination McDowell
                                                </a>
                                            </li>
                                            <li>
                                                <a href="https://www.dncr.nc.gov/visit/trip-ideas/experience-western-north-carolina" target="_blank" rel="noopener">
                                                    DNCR
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="column">
                                        <h3 class="chalk">Nearby Attractions:</h3>
                                         <ul class="chalk">
                                            <li>
                                                <a href="https://www.mgmnc.org/" target="_blank" rel="noopener">
                                                    The Mountain Gateway Museum
                                                </a>
                                            </li>
                                            <li>
                                                <a href="https://www.destinationmcdowell.com/attractions/historic-old-fort-train-depot-museum/" target="_blank" rel="noopener">
                                                    Old Fort Railroad Museum
                                                </a>
                                            </li>
                                        </ul>                                   
                                    </div>
                                    <div class="column">
                                        <h3 class="chalk">Food & Drink:</h3>
                                         <ul class="chalk">
                                            <li>
                                                <a href="https://www.modernalchemist.com/" target="_blank" rel="noopener">
                                                    Modern Alchemist
                                                </a>
                                            </li>
                                            <li>
                                                <a href="https://hillmanbeer.com/old-fort-taproom/" target="_blank" rel="noopener">
                                                    Hillmans Beer
                                                </a>
                                            </li>
                                            <li>
                                                <a href="https://www.seekercoffeenc.com/" target="_blank" rel="noopener">
                                                    Seeker Coffee
                                                </a>
                                            </li>
                                            
                                        </ul>   
                                   </div>
                            </div>

                            <p class="chalk chalk-small"></p>

                        </div>
                    </div> 
                </div>
                
            </div>
        </div>
<?php	
	include __DIR__ . '/includes/footer.php';
	
?>