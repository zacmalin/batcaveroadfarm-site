<?php
	http_response_code(403);
	$pageTitle    = "Access Denied (403) - Bat Cave Road Farm";
	$metaDesc     = "This area isn't open to visitors.";
	$canonicalUrl = "https://batcaveroadfarm.com/403";
	$hideBreadcrumbs = true;
	require('includes/head.php');
	include('includes/header.php');
?>
        <div id="page-403" class="page1">
          <div class="hero">
            <span class="hero-deco" aria-hidden="true">🔒</span>
            <div class="hero-content">
              <h1>This gate's closed</h1>
              <p>You don't have access to that page. <span class="nowrap">(Error 403)</span></p>
            </div>
          </div>
          <div class="divider" aria-hidden="true">✦ ✦ ✦</div>
          <div class="content-wrap1">
            <p style="text-align:center;max-width:560px;margin:0 auto 6px">If you think you reached this by mistake, head back to the farm or get in touch:</p>
            <div class="ctaDiv" style="padding-bottom:48px">
              <a class="cta-btn" href="https://batcaveroadfarm.com/">Home</a>
              <a class="cta-btn" href="https://batcaveroadfarm.com/contact-us/">Contact Us</a>
            </div>
          </div>
        </div>
<?php
	require('includes/footer.php');
?>
