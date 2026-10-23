<?php
	$pageTitle    = "Accessibility - Bat Cave Road Farm";
	$metaDesc     = "Bat Cave Road Farm's commitment to keeping batcaveroadfarm.com accessible, the steps we've taken, and how to tell us about any barriers you run into.";
	$canonicalUrl = "https://batcaveroadfarm.com/accessibility/";
	$pageCSS      = "https://batcaveroadfarm.com/css/min.legal.css";
	$breadcrumbs  = [['label' => 'Accessibility']];
	require('../includes/head.php');
	include('../includes/header.php');
?>
        <div id="page-accessibility" class="page1">
          <div class="hero">
            <span class="hero-deco" aria-hidden="true">♿</span>
            <div class="hero-content">
              <h1>Accessibility</h1>
              <p>We want everyone to be able to use our website.</p>
            </div>
          </div>
          <div class="divider" aria-hidden="true">✦ ✦ ✦</div>

          <div class="content-wrap1">
            <article class="legal-doc reveal">
              <p class="legal-updated">Last reviewed: June 9, 2026</p>
              <p class="legal-intro">Bat Cave Road Farm is committed to making
                <strong>batcaveroadfarm.com</strong> usable for as many people as possible, including
                visitors who use screen readers, keyboard navigation, or other assistive technology. Accessibility
                is an ongoing effort for us, and we welcome your feedback.</p>

              <nav class="legal-toc" aria-label="On this page">
                <h2>On this page</h2>
                <ol>
                  <li><a href="#standard">The standard we aim for</a></li>
                  <li><a href="#measures">What we've done</a></li>
                  <li><a href="#ongoing">Ongoing work &amp; limitations</a></li>
                  <li><a href="#help">Need help or found a barrier?</a></li>
                  <li><a href="#contact">Contact us</a></li>
                </ol>
              </nav>

              <h2 id="standard">The standard we aim for</h2>
              <p>We use the Web Content Accessibility Guidelines (WCAG) 2.1, Level AA, as our guide. These are
                the internationally recognized guidelines for making web content more accessible. We aim to
                meet them throughout the site, and we treat accessibility as an ongoing process of improvement
                rather than a one-time task — so while we strive to follow them, we can't guarantee that every
                page meets every guideline at all times.</p>

              <h2 id="measures">What we've done</h2>
              <p>Steps we've taken to make the site easier to use include:</p>
              <ul>
                <li>A "skip to content" link so keyboard users can jump straight past the menu.</li>
                <li>Full keyboard operability for interactive parts of the site — the navigation menu, photo
                  lightboxes, and expandable FAQ answers can all be used without a mouse.</li>
                <li>Visible focus outlines so you can always see where you are on the page when tabbing through it.</li>
                <li>Meaningful headings, landmarks, and ARIA labels to help screen readers understand the
                  structure of each page.</li>
                <li>Descriptive alternative text for meaningful images, with decorative graphics hidden from
                  assistive technology.</li>
                <li>A responsive layout that adapts to zoom and to phones, tablets, and desktops.</li>
                <li>Attention to color contrast for text against its background.</li>
              </ul>

              <h2 id="ongoing">Ongoing work &amp; limitations</h2>
              <p>We continue to review and improve the site over time. A few areas are partly outside our direct
                control: some embedded third-party content — such as the Instagram feed on our gallery page and
                the Google map on our contact page — is provided by other companies and may not fully meet the
                same standards. If any of that content gets in your way, please let us know and we'll help you get
                the same information another way.</p>

              <h2 id="help">Need help or found a barrier?</h2>
              <p>If you have trouble accessing any part of this website, or you'd like information from the site in
                a different format, we want to hear from you — and we'll do our best to help promptly. When you
                reach out, it helps if you can tell us:</p>
              <ul>
                <li>The page or feature you were trying to use (a web address is great if you have it).</li>
                <li>What happened, and what you were trying to do.</li>
                <li>The device, browser, or assistive technology you were using, if you know it.</li>
              </ul>
              <p>You can always reach us another way, too — by phone or in person at the farm — and we're glad to
                read information aloud, take an order, or answer questions directly if that's easier for you.</p>

              <div class="legal-contact" id="contact">
                <h2>Contact us</h2>
                <p>To report an accessibility barrier or request help:</p>
                <p>Bat Cave Road Farm<br>
                   4132 Bat Cave Road, Old Fort, NC 28762<br>
                   <a href="mailto:accessibility@batcaveroadfarm.com">accessibility@batcaveroadfarm.com</a><br>
                   <a href="tel:+18282165488">(828) 216-5488</a></p>
              </div>
            </article>
          </div>
        </div>
<?php
	require('../includes/footer.php');
?>
