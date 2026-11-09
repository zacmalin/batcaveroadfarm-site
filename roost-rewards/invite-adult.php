<?php
// Manager-issued invitation, queued only. No synchronous email dispatch.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customer_auth.php';
require_once __DIR__.'/../includes/farm.php';
require_once __DIR__.'/../includes/rate_limit.php';
if (!is_logged_in()) {
    header('Location: /account/login.php?next=%2Froost-rewards%2Finvite-adult.php');
    exit;
}
$enabled=getenv('BCRF_ROOST_INVITATION_ISSUANCE_ENABLED')==='1';
$customer=bcrf_customer_find($pdo,current_user_id());
$verified=$customer && bcrf_customer_active($customer) && !empty($customer['email_verified_at']);
$error='';
$queued=false;
$manager=false;
if ($enabled && $verified) {
    try {
        require_once __DIR__.'/../includes/roost-manager-invitation-summary.php';
        $manager=bcrf_roost_manager_invitation_summary($pdo,(int)$customer['id'])!==[];
        // An eligible manager may have no invitation history yet.
        $q=$pdo->prepare("SELECT 1 FROM reward_household_members m
            JOIN reward_households h ON h.id=m.household_id AND h.status='active'
            WHERE m.customer_id=? AND m.role='manager' AND m.verification_status='verified'
              AND m.revoked_at IS NULL LIMIT 1");
        $q->execute([(int)$customer['id']]);
        $manager=(bool)$q->fetchColumn();
    } catch (Throwable $e) {
        error_log('[Roost invitation manager check unavailable] '.get_class($e));
        $error='Invitation management temporarily unavailable.';
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled) $error='Invitations are not open yet.';
    elseif (!$verified || !$manager) $error='A verified household manager account is required.';
    elseif ($error!=='') $error='Invitation management temporarily unavailable.';
    elseif (!csrf_verify($_POST['csrf_token'] ?? null)) $error='Session expired. Please retry.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_issue_ip',bcrf_rl_ip(),12,3600)
        || bcrf_rl_hit('roost_issue_account',(string)current_user_id(),5,3600))
        $error='Too many requests. Please try again later.';
    elseif (($_POST['confirm_adult'] ?? '')!=='yes') $error='Confirm the invitee is an adult.';
    else {
        try {
            $email=(string)($_POST['invitee_email'] ?? '');
            $keyEncoded=getenv('BCRF_ROOST_INVITATION_ENCRYPTION_KEY_B64');
            $keyId=getenv('BCRF_ROOST_INVITATION_ENCRYPTION_KEY_ID');
            $key=is_string($keyEncoded) ? base64_decode($keyEncoded,true) : false;
            if (!is_string($key) || strlen($key)!==32 || !is_string($keyId) ||
                !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$keyId))
                throw new RuntimeException('Invitation encryption not configured.');
            require_once __DIR__.'/../includes/roost-household-invitations.php';
            $result=bcrf_roost_create_household_invitation($pdo,(int)$customer['id'],$email,
                ['key'=>$key,'key_id'=>$keyId]);
            $queued=($result['delivery_queued']??false)===true;
        } catch (DomainException|InvalidArgumentException $e) {
            $error='Unable to invite this address. Check the address or existing invitations.';
        } catch (Throwable $e) {
            error_log('[Roost invitation issuance unavailable] '.get_class($e));
            $error='Invitation management temporarily unavailable.';
        }
    }
}
$pageTitle='Invite an Adult | Roost Rewards';
$metaDesc='Private household invitation management.';
$canonicalUrl=bcrf_farm_url().'/roost-rewards/invite-adult.php';
require __DIR__.'/../includes/head.php';
include __DIR__.'/../includes/header.php';
?>
<main class="content-wrap1" style="max-width:42rem;margin:2rem auto;padding:1rem">
<h1>Invite an adult to your household</h1>
<?php if (!$enabled): ?><p>Invitations are not open yet.</p>
<?php elseif (!$verified || !$manager): ?><p>Only verified household managers can send invitations.</p>
<?php else: ?>
<p>Invite another adult using their email address. An invitation is queued for delivery, not sent immediately. Invitations expire after seven days.</p>
<?php if ($queued): ?><p role="status">Invitation queued. Delivery may take time.</p><?php endif; ?>
<?php if ($error!==''): ?><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<form method="post" action="/roost-rewards/invite-adult.php" autocomplete="off">
<?= csrf_field() ?>
<p><label for="invitee_email">Adult's email address</label>
<input id="invitee_email" type="email" name="invitee_email" maxlength="254" required autocomplete="off"></p>
<p><label><input type="checkbox" name="confirm_adult" value="yes" required> I confirm this person is an adult and I am authorized to invite them.</label></p>
<button type="submit">Queue invitation</button>
</form>
<?php endif; ?>
<p><a href="/roost-rewards/revoke-invitation.php">Manage pending invitations</a></p>
<p><a href="/roost-rewards/balance.php">Return to Rewards</a></p>
</main>
<?php require __DIR__.'/../includes/footer.php'; ?>
