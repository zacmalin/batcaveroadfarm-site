<?php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
	$pageTitle    = "Terms of Service - Bat Cave Road Farm";
	$metaDesc     = ("The terms for using " . bcrf_farm_domain() . " and for visiting Bat Cave Road Farm in Old Fort, NC, including website use, purchases, and farm-visit safety.");
	$canonicalUrl = (bcrf_farm_url() . "/terms/");
	$pageCSS      = (bcrf_farm_url() . "/css/min.legal.css");
	$breadcrumbs  = [['label' => 'Terms of Service']];
	require('../includes/head.php');
	include('../includes/header.php');
?>
        <div id="page-terms" class="page1">
          <div class="hero">
            <span class="hero-deco" aria-hidden="true">📜</span>
            <div class="hero-content">
              <h1>Terms of Service</h1>
              <p>The ground rules for using our website and visiting the farm.</p>
            </div>
          </div>
          <div class="divider" aria-hidden="true">✦ ✦ ✦</div>

          <div class="content-wrap1">
            <article class="legal-doc reveal">
              <p class="legal-updated">Last updated: November 3, 2026</p>
              <p class="legal-intro">Welcome to Bat Cave Road Farm. These Terms of Service ("Terms") govern your
                use of <strong><?= bcrf_farm_domain() ?></strong> (the "Site") and your visit to our farm in Old Fort,
                North Carolina. By using the Site or visiting the farm, you agree to these Terms. If you don't
                agree, please don't use the Site.</p>

              <nav class="legal-toc" aria-label="On this page">
                <h2>On this page</h2>
                <ol>
                  <li><a href="#use">Using the website</a></li>
                  <li><a href="#ip">Our content</a></li>
                  <li><a href="#products">Products &amp; pricing</a></li>
                  <li><a href="#purchases">Tickets, passes &amp; bookings</a></li>
                  <li><a href="#visits">Farm visits &amp; the petting zoo</a></li>
                  <li><a href="#risk">Assumption of risk</a></li>
                  <li><a href="#disclaimer">Disclaimers</a></li>
                  <li><a href="#liability">Limitation of liability</a></li>
                  <li><a href="#links">Third-party links</a></li>
                  <li><a href="#copyright">Copyright complaints</a></li>
                  <li><a href="#changes">Changes to these Terms</a></li>
                  <li><a href="#law">Governing law</a></li>
                  <li><a href="#contact">Contact us</a></li>
                </ol>
              </nav>

              <h2 id="use">Using the website</h2>
              <p>You may use the Site for your own personal, non-commercial purposes — browsing our animals,
                farm stand, thrift shop, recipes, and information about visiting. You agree not to misuse the
                Site, including by attempting to disrupt it, access it through automated scraping, interfere
                with its security, or use it for any unlawful purpose.</p>

              <h2 id="ip">Our content</h2>
              <p>Unless noted otherwise, the text, photographs, graphics, logo, and other content on the Site
                are owned by or licensed to Bat Cave Road Farm and are protected by intellectual-property laws.
                You're welcome to share links to our pages. Please don't copy, republish, or use our content for
                commercial purposes without our written permission.</p>

              <h2 id="products">Products &amp; pricing</h2>
              <p>Our farm stand and thrift shop offerings change with the seasons and with what's available.
                Descriptions, prices, and availability shown on the Site may change at any time and may not
                always reflect current in-person stock. Thrift and second-hand goods are sold <strong>as is</strong>.
                Nothing on the Site is a guarantee that a particular item is in stock.</p>

              <h2 id="purchases">Tickets, passes &amp; bookings</h2>
              <p>Online payments are processed securely by Stripe.</p>
              <ul>
                <li><strong>Online admission tickets</strong> are non-refundable. A ticket is good for any day we're
                  open — not only the date you pick — including days when a private event is booked.</li>
                <li><strong>Annual passes</strong> are non-refundable.</li>
                <li><strong>Group and party bookings</strong> get a full refund if you cancel at least 30 days before
                  your booked date. Cancellations made less than 30 days before the date are not refunded.</li>
                <li><strong>Weather:</strong> we're open rain or shine. If the weather looks bad on the day of your
                  booking, you may reschedule to a day within two weeks after your booked date.</li>
              </ul>

              <h2 id="visits">Farm visits &amp; the petting zoo</h2>
              <p>A working farm is a hands-on environment with live animals, uneven ground, equipment, and the
                outdoors. When you visit, you agree to:</p>
              <ul>
                <li>Follow posted signs and the instructions of our staff at all times.</li>
                <li>Supervise children in your group closely — they remain your responsibility throughout the
                  visit.</li>
                <li>Treat the animals gently and only interact with them where and how we permit.</li>
                <li>Wash your hands at the stations provided after touching animals.</li>
              </ul>
              <p>North Carolina law requires us to give you the following warnings. They are also posted at the
                farm, and they apply to every visit, ticket, pass and booking.</p>
              <?php require_once __DIR__ . '/../includes/nc_warning.php'; echo bcrf_nc_warning_html(); ?>

              <h2 id="risk">Assumption of risk</h2>
              <p>You understand that interacting with farm animals and being on farm property involves inherent
                risks, including the unpredictable behavior of animals, allergic reactions, and the ordinary
                hazards of an outdoor agricultural setting. To the fullest extent permitted by law, you
                voluntarily assume these risks when you visit, on behalf of yourself and any minors in your care.</p>

              <h2 id="disclaimer">Disclaimers</h2>
              <p>The Site and its content are provided on an "as is" and "as available" basis without warranties
                of any kind, whether express or implied, to the fullest extent permitted by law. We don't
                warrant that the Site will be uninterrupted, error-free, or free of harmful components, or that
                information on it is complete or current.</p>

              <h2 id="liability">Limitation of liability</h2>
              <p>To the fullest extent permitted by North Carolina law, Bat Cave Road Farm and its owners and
                staff will not be liable for any indirect, incidental, or consequential damages arising out of
                your use of the Site. Nothing in these Terms limits any liability that cannot be limited under
                applicable law.</p>

              <h2 id="links">Third-party links</h2>
              <p>The Site may link to or embed third-party services (such as Google Maps and our Instagram feed).
                We're not responsible for the content, policies, or practices of those third parties.</p>

              <h2 id="copyright">Copyright complaints</h2>
              <p>If you believe something on the Site (including a visitor comment or photo) infringes your
                copyright, email <a href="mailto:<?= htmlspecialchars(bcrf_farm_email('support'), ENT_QUOTES) ?>"><?= htmlspecialchars(bcrf_farm_email('support'), ENT_QUOTES) ?></a> with:
                your contact details; the work you believe is infringed; the page address of the material; a
                statement that you have a good-faith belief the use isn't authorized by the owner, its agent, or the
                law; a statement, under penalty of perjury, that your notice is accurate and that you are the owner
                or authorized to act for the owner; and your physical or electronic signature. We'll review the
                notice and remove or disable material where appropriate.</p>

              <h2 id="changes">Changes to these Terms</h2>
              <p>We may update these Terms from time to time. When we do, we'll update the "Last updated" date at
                the top of this page. Continuing to use the Site after changes means you accept the updated Terms.</p>

              <h2 id="law">Governing law</h2>
              <p>These Terms are governed by the laws of the State of North Carolina, without regard to its
                conflict-of-laws rules. Any disputes will be handled in the state or federal courts located in
                McDowell County, North Carolina.</p>

              <div class="legal-contact" id="contact">
                <h2>Contact us</h2>
                <p>Questions about these Terms?</p>
                <?php
                    $__name = function_exists('site') ? site('business_name', 'Bat Cave Road Farm') : 'Bat Cave Road Farm';
                    $__addr = (function_exists('site_address') && site_address() !== '') ? site_address() : '4132 Bat Cave Road, Old Fort, NC 28762';
                    $__em   = function_exists('site_email') ? site_email('general') : (bcrf_farm_email('support'));
                    $__ph   = function_exists('site_phone_display') ? site_phone_display('general') : '(828) 216-5488';
                    $__tel  = function_exists('site_tel') ? site_tel('general') : '+18282165488';
                ?>
                <p><?= htmlspecialchars($__name) ?><br>
                   <?= htmlspecialchars($__addr) ?><br>
                   <a href="mailto:<?= htmlspecialchars($__em) ?>"><?= htmlspecialchars($__em) ?></a><br>
                   <a href="tel:<?= htmlspecialchars($__tel) ?>"><?= htmlspecialchars($__ph) ?></a></p>
              </div>
            </article>
          </div>
        </div>
<?php
	require('../includes/footer.php');
?>
