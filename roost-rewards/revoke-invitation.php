<?php
// Manager-only invitation revocation. Disabled until separate release approval.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customer_auth.php';
require_once __DIR__.'/../includes/farm.php';
require_once __DIR__.'/../includes/rate_limit.php';
if (!is_logged_in()) {
    header('Location: /account/login.php?next=%2Froost-rewards%2Frevoke-invitation.php');
    exit;
}
$customer=bcrf_customer_find($pdo,current_user_id());
$verified=$customer && bcrf_customer_active($customer) && !empty($customer['email_verified_at']);
$enabled=getenv('BCRF_ROOST_INVITATION_REVOCATION_ENABLED')==='1';
$error='';
$success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled) $error='Invitation management is not open.';
    elseif (!$verified) $error='Verify your customer account email first.';
    elseif (!csrf_verify($_POST['csrf_token'] ?? null)) $error='Session expired. Please retry.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_revoke_ip',bcrf_rl_ip(),15,3600)
        || bcrf_rl_hit('roost_revoke_account',(string)current_user_id(),5,3600))
        $error='Too many attempts. Please try again later.';
    else {
        $id=filter_var($_POST['invitation_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false || $id===null || ($_POST['confirm_revoke'] ?? '')!=='yes')
            $error='Select an invitation and confirm revocation.';
        else {
            try {
                require_once __DIR__.'/../includes/roost-household-invitation-revocation.php';
                $success=bcrf_roost_revoke_household_invitation($pdo,(int)$customer['id'],(int)$id);
                if (!$success) $error='Invitation is no longer pending or cannot be revoked.';
            } catch (DomainException|InvalidArgumentException $e) {
                $error='Unable to revoke this invitation.';
            } catch (Throwable $e) {
                error_log('[Roost invitation revocation unavailable] '.get_class($e));
                $error='Invitation management temporarily unavailable.';
            }
        }
    }
}
$pageTitle='Manage Roost Rewards Invitations | Bat Cave Road Farm';
$metaDesc='Private household invitation management.';
$canonicalUrl=bcrf_farm_url().'/roost-rewards/revoke-invitation.php';
require __DIR__.'/../includes/head.php';
include __DIR__.'/../includes/header.php';
?>
<main class="content-wrap1" style="max-width:42rem;margin:2rem auto;padding:1rem">
<h1>Revoke a household invitation</h1>
<?php if (!$enabled): ?><p>Invitation management is not open yet.</p>
<?php elseif (!$verified): ?><p>Verify your account email before managing invitations.</p>
<?php else: ?>
<p>Only a verified household manager may revoke a pending invitation. Accepted invitations cannot be revoked here.</p>
<?php if ($success): ?><p role="status">Pending invitation revoked.</p><?php endif; ?>
<?php if ($error!==''): ?><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<form method="post" action="/roost-rewards/revoke-invitation.php">
<?= csrf_field() ?>
<p><label for="invitation_id">Invitation number</label>
<input id="invitation_id" type="number" name="invitation_id" min="1" step="1" required></p>
<p><label><input type="checkbox" name="confirm_revoke" value="yes" required> Revoke this pending invitation.</label></p>
<button type="submit">Revoke invitation</button>
</form>
<?php endif; ?>
<p><a href="/roost-rewards/balance.php">Return to Rewards</a></p>
</main>
<?php require __DIR__.'/../includes/footer.php'; ?>
