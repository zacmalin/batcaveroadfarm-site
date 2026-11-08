<?php if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
      if (!function_exists('site_socials_display')) require_once __DIR__ . '/site_info.php';   // social links from Admin → Site Info (Nov 2026) ?>        </main>
 	<footer>
        <div class="footer-inner">
            <div class="footer-cols">
                <!-- LEFT: Contact Info -->
                <div class="footer-left">
                    <div class="footer-logo"><span aria-hidden="true">🌿</span> Bat Cave Road Farm</div>
                    <p>
                        <a rel="noopener" target="_blank" href="https://maps.google.com/?q=4132 Bat Cave Road, Old Fort, North Carolina, 28762">4132 Bat Cave Road<br>Old Fort, NC 28762</a><br>
                        <a href="tel:<?= htmlspecialchars(site_tel('general'), ENT_QUOTES) ?>"><?= htmlspecialchars(site_phone_display('general')) ?></a><br>
                        <a href="mailto:<?= htmlspecialchars(bcrf_farm_email('support'), ENT_QUOTES) ?>"><?= htmlspecialchars(bcrf_farm_email('support'), ENT_QUOTES) ?></a>
                    </p>
                    © 2026 Bat Cave Road Farm - All Rights Reserved
                </div>
                <!-- RIGHT: Social Links -->
                <div class="footer-right">
                    <div class="chalkboard-frame">
                        <div class="chalkboard">
                            <h2 class="chalk chalk-title">Follow us on social media!</h2>
                            <hr class="chalk-rule">

                            <p class="chalk chalk-body social-links">
<?php foreach (site_socials_display() as $__s): ?>
                                <a class="social-link" href="<?= htmlspecialchars($__s['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                                    <?php if ($__s['image'] !== ''): ?><img class="socialicon" src="<?= htmlspecialchars($__s['image'], ENT_QUOTES) ?>" alt="" width="50" height="50" decoding="async" loading="lazy"><?php endif; ?><span><?= htmlspecialchars($__s['name'], ENT_QUOTES) ?></span>
                                </a>
<?php endforeach; ?>
                            </p>

                            <p class="chalk chalk-small">…or get farm news by email</p>
                            <div class="chalk-subscribe-wrap">
                              <button type="button" class="chalk-subscribe" data-subscribe-open><span aria-hidden="true">📬</span> Join our mailing list</button>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
            <nav aria-label="Footer">
            <p>
            	<a href="<?= bcrf_farm_url() ?>/">Home</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/plan-your-visit/"> Plan Your Visit</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/directions/"> Directions</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/booking/"> Book a Visit</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/tickets/"> Day Tickets</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/passes/"> Annual Passes</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/private-events/"> Private Events</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/petting-zoo/"> Petting Zoo</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/farm-stand/"> Farmstand</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/thrift-shop/"> Thrift Shop</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/shop/"> Shop</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/recipes/"> Recipes</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/blog/"> Blog</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/events/"> Events</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/about/"> About</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/contact-us/"> Contact Us</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/roost-rewards/"> Roost Rewards</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/faq/"> FAQ</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/jobs/"> Jobs</a> <span aria-hidden="true">✦</span>
            	<a href="<?= bcrf_farm_url() ?>/gallery/"> Gallery</a>
            </p>
            </nav>
            <p class="footer-legal"><a href="<?= bcrf_farm_url() ?>/privacy/">Privacy Policy</a> <span aria-hidden="true">·</span> <a href="<?= bcrf_farm_url() ?>/terms/">Terms of Service</a> <span aria-hidden="true">·</span> <a href="<?= bcrf_farm_url() ?>/accessibility/">Accessibility</a></p>
            </div>
    </footer>

    <div id="cookie-notice" class="cookie-notice" hidden role="region" aria-label="Cookie notice">
      <p>We use cookies for basic analytics to see how visitors use our site. By continuing, you accept this — see our <a href="<?= bcrf_farm_url() ?>/privacy/">Privacy Policy</a>.</p>
      <button type="button" class="cookie-dismiss" id="cookie-dismiss">Got it</button>
    </div>

    <div id="subscribe-modal" class="sub-modal" hidden role="dialog" aria-modal="true" aria-labelledby="sub-modal-title">
      <div class="sub-modal-card">
        <button type="button" class="sub-modal-close" id="subscribe-close" aria-label="Close">&times;</button>
        <h2 id="sub-modal-title"><span aria-hidden="true">📬</span> Join our mailing list</h2>
        <p class="sub-modal-lead">Fresh harvests, baked goods, and new baby animals — straight to your inbox. No spam.</p>
        <div class="subscribe-msg" id="subscribe-msg" hidden role="status"></div>
        <form id="subscribe-form" class="sub-form">
          <label for="sub-modal-email">Email address</label>
          <input id="sub-modal-email" type="email" name="email" required placeholder="name@domain.com" autocomplete="email">
          <label class="sub-check"><input type="checkbox" name="track_nursery" value="1" checked> <span>New baby animal updates</span></label>
          <label class="sub-check"><input type="checkbox" name="track_inventory" value="1" checked> <span>Fresh harvest &amp; bakery alerts</span></label>
          <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
          <button type="submit" class="cta-btn sub-submit">Subscribe</button>
        </form>
      </div>
    </div>

        <!-- Fix pack M: site-wide scripts now load from js/min.site.js (in head.php) -->
<?php
	 if (isset($pageJS)):
	   foreach ((array)$pageJS as $__pjs):
?>
    <script defer src="<?php echo $__pjs; ?>?v=<?= bcrf_asset_v((string)$__pjs) ?>"></script>
<?php
	   endforeach;
	endif; 
?>





    </body>
</html>