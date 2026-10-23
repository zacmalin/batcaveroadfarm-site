<?php
// directions/index.php — Map & Directions with drive times from surrounding towns.
    $pageTitle    = "Map & Directions | Bat Cave Road Farm — Old Fort, NC";
    $metaDesc     = "Directions to Bat Cave Road Farm at 4132 Bat Cave Road, Old Fort, NC 28762 — an easy drive from Asheville, Black Mountain, Marion, and across Western North Carolina, right off I-40.";
    $canonicalUrl = "https://batcaveroadfarm.com/directions/";
    $ogImage      = "https://batcaveroadfarm.com/img/og-home.jpg";
    require('../includes/head.php');
    include('../includes/header.php');

    $mapEmbed = function_exists('site_setting') ? site_setting('map_embed_url') : '';

    // Drive times — approximate, ordered nearest first
    $nearby = [
        ['Old Fort (downtown)', '10 min'],
        ['Black Mountain',      '17 min'],
        ['Swannanoa',           '21 min'],
        ['Montreat',            '21 min'],
        ['Marion',              '23 min'],
        ['Asheville',           '30 min'],
        ['Lake Lure',           '40 min'],
        ['Morganton',           '40 min'],
        ['Weaverville',         '45 min'],
        ['Chimney Rock',        '47 min'],
        ['Rutherfordton',       '45 min'],
        ['Hendersonville',      '55 min'],
        ['Hickory',             '1 hr'],
        ['Waynesville',         '1 hr 5 min'],
    ];
    $farther = [
        ['Boone, NC',          '1 hr 35 min'],
        ['Spartanburg, SC',    '1 hr 30 min'],
        ['Greenville, SC',     '1 hr 40 min'],
        ['Charlotte, NC',      '2 hr'],
        ['Johnson City, TN',   '1 hr 50 min'],
        ['Knoxville, TN',      '2 hr 15 min'],
        ['Winston-Salem, NC',  '2 hr 30 min'],
    ];
?>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"WebPage","name":"Map & Directions — Bat Cave Road Farm","url":"https://batcaveroadfarm.com/directions/","description":"Directions and drive times to Bat Cave Road Farm at 4132 Bat Cave Road, Old Fort, NC 28762.","about":{"@id":"https://batcaveroadfarm.com/#business"},"isPartOf":{"@id":"https://batcaveroadfarm.com/#website"}}
</script>
<style>
.dir-wrap{max-width:820px;margin:0 auto;padding:0 16px 64px}
.dir-map{border:2px solid #c9923a;border-radius:12px;overflow:hidden;margin:0 0 26px;box-shadow:0 8px 24px rgba(0,0,0,.35)}
.dir-map iframe{display:block;width:100%;height:380px;border:0}
.dir-address{background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;border-radius:12px;padding:22px 24px;text-align:center;margin:0 0 34px}
.dir-address .dir-gps-label{font-family:'Lora',serif;font-size:.78rem;font-weight:600;color:#8b5c2a;text-transform:uppercase;letter-spacing:.08em;margin:0 0 6px}
.dir-address .dir-gps{font-family:'Rye',Georgia,serif;font-size:1.25rem;color:#3b2010;margin:0 0 16px}
.dir-address .dir-btns{display:flex;justify-content:center;flex-wrap:wrap;gap:12px}
.dir-section{margin:0 0 30px}
.dir-section h2{font-family:'Rye',Georgia,serif;font-size:1.15rem;color:var(--cream,#f5ead6);text-shadow:1px 2px 6px rgba(0,0,0,.75);margin:0 0 12px}
.dir-route{background:rgba(245,234,214,.08);border:1px solid rgba(201,146,58,.3);border-radius:10px;padding:16px 20px;margin:0 0 14px}
.dir-route h3{font-family:'Rye',Georgia,serif;font-size:.95rem;color:var(--gold,#e8b84b);margin:0 0 8px}
.dir-route p{font-family:'Lora',serif;font-size:.9rem;line-height:1.7;color:var(--cream-dark,#e8d5b0);text-shadow:1px 1px 3px rgba(0,0,0,.6);margin:0}
.dir-times{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.dir-times-card{background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;border-radius:12px;padding:18px 22px}
.dir-times-card h3{font-family:'Rye',Georgia,serif;font-size:.95rem;color:#5c3317;margin:0 0 12px;text-align:center}
.dir-times-card table{width:100%;border-collapse:collapse;font-family:'Lora',serif;font-size:.88rem;color:#3b2010}
.dir-times-card td{padding:6px 4px;border-bottom:1px solid rgba(201,146,58,.25)}
.dir-times-card tr:last-child td{border-bottom:none}
.dir-times-card td:last-child{text-align:right;font-weight:600;color:#5c3317;white-space:nowrap}
.dir-pass-note{background:rgba(245,234,214,.08);border:1px solid rgba(201,146,58,.3);border-radius:10px;padding:16px 20px;margin:26px 0 0;text-align:center;font-family:'Lora',serif;font-size:.92rem;color:var(--cream-dark,#e8d5b0);text-shadow:1px 1px 3px rgba(0,0,0,.6);line-height:1.6}
.dir-pass-note a{color:var(--gold,#e8b84b);font-weight:600;text-decoration:underline}
@media(max-width:640px){.dir-times{grid-template-columns:1fr}.dir-map iframe{height:300px}}
</style>

<div id="page-directions" class="page1">
  <div class="hero">
    <span class="hero-deco" aria-hidden="true">🗺️</span>
    <div class="hero-content">
      <h1>Map &amp; Directions</h1>
      <p>We're an easy drive from anywhere in Western North Carolina — right off the I-40 corridor.</p>
    </div>
  </div>
  <div class="divider" aria-hidden="true">✦ ✦ ✦</div>

  <div class="dir-wrap">

    <?php if ($mapEmbed !== ''): ?>
    <div class="dir-map">
      <iframe src="<?= htmlspecialchars($mapEmbed, ENT_QUOTES) ?>"
              title="Map to Bat Cave Road Farm, Old Fort NC"
              loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
    <?php endif; ?>

    <div class="dir-address">
      <p class="dir-gps-label">Physical / GPS address</p>
      <p class="dir-gps">4132 Bat Cave Road, Old Fort, NC 28762</p>
      <div class="dir-btns">
        <a class="cta-btn" rel="noopener" target="_blank"
           href="https://maps.google.com/?q=4132+Bat+Cave+Road,+Old+Fort,+NC+28762">Open in Google Maps →</a>
        <a class="cta-btn" rel="noopener" target="_blank"
           href="https://maps.apple.com/?q=4132+Bat+Cave+Road,+Old+Fort,+NC+28762">Open in Apple Maps →</a>
      </div>
    </div>

    <div class="dir-section">
      <h2>Written directions</h2>

      <div class="dir-route">
        <h3>🏔️ From Asheville (about 35 minutes)</h3>
        <p>Take I-40 East toward Statesville. Take <strong>exit 73 for Old Fort</strong>. Follow the exit into Old Fort, then make a right and take Bat Cave Road south out of town. Continue to <strong>4132 Bat Cave Road</strong> — watch for our farm signs.</p>
      </div>

      <div class="dir-route">
        <h3>⛰️ From Marion / Morganton / Hickory (I-40 West)</h3>
        <p>Take I-40 West toward Asheville. Take <strong>exit 73 for Old Fort</strong>. Follow the exit into Old Fort, then make a left and take Bat Cave Road south out of town. Continue to <strong>4132 Bat Cave Road</strong> — watch for our farm signs.</p>
      </div>

      <div class="dir-route">
        <h3>🌄 From Black Mountain / Montreat (about 20 minutes)</h3>
        <p>Take US-70 East through the Swannanoa Gap into Old Fort — a beautiful mountain drive that skips the interstate entirely. In Old Fort, turn onto Bat Cave Road heading south and continue to <strong>4132 Bat Cave Road</strong>.</p>
      </div>

      <div class="dir-route">
        <h3>🌆 From Charlotte (about 2 hours)</h3>
        <p>Take I-85 South to US-74 West, or I-40 West via Statesville and Hickory. Stay on I-40 West past Marion, then take <strong>exit 73 for Old Fort</strong>. Follow the exit into Old Fort, make a left and take Bat Cave Road south, and continue to <strong>4132 Bat Cave Road</strong>.</p>
      </div>
    </div>

    <div class="dir-section">
      <h2>Drive times to the farm</h2>
      <div class="dir-times">
        <div class="dir-times-card">
          <h3>From nearby WNC towns</h3>
          <table>
            <?php foreach ($nearby as [$town, $time]): ?>
            <tr><td><?= htmlspecialchars($town) ?></td><td><?= htmlspecialchars($time) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
        <div class="dir-times-card">
          <h3>From surrounding cities</h3>
          <table>
            <?php foreach ($farther as [$town, $time]): ?>
            <tr><td><?= htmlspecialchars($town) ?></td><td><?= htmlspecialchars($time) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
      <div class="dir-pass-note">
        🪪 Live within a half hour? An <a href="/passes/">annual pass</a> pays for itself in just a few visits — unlimited entry for a full year.
      </div>
    </div>

    <div class="dir-section" style="text-align:center">
      <p style="font-family:'Lora',serif;color:var(--cream-dark,#e8d5b0);text-shadow:1px 1px 3px rgba(0,0,0,.6);font-size:.92rem;margin:0 0 14px">
        Free parking on site · Open Tuesday – Sunday, 10AM – 4PM · Questions? Call <a href="tel:+18282165488" style="color:var(--gold,#e8b84b)">(828) 216-5488</a>
      </p>
      <a class="cta-btn" href="/plan-your-visit/">Plan your visit →</a>
    </div>

  </div>
</div>

<?php require('../includes/footer.php'); ?>
