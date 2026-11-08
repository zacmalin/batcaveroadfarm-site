<?php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
	require_once __DIR__ . '/../config.php';
	require_once __DIR__ . '/../includes/site_info.php';
	$contactHours = site_hours_summary('general');
	$contactDays = $contactHours['open_days'] ?? '';
	$contactTime = $contactHours['open_time'] ?? '';
	$contactClosed = $contactHours['closed_phrase'] ?? '';
	$pageTitle = "Contact Us - Bat Cave Road Farm";
	$metaDesc = "Reach out to Bat Cave Road Farm — your family farm in Old Fort, NC. Call " . site_phone_display('general') . " or check our current visiting hours.";
	$canonicalUrl = (bcrf_farm_url() . "/contact-us/");
	$pageJS = (bcrf_farm_url() . "/js/min.contact-us.js");
	$ogImage = (bcrf_farm_url() . "/img/og-contact.jpg");
	$pageCSS = (bcrf_farm_url() . "/css/min.contactus.css");
	$preloadImage = (bcrf_farm_url() . "/img/Mailhero.webp"); // hero photo = mobile LCP (Nov 3 perf fix)
	require('../includes/head.php');
	include('../includes/header.php');
?>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"ContactPage","name":"Contact Bat Cave Road Farm","url":"<?= bcrf_farm_url() ?>/contact-us/","description":<?= json_encode("Get in touch with Bat Cave Road Farm in Old Fort, NC — call " . site_phone_display('general') . " or visit during our published opening hours.", JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,"mainEntity":{"@id":"<?= bcrf_farm_url() ?>/#business"}}
</script>

        <div id="page-contact-us" class="page1">
          <div class="hero hero-photo">
            <span class="hero-deco" aria-hidden="true">✉️</span>
            <div class="hero-content">
              <h1>Contact Us</h1>
              <p>We'd love to hear from you. Reach out any time!</p>
              <p style="margin-top:10px;">Looking for a quick answer first? Many common questions are covered on our <a href="<?= bcrf_farm_url() ?>/faq/">FAQ page</a>.</p>
              <p style="margin-top:10px;">Planning a visit or a group event? <a class="cta-btn" href="<?= bcrf_farm_url() ?>/booking/">🎟️ Request a booking →</a></p>
            </div>
          </div>
          <div class="divider" aria-hidden="true">✦ ✦ ✦</div>
        
          <div class="content-wrap">
            <div class="info-grid">
              <div class="info-tile reveal">
                <div class="icon" aria-hidden="true">📍</div>
                <h2>Address</h2>
                <a rel="noopener" target="_blank" href="https://maps.google.com/?q=4132 Bat Cave Road, Old Fort, North Carolina, 28762">4132 Bat Cave Road<br>Old Fort, NC 28762</a>
                <p style="margin-top:10px"><a class="cta-btn" href="/directions/">Directions →</a></p>

              </div>
              <div class="info-tile reveal">
                <div class="icon" aria-hidden="true">📞</div>
                <h2>Phone</h2>
                <p><a href="tel:<?= htmlspecialchars(site_tel('general'), ENT_QUOTES) ?>"><?= htmlspecialchars(site_phone_display('general')) ?></a>
                <br>Call or text welcome</p>
              </div>
              <div class="info-tile reveal">
                <div class="icon" aria-hidden="true">📧</div>
                <h2>Email</h2>
                <p><a href="mailto:<?= htmlspecialchars(bcrf_farm_email('support'), ENT_QUOTES) ?>"><?= htmlspecialchars(bcrf_farm_email('support'), ENT_QUOTES) ?></a>
                <br>We reply within 24 hours.</p>
              </div>
              <div class="info-tile reveal">
                <div class="icon" aria-hidden="true">🕐</div>
                <h2>Hours</h2>
                <p><?= htmlspecialchars($contactDays) ?><?= $contactDays !== '' && $contactTime !== '' ? ': ' : '' ?><?= htmlspecialchars($contactTime) ?><?php if ($contactClosed !== ''): ?><br><?= htmlspecialchars($contactClosed) ?><?php endif; ?></p>
              </div>
            </div>
        
            <div class="contact-form reveal" id="contactform">
              <h2 style="font-family:'Rye',serif;font-size:1.3rem;color:var(--brown-dark);margin-bottom:24px;text-align:center;">Send Us a Message</h2>
              <form  name="contact" id="contact" action="<?= bcrf_farm_url() ?>/send-message.php" method="POST">
                  <?php require_once __DIR__ . '/../includes/auth.php'; echo csrf_field(); /* fix pack K: send-message.php requires this token */ ?>
                  <div class="hp" aria-hidden="true">
                    <label for="website">Website</label>
                    <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                  </div>
                  <div class="form-group">
                  <label for="name">Your Name<span class="required">*</span></label>
                  <input type="text" id="name" name="name" placeholder="[First & Last Name]" required>
                </div>
                <div class="form-group">
                  <label for="email">Email Address<span class="required">*</span></label>
                  <input type="email" id="email" name="email" placeholder="your@email.com" required>
                </div>
                <div class="form-group">
                  <label for="phone">Phone (optional)</label>
                  <input type="tel" id="phone" name="phone" placeholder="(111) 111-1111">
                </div>
                <div class="form-group">
                  <label for="catagory">What can we help you with?<span class="required">*</span></label>
                  <select id="catagory" name="catagory" required>
                    <option value="">— Select a topic —</option>
                    <option>Petting Zoo / Group Booking</option>
                    <option>Farmstand / CSA Inquiry</option>
                    <option>Thrift Shop / Donations</option>
                    <option>General Question</option>
                    <option>Other</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="message">Message<span class="required">*</span></label>
                  <textarea id="message" name="message" placeholder="Tell us what's on your mind…" required></textarea>
                </div>
              
                <button type="submit" class="form-submit">Send Message <span aria-hidden="true">🌿</span></button>
                </form>
            </div>
        
            <div class="feature-band reveal" style="margin-top:50px">
              <h2><span aria-hidden="true">📱</span> Follow Along</h2>
              <p>We share daily farm updates, new farmstand arrivals, animal moments, and thrift shop highlights on our social channels. Come say hello — we love our online community just as much as our in-person visitors!</p>
              <p class="social-links social-links-center" style="margin-top:16px;font-size:1.1rem;">
<?php if (!function_exists('site_socials_display')) require_once __DIR__ . '/../includes/site_info.php';
      foreach (site_socials_display() as $__s):   // from Admin → Site Info (Nov 2026) ?>
                <a class="social-link" href="<?= htmlspecialchars($__s['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener">
                    <?php if ($__s['image'] !== ''): ?><img class="socialicon" src="<?= htmlspecialchars($__s['image'], ENT_QUOTES) ?>" alt="" width="50" height="50" decoding="async" loading="lazy"><?php endif; ?><span><?= htmlspecialchars($__s['name'], ENT_QUOTES) ?></span>
                </a>
<?php endforeach; ?>
              </p>
            </div>
          </div>
        </div>
<?php if (isset($_GET['sent']) || isset($_GET['error'])):
    $bcrfErr = isset($_GET['error']);
?>
<div class="thanks-modal" id="thanksModal" role="dialog" aria-modal="true" aria-labelledby="thanksTitle">
  <div class="thanks-card">
    <button type="button" class="thanks-close" aria-label="Close">&times;</button>
    <div class="thanks-emoji" aria-hidden="true"><?= $bcrfErr ? '🌾' : '🌿' ?></div>
    <h2 id="thanksTitle"><?= $bcrfErr ? "That didn't go through" : "Message Sent!" ?></h2>
    <p><?= $bcrfErr
        ? "Sorry &mdash; something went wrong and we couldn't send your message. Please try again, or call us at " . htmlspecialchars(site_phone_display('general')) . "."
        : "Thank you for reaching out! We've received your message and will get back to you within 24 hours." ?></p>
    <a class="cta-btn" href="/contact-us/">Close</a>
  </div>
</div>
<?php endif; ?>
<?php	
	require('../includes/footer.php');
?>
	