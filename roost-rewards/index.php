<?php
// Roost Rewards launch information. No enrollment or balance lookup is exposed here.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
$pageTitle = 'Roost Rewards | Bat Cave Road Farm';
$metaDesc = 'Learn about the upcoming Roost Rewards program: qualifying purchases, verified visits, shared household points, and redemption.';
$canonicalUrl = bcrf_farm_url() . '/roost-rewards/';
$pageCSS = bcrf_farm_url() . '/css/min.legal.css';
$breadcrumbs = [['label' => 'Roost Rewards']];
require __DIR__ . '/../includes/head.php';
include __DIR__ . '/../includes/header.php';
?>
<div id="page-roost-rewards" class="page1">
  <div class="hero"><span class="hero-deco" aria-hidden="true">🐓</span><div class="hero-content">
    <span aria-hidden="true" style="display:block;font-size:4rem;line-height:1;margin-bottom:1rem">🐓</span>
    <h1>Roost Rewards</h1><p>Good things are coming home to roost.</p>
  </div></div>
  <div class="divider" aria-hidden="true">✦ ✦ ✦</div>
  <div class="content-wrap1"><article class="legal-doc reveal">
    <h2>Coming soon</h2>
    <p>We're getting Roost Rewards ready for our farm community. Enrollment, point earning, and redemption are not available yet. We'll announce the launch when everything is ready.</p>
    <h2>How points will work</h2>
    <ul>
      <li><strong>Shop:</strong> Earn 5 points per dollar of qualifying purchases, after discounts and before tax. Whole points are calculated once per eligible sale, with no fractional carryover.</li>
      <li><strong>Visit:</strong> Earn 25 points per eligible person for a staff-verified farm visit, up to once per operating day.</li>
      <li><strong>Share:</strong> Verified family household members can pool points. Authorized verified adults can redeem points; children can earn them.</li>
      <li><strong>Keep active:</strong> Points expire after 12 months without a qualifying purchase or verified visit.</li>
    </ul>
    <h2>Redeem rewards</h2>
    <p>Planned redemption tiers are 250, 500, 1,000, and 2,000 points. The benefits associated with each tier, qualifying categories, and exclusions will be published before enrollment opens.</p>
    <p>Refunds can reverse previously earned points and may temporarily create a negative balance.</p>
    <h2>Questions?</h2>
    <p>Visit the <a href="<?= bcrf_farm_url() ?>/faq/#roost-rewards">Roost Rewards FAQ</a>, review our <a href="<?= bcrf_farm_url() ?>/terms/#roost-rewards">program terms</a> and <a href="<?= bcrf_farm_url() ?>/privacy/#rewards">privacy information</a>, or <a href="<?= bcrf_farm_url() ?>/contact-us/">contact the farm</a>.</p>
  </article></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
