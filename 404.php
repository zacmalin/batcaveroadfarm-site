<?php
	http_response_code(404);
	$pageTitle    = "Page Not Found (404) - Bat Cave Road Farm";
	$metaDesc     = "Sorry, we couldn't find that page. Here's the way back to the farm.";
	$canonicalUrl = "https://batcaveroadfarm.com/404";
	$hideBreadcrumbs = true;
	require('includes/head.php');
	include('includes/header.php');
?>
        <div id="page-404" class="page1">
          <div class="hero">
            <span class="hero-deco" aria-hidden="true">🐐</span>
            <div class="hero-content">
              <h1>This trail led nowhere</h1>
              <p>We couldn't find the page you were looking for. <span class="nowrap">(Error 404)</span></p>
            </div>
          </div>
          <div class="divider" aria-hidden="true">✦ ✦ ✦</div>
          <div class="content-wrap1">
            <p style="text-align:center;max-width:560px;margin:0 auto 6px">The page may have moved, or the link might have a typo. Here are some good places to go instead:</p>
            <div class="ctaDiv" style="padding-bottom:48px">
              <a class="cta-btn" href="https://batcaveroadfarm.com/">Home</a>
              <a class="cta-btn" href="https://batcaveroadfarm.com/petting-zoo/">Petting Zoo</a>
              <a class="cta-btn" href="https://batcaveroadfarm.com/farm-stand/">Farm Stand</a>
              <a class="cta-btn" href="https://batcaveroadfarm.com/thrift-shop/">Thrift Shop</a>
              <a class="cta-btn" href="https://batcaveroadfarm.com/contact-us/">Contact Us</a>
            </div>
          </div>
        </div>
<?php
	require('includes/footer.php');
?>
