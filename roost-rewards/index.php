<?php
// roost-rewards/index.php — Roost Rewards for customers (lean version, Nov 9, 2026). Rules: includes/rewards.php
// Signed-in customers see their points, the rewards and their history, or join with their phone number.
// Not signed in: how it works, plus sign-in / create-account links. Closed (404-style notice) while rewards are OFF.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rewards.php';

$on = bcrf_rewards_enabled($pdo);
$cid = is_logged_in() ? current_user_id() : 0;
$msg = null;
if ($on && $cid && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $msg = ['err', 'Your session expired. Please try again.'];
    } else {
        try {
            bcrf_rewards_enroll($pdo, $cid, (string)($_POST['phone'] ?? ''), null);
            header('Location: /roost-rewards/?joined=1', true, 303);
            exit;
        } catch (BcrfRewardError $e) {
            $msg = ['err', $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[BCRF rewards join] ' . $e->getMessage());
            $msg = ['err', 'Something went wrong. Please try again, or ask us at the farm stand.'];
        }
    }
}
if (isset($_GET['joined'])) $msg = ['ok', 'You\'re in! Give your phone number at the counter to earn points.'];

$pageTitle    = 'Roost Rewards — Bat Cave Road Farm';
$metaDesc     = 'Roost Rewards: earn 5 points per dollar and 25 points per visit at Bat Cave Road Farm, and share them with your family.';
$canonicalUrl = bcrf_farm_url() . '/roost-rewards/';
$ogImage      = bcrf_farm_url() . '/img/logos/roost_rewards.jpg';
$pageCSS      = bcrf_farm_url() . '/css/min.rewards.css?v=2';
$breadcrumbs  = [['label' => 'Roost Rewards']];
require __DIR__ . '/../includes/head.php';
include __DIR__ . '/../includes/header.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$n = fn($p) => number_format((int)$p);
$tiers = $on ? bcrf_rewards_tiers($pdo, true) : [];
$m = ($on && $cid) ? bcrf_rewards_member($pdo, $cid) : null;
$bal = $m ? (int)$m['balance_points'] : 0;
$next = null;
foreach ($tiers as $t) if ((int)$t['points'] > $bal) { $next = $t; break; }
$tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
?>
<div id="page-rewards" class="page1">
  <div class="hero rr-hero">
    <div class="hero-content">
      <img class="rr-logo" src="/img/logos/roost_rewards.jpg" alt="Roost Rewards" width="240" height="240">
      <h1>Roost Rewards</h1>
      <p>Points for every visit and every dollar, shared by the whole family.</p>
    </div>
  </div>
  <div class="divider" aria-hidden="true">✦ ✦ ✦</div>

  <div class="content-wrap1">
    <div class="rr-wrap">
<?php if (!$on): ?>
      <section class="rr-card"><h2>Coming soon</h2><p>Roost Rewards isn't open yet. Check back soon, or ask us about it at the farm stand!</p></section>
<?php else: ?>
<?php if ($msg): ?>
      <p class="rr-notice<?= $msg[0] === 'err' ? ' error' : '' ?>" role="status"><?= $h($msg[1]) ?></p>
<?php endif; ?>
<?php if ($m): ?>
      <section class="rr-card rr-balance" aria-label="Your points">
        <p class="rr-hi">Hi <?= $h($m['first_name']) ?>, you have</p>
        <p class="rr-points"><?= $n($bal) ?></p>
        <p class="rr-points-label">points</p>
<?php if ($next): ?>
        <p class="rr-next"><strong><?= $n((int)$next['points'] - $bal) ?></strong> more to <?= $h($next['benefit']) ?>.</p>
<?php elseif ($tiers): ?>
        <p class="rr-next">You've got enough for any reward. Ask at the counter!</p>
<?php endif; ?>
        <p class="rr-fine">Give your phone number (ending in <?= $h(substr($m['phone_key'], -4)) ?>) at the counter. Everyone in your family can use it.</p>
      </section>
<?php endif; ?>

      <section class="rr-card">
        <h2>How it works</h2>
        <ul class="rr-ways">
          <li><span class="rr-big">5 pts</span><span class="rr-small">for every dollar you spend</span></li>
          <li><span class="rr-big">25 pts</span><span class="rr-small">every time you visit</span></li>
          <li><span class="rr-big">1 number</span><span class="rr-small">your whole family shares the points</span></li>
        </ul>
      </section>

<?php if ($tiers): ?>
      <section class="rr-card">
        <h2>Rewards</h2>
        <ul class="rr-tiers">
<?php foreach ($tiers as $t): $tp = (int)$t['points']; $ready = $m && $bal >= $tp; ?>
          <li class="rr-tier<?= $ready ? ' ready' : '' ?>">
            <span class="rr-tier-pts"><?= $n($tp) ?> pts</span>
            <span class="rr-tier-what"><?= $h($t['benefit']) ?></span>
<?php if ($m): ?>
            <span class="rr-bar" aria-hidden="true"><span style="width:<?= max(0, min(100, (int)floor($bal * 100 / $tp))) ?>%"></span></span>
            <span class="rr-tier-status"><?= $ready ? 'Ready to use!' : $n($tp - $bal) . ' to go' ?></span>
<?php endif; ?>
          </li>
<?php endforeach; ?>
        </ul>
        <p class="rr-fine">Ask at the counter to use a reward. Points expire after 12 months with no purchases or visits.</p>
      </section>
<?php endif; ?>

<?php if ($m): ?>
      <section class="rr-card">
        <h2>Recent activity</h2>
        <ul class="rr-history">
<?php $hist = bcrf_rewards_history($pdo, $cid, 20); if (!$hist): ?>
          <li><span>Nothing yet. Your points will show up here after your next visit.</span></li>
<?php endif; foreach ($hist as $l): $p = (int)$l['points']; ?>
          <li><span><?= $h((new DateTimeImmutable($l['created_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('M j, Y')) ?> · <?= $h(bcrf_rewards_type_label($l['entry_type'])) ?></span>
              <span class="<?= $p >= 0 ? 'plus' : 'minus' ?>"><?= sprintf('%+d', $p) ?></span></li>
<?php endforeach; ?>
        </ul>
      </section>
<?php elseif ($cid): ?>
      <section class="rr-card rr-join">
        <h2>Join Roost Rewards</h2>
        <p class="rr-join-lead">It's free. Enter the number you'll give at the counter, and start earning on your next visit.</p>
        <form class="rr-form" method="POST" action="/roost-rewards/">
          <?= csrf_field() ?>
          <label for="rr-phone">Mobile phone number</label>
          <input id="rr-phone" type="tel" name="phone" autocomplete="tel" inputmode="tel" required>
          <button class="rr-btn" type="submit">Join for free</button>
        </form>
        <p class="rr-fine">We use your number only to find your account at the counter.</p>
      </section>
<?php else: ?>
      <section class="rr-card rr-join">
        <h2>Join for free</h2>
        <p class="rr-join-lead">Sign in or create an account to join online and check your points any time, or just ask us to sign you up at the farm stand.</p>
        <div class="rr-actions">
          <a class="rr-btn" href="/account/register.php?next=<?= $h(rawurlencode('/roost-rewards/')) ?>">Create an account</a>
          <a class="rr-btn alt" href="/account/login.php?next=<?= $h(rawurlencode('/roost-rewards/')) ?>">Sign in</a>
        </div>
      </section>
<?php endif; ?>
<?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php';
