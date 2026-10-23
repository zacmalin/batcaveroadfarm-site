<?php
	$pageTitle = "Contact Us - Bat Cave Road Farm";
	$metaDesc = "Reach out to Bat Cave Road Farm — your family farm in Old Fort, NC. Call (828) 216-5488 or visit Tue–Sun, 10AM–4PM.";
	$canonicalUrl = "https://batcaveroadfarm.com/contact-us/";
	$pageJS = "https://batcaveroadfarm.com/js/min.contact-us.js";
	$ogImage = "https://batcaveroadfarm.com/img/og-contact.jpg";
	$pageCSS = "https://batcaveroadfarm.com/css/min.contactus.css";
	require('../includes/head.php');
	include('../includes/header.php');
?>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"ContactPage","name":"Contact Bat Cave Road Farm","url":"https://batcaveroadfarm.com/contact-us/","description":"Get in touch with Bat Cave Road Farm in Old Fort, NC — call (828) 216-5488 or visit Tuesday through Sunday, 10AM–4PM.","mainEntity":{"@id":"https://batcaveroadfarm.com/#business"}}
</script>

        <div id="page-contact-us" class="page1">
          <div class="hero hero-photo">
            <span class="hero-deco" aria-hidden="true">✉️</span>
            <div class="hero-content">
              <h1>Contact Us</h1>
              <p>We'd love to hear from you. Reach out any time!</p>
              <p style="margin-top:10px;">Looking for a quick answer first? Many common questions are covered on our <a href="https://batcaveroadfarm.com/faq/">FAQ page</a>.</p>
              <p style="margin-top:10px;">Planning a visit or a group event? <a class="cta-btn" href="https://batcaveroadfarm.com/booking/">🎟️ Request a booking →</a></p>
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
                <p><a href="tel:+18282165488">(828) 216-5488</a>
                <br>Call or text welcome</p>
              </div>
              <div class="info-tile reveal">
                <div class="icon" aria-hidden="true">📧</div>
                <h2>Email</h2>
                <p><a href="mailto:support@batcaveroadfarm.com">support@batcaveroadfarm.com</a>
                <br>We reply within 24 hours.</p>
              </div>
              <div class="info-tile reveal">
                <div class="icon" aria-hidden="true">🕐</div>
                <h2>Hours</h2>
                <p>Tuesday – Sunday: 10:00 AM – 4:00 PM<br>
                Closed Mondays</p>
              </div>
            </div>
        
            <div class="contact-form reveal" id="contactform">
              <h2 style="font-family:'Rye',serif;font-size:1.3rem;color:var(--brown-dark);margin-bottom:24px;text-align:center;">Send Us a Message</h2>
              <form  name="contact" id="contact" action="https://batcaveroadfarm.com/send-message.php" method="POST">
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
              <p style="margin-top:16px;font-size:1.1rem;">
                <a href="https://www.facebook.com/batcaveroadfarm" target="_blank" rel="noopener">
                    <img class="socialicon" src="https://batcaveroadfarm.com/img/facebook.png" alt="Bat Cave Road Farm on Facebook" width="64" height="64" decoding="async" loading="lazy"> Facebook:
                </a>
                @BatCaveRoadFarm &nbsp;|&nbsp; 
                <a href="https://www.instagram.com/batcaveroadfarm" target="_blank" rel="noopener">
                    <img class="socialicon" src="https://batcaveroadfarm.com/img/instagram.png" alt="Bat Cave Road Farm on Instagram" width="64" height="64" decoding="async" loading="lazy"> Instagram:
                </a>
                @batcaveroadfarm
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
        ? "Sorry &mdash; something went wrong and we couldn't send your message. Please try again, or call us at (828)&nbsp;216-5488."
        : "Thank you for reaching out! We've received your message and will get back to you within 24 hours." ?></p>
    <a class="cta-btn" href="/contact-us/">Close</a>
  </div>
</div>
<?php endif; ?>
<?php	
	require('../includes/footer.php');
?>
	