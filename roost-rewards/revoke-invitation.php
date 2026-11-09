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
$pendingInvitations=[];
if ($enabled && $verified) {
    try {
        require_once __DIR__.'/../includes/roost-manager-invitation-summary.php';
        $invitationRows=bcrf_roost_manager_invitation_summary($pdo,(int)$customer['id']);
        foreach ($invitationRows as $invitation) {
            if ($invitation['display_status']==='pending') $pendingInvitations[]=$invitation;
        }
    } catch (Throwable $e) {
        error_log('[Roost invitation list unavailable] '.get_class($e));
        $error='Invitation management temporarily unavailable.';
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled) $error='Invitation management is not open.';
    elseif (!$verified) $error='Verify your customer account email first.';
    elseif ($error!=='') $error='Invitation management temporarily unavailable.';
    elseif (!csrf_verify($_POST['csrf_token'] ?? null)) $error='Session expired. Please retry.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_revoke_ip',bcrf_rl_ip(),15,3600)
        || bcrf_rl_hit('roost_revoke_account',(string)current_user_id(),5,3600))
        $error='Too many attempts. Please try again later.';
    else {
        $id=filter_var($_POST['invitation_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false || $id===null || !in_array((int)$id,array_map(static fn(array $row): int => (int)$row['id'],$pendingInvitations),true) || ($_POST['confirm_revoke'] ?? '')!=='yes')
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
<?php if (!$pendingInvitations): ?><p>No pending invitations are available to revoke.</p><?php else: ?>
<p><label for="invitation_id">Pending invitation</label>
<select id="invitation_id" name="invitation_id" required>
<option value="">Select an invitation</option>
<?php foreach ($pendingInvitations as $invitation): ?>
<option value="<?= (int)$invitation['id'] ?>">Invitation #<?= (int)$invitation['id'] ?> (expires <?= htmlspecialchars(substr((string)$invitation['expires_at'],0,10),ENT_QUOTES,'UTF-8') ?> UTC)</option>
<?php endforeach; ?>
</select></p>
<p><label><input type="checkbox" name="confirm_revoke" value="yes" required> Revoke this pending invitation.</label></p>
<button type="submit">Revoke invitation</button>
<?php endif; ?>
</form>
<?php endif; ?>
<p><a href="/roost-rewards/balance.php">Return to Rewards</a></p>
</main>
<?php require __DIR__.'/../includes/footer.php'; ?>
