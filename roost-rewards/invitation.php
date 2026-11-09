<?php
// Private invitation review only. No writes, acceptance, or consent recording.
// Feature remains dormant until Rewards migrations and launch approval.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customer_auth.php';
require_once __DIR__.'/../includes/farm.php';
if (!is_logged_in()) {
    // Do not put invitation bearer tokens in login redirect URLs.
    header('Location: /account/login.php?next=%2Froost-rewards%2Finvitation.php');
    exit;
}
$token=(string)($_POST['invitation_token'] ?? '');
$preview=null;
$unavailable=false;
$customer=bcrf_customer_find($pdo,current_user_id());
$verified=$customer && bcrf_customer_active($customer) && !empty($customer['email_verified_at']);
$csrfValid=$_SERVER['REQUEST_METHOD']==='POST' && csrf_verify($_POST['csrf_token'] ?? null);
if ($csrfValid && $verified) {
    try {
        require_once __DIR__.'/../includes/roost-customer-invitation-preview.php';
        $preview=bcrf_roost_customer_invitation_preview($pdo,(int)$customer['id'],$token);
    } catch (Throwable $e) {
        error_log('[Roost invitation review unavailable] '.get_class($e));
        $unavailable=true;
    }
}
$pageTitle='Review Roost Rewards Invitation | Bat Cave Road Farm';
$metaDesc='Private Roost Rewards household invitation review.';
$canonicalUrl=bcrf_farm_url().'/roost-rewards/invitation.php';
require __DIR__.'/../includes/head.php';
include __DIR__.'/../includes/header.php';
?>
<main class="content-wrap1" style="max-width:42rem;margin:2rem auto;padding:1rem">
<h1>Household invitation</h1>
<?php if (!$verified): ?>
<p>Please verify your customer email before reviewing a household invitation.</p>
<?php elseif ($unavailable): ?>
<p>Invitation review is temporarily unavailable.</p>
<?php else: ?>
<p>Enter the invitation code supplied to you. This page does not join a household or change your account.</p>
<form method="post" action="/roost-rewards/invitation.php" autocomplete="off">
<?= csrf_field() ?>
<label for="invitation_token">Invitation code</label>
<input id="invitation_token" type="password" name="invitation_token" maxlength="64" minlength="64" pattern="[a-f0-9]{64}" required autocomplete="off" style="width:100%;max-width:32rem">
<button type="submit">Review invitation</button>
</form>
<?php if ($_SERVER['REQUEST_METHOD']==='POST'): ?>
<?php if (!$csrfValid): ?><p role="alert">Your session expired. Please try again.</p>
<?php elseif ($preview): ?>
<section aria-label="Invitation details">
<h2>Invitation found</h2>
<p>This invitation is intended for your verified customer email and is pending.</p>
<p>Expires: <?= htmlspecialchars(substr($preview['expires_at'],0,16),ENT_QUOTES,'UTF-8') ?> UTC</p>
<p>Joining is not yet available. Adult verification and explicit household consent will be required.</p>
</section>
<?php else: ?>
<p role="status">This invitation is unavailable for your account, invalid, or expired.</p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<p><a href="/roost-rewards/balance.php">Return to Rewards</a></p>
</main>
<?php require __DIR__.'/../includes/footer.php'; ?>
