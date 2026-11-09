<?php
// Customer redemption requests; default OFF pending approved launch.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customer_auth.php';
require_once __DIR__.'/../includes/farm.php';
require_once __DIR__.'/../includes/rate_limit.php';
if (!is_logged_in()) {
    header('Location: /account/login.php?next=%2Froost-rewards%2Fredeem.php');
    exit;
}
$enabled=getenv('BCRF_ROOST_REDEMPTION_REQUESTS_ENABLED')==='1';
$customer=bcrf_customer_find($pdo,current_user_id());
$verified=$customer && bcrf_customer_active($customer) && !empty($customer['email_verified_at']);
$error=''; $success=false; $benefits=[]; $householdId=0; $balance=0; $policyId=0; $history=[];
if ($enabled && $verified) {
    try {
        $q=$pdo->prepare("SELECT a.household_id,a.balance_points
            FROM reward_household_members m
            JOIN reward_households h ON h.id=m.household_id AND h.status='active'
            JOIN reward_accounts a ON a.household_id=h.id
            WHERE m.customer_id=? AND m.role IN ('manager','adult')
              AND m.can_redeem=1 AND m.verification_status='verified'
              AND m.revoked_at IS NULL LIMIT 1");
        $q->execute([(int)$customer['id']]);
        $member=$q->fetch(PDO::FETCH_ASSOC);
        if ($member) {
            $householdId=(int)$member['household_id'];
            $balance=(int)$member['balance_points'];
            $p=$pdo->query("SELECT id FROM reward_policies
                WHERE status='active' AND (effective_at IS NULL OR effective_at<=UTC_TIMESTAMP(3))
                ORDER BY effective_at DESC,id DESC LIMIT 1");
            $policyId=(int)$p->fetchColumn();
            $benefits=$pdo->query("SELECT code,title,description,tier_points
                FROM reward_redemption_benefits WHERE status='active' AND fulfillment_type='internal_voucher'
                ORDER BY tier_points,code")->fetchAll(PDO::FETCH_ASSOC);
            $h=$pdo->prepare("SELECT r.id,r.tier_points,r.status,r.requested_at,r.fulfillment_receipt,b.title
                FROM reward_redemption_requests r
                LEFT JOIN reward_redemption_benefits b ON b.code=r.benefit_code
                WHERE r.household_id=? AND r.customer_id=?
                ORDER BY r.id DESC LIMIT 20");
            $h->execute([$householdId,(int)$customer['id']]);
            $history=$h->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('[Roost redemption customer page unavailable] '.get_class($e));
        $error='Redemption is temporarily unavailable.';
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled || !$verified || !$householdId || !$policyId)
        $error='Redemption requests are not available.';
    elseif (!csrf_verify($_POST['csrf_token']??null))
        $error='Session expired. Reload and try again.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_redeem_ip',bcrf_rl_ip(),12,3600)
        || bcrf_rl_hit('roost_redeem_account',(string)current_user_id(),6,3600))
        $error='Too many requests. Please try later.';
    else {
        try {
            $code=(string)($_POST['benefit_code']??'');
            $token=(string)($_POST['request_token']??'');
            $selected=null;
            foreach ($benefits as $benefit) {
                if ($benefit['code']===$code) { $selected=$benefit; break; }
            }
            if (!$selected) throw new DomainException('Benefit unavailable.');
            require_once __DIR__.'/../includes/roost-redemption-request.php';
            bcrf_roost_request_redemption($pdo,$householdId,(int)$customer['id'],
                $policyId,(int)$selected['tier_points'],$code,$token);
            $success=true;
            $h=$pdo->prepare("SELECT r.id,r.tier_points,r.status,r.requested_at,r.fulfillment_receipt,b.title
                FROM reward_redemption_requests r
                LEFT JOIN reward_redemption_benefits b ON b.code=r.benefit_code
                WHERE r.household_id=? AND r.customer_id=?
                ORDER BY r.id DESC LIMIT 20");
            $h->execute([$householdId,(int)$customer['id']]);
            $history=$h->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[Roost redemption request rejected] '.get_class($e));
            $error='Request could not be accepted. Check eligibility and available points.';
        }
    }
}
$pageTitle='Redeem Roost Rewards | Bat Cave Road Farm';
$metaDesc='Private Roost Rewards redemption requests.';
$canonicalUrl=bcrf_farm_url().'/roost-rewards/redeem.php';
require __DIR__.'/../includes/head.php';
include __DIR__.'/../includes/header.php';
?>
<main class="content-wrap1" style="max-width:46rem;margin:2rem auto;padding:1rem">
<h1>Redeem Roost Rewards</h1>
<?php if (!$enabled): ?>
<p>Reward redemption is not open yet.</p>
<?php elseif (!$verified || !$householdId): ?>
<p>A verified, eligible adult household membership is required.</p>
<?php elseif ($error!==''): ?>
<p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p>
<?php endif; ?>
<?php if ($success): ?><p role="status">Your request was received for staff review. No points have been deducted yet.</p><?php endif; ?>
<?php if ($enabled && $verified && $householdId && $policyId): ?>
<p>Available household points: <strong><?= number_format($balance) ?></strong>. Requests are reviewed by staff and do not reserve points or guarantee a benefit.</p>
<?php if (!$benefits): ?><p>No redemption benefits are currently available.</p><?php endif; ?>
<?php foreach ($benefits as $benefit): ?>
<section>
<h2><?= htmlspecialchars($benefit['title'],ENT_QUOTES,'UTF-8') ?></h2>
<p><?= number_format((int)$benefit['tier_points']) ?> points</p>
<?php if ($benefit['description']): ?><p><?= htmlspecialchars($benefit['description'],ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<?php if ($balance >= (int)$benefit['tier_points']): ?>
<form method="post" action="/roost-rewards/redeem.php">
<?= csrf_field() ?>
<input type="hidden" name="benefit_code" value="<?= htmlspecialchars($benefit['code'],ENT_QUOTES,'UTF-8') ?>">
<input type="hidden" name="request_token" value="<?= bin2hex(random_bytes(16)) ?>">
<button type="submit">Request this reward</button>
</form>
<?php else: ?><p>Not enough points yet.</p><?php endif; ?>
</section>
<?php endforeach; ?>
<h2>Recent requests</h2>
<?php if (!$history): ?><p>No requests yet.</p><?php else: ?>
<ul><?php foreach ($history as $item): ?>
<li><?= htmlspecialchars($item['title']??'Reward',ENT_QUOTES,'UTF-8') ?>:
<?= number_format((int)$item['tier_points']) ?> points,
<?= htmlspecialchars($item['status'],ENT_QUOTES,'UTF-8') ?>
<?php if ($item['status']==='fulfilled' && !empty($item['fulfillment_receipt'])): ?>
— Voucher receipt: <code><?= htmlspecialchars($item['fulfillment_receipt'],ENT_QUOTES,'UTF-8') ?></code>
<?php endif; ?></li>
<?php endforeach; ?></ul>
<?php endif; ?>
<?php endif; ?>
<p><a href="/roost-rewards/balance.php">Back to my rewards balance</a></p>
</main>
<?php include __DIR__.'/../includes/footer.php'; ?>
