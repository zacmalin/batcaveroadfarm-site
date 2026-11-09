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
$pageCSS      = bcrf_farm_url() . '/css/min.legal.css';
$breadcrumbs  = [['label' => 'Roost Rewards']];
require __DIR__ . '/../includes/head.php';
include __DIR__ . '/../includes/header.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$n = fn($p) => number_format((int)$p);
?>
<div id="page-rewards" class="page1">
  <main class="legal-content">
    <h1>Roost Rewards</h1>
<?php if (!$on): ?>
    <p>Roost Rewards isn't open yet. Check back soon!</p>
<?php else:
    if ($msg) echo '<p class="notice', $msg[0] === 'err' ? ' error' : '', '" role="status">', $h($msg[1]), '</p>';
    $tiers = bcrf_rewards_tiers($pdo, true);
    $m = $cid ? bcrf_rewards_member($pdo, $cid) : null;
    if ($m): ?>
    <p>Hi <?= $h($m['first_name']) ?>! You have</p>
    <p class="rewards-balance"><strong><?= $n($m['balance_points']) ?></strong> points</p>
    <p>Give your phone number (ending in <?= $h(substr($m['phone_key'], -4)) ?>) at the counter. Everyone in your family can use it.</p>
<?php else: ?>
    <p>Earn <strong>5 points for every dollar</strong> you spend at the farm and <strong>25 points every time you visit</strong>. Your whole family can share one account: just give the same phone number at the counter.</p>
<?php endif; ?>
<?php if ($tiers): ?>
    <h2>Rewards</h2>
    <ul>
<?php foreach ($tiers as $t): ?>
      <li><strong><?= $n($t['points']) ?> points</strong> — <?= $h($t['benefit']) ?></li>
<?php endforeach; ?>
    </ul>
    <p>Ask at the counter to use a reward. Points expire after 12 months with no purchases or visits.</p>
<?php endif; ?>
<?php if ($m): ?>
    <h2>Recent activity</h2>
    <ul>
<?php foreach (bcrf_rewards_history($pdo, $cid, 20) as $l): ?>
      <li><?= $h((new DateTimeImmutable($l['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York'))->format('M j, Y')) ?>
        — <?= $h(bcrf_rewards_type_label($l['entry_type'])) ?>: <?= sprintf('%+d', (int)$l['points']) ?></li>
<?php endforeach; ?>
    </ul>
<?php elseif ($cid): ?>
    <h2>Join</h2>
    <form method="POST" action="/roost-rewards/">
      <?= csrf_field() ?>
      <label for="rr-phone">Mobile phone number</label>
      <input id="rr-phone" type="tel" name="phone" autocomplete="tel" required>
      <button type="submit">Join Roost Rewards</button>
    </form>
    <p><small>We use your number only to find your account at the counter.</small></p>
<?php else: ?>
    <p><a href="/account/login.php?next=<?= $h(rawurlencode('/roost-rewards/')) ?>">Sign in</a> or <a href="/account/register.php">create an account</a> to join and check your points, or just ask us to sign you up at the farm stand.</p>
<?php endif;
endif; ?>
  </main>
</div>
<?php include __DIR__ . '/../includes/footer.php';
