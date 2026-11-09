<?php
// Authenticated, launch-gated acceptance. Invitation tokens are POST-only,
// never placed in URLs, logs, or session storage.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customer_auth.php';
require_once __DIR__.'/../includes/farm.php';
require_once __DIR__.'/../includes/rate_limit.php';
require_once __DIR__.'/../includes/roost-consent-form-config.php';
if (!is_logged_in()) {
    header('Location: /account/login.php?next=%2Froost-rewards%2Faccept-invitation.php');
    exit;
}
$customer=bcrf_customer_find($pdo,current_user_id());
$verified=$customer && bcrf_customer_active($customer) && !empty($customer['email_verified_at']);
$policy=bcrf_roost_consent_form_config();
$enabled=getenv('BCRF_ROOST_INVITATION_ACCEPTANCE_ENABLED')==='1' && $policy!==null;
$error='';
$success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled) $error='Household joining is not open.';
    elseif (!$verified) $error='Verify your account email first.';
    elseif (!csrf_verify($_POST['csrf_token'] ?? null)) $error='Session expired. Please retry.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_accept_ip',bcrf_rl_ip(),15,3600)
        || bcrf_rl_hit('roost_accept_account',(string)current_user_id(),5,3600))
        $error='Too many attempts. Please try again later.';
    elseif (($_POST['confirm_join'] ?? '')!=='yes') $error='Confirm your request to join.';
    else {
        try {
            $token=(string)($_POST['invitation_token'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/D',$token))
                throw new DomainException('Invalid invitation.');
            require_once __DIR__.'/../includes/roost-customer-consent-status.php';
            $consent=bcrf_roost_customer_consent_status($pdo,(int)$customer['id'],'household_join');
            if (!$consent || $consent['status']!=='granted' || $consent['policy_version']!==$policy['version'])
                throw new DomainException('Current policy consent required.');
            require_once __DIR__.'/../includes/roost-verification-consent.php';
            if (!bcrf_roost_verified_adult_with_consent($pdo,(int)$customer['id'],'household_join'))
                throw new DomainException('Staff-approved adult verification required.');
            require_once __DIR__.'/../includes/roost-household-invitation-acceptance.php';
            bcrf_roost_accept_household_invitation($pdo,$token,(int)$customer['id'],true,true);
            $success=true;
        } catch (DomainException|InvalidArgumentException $e) {
            $error='Unable to join. Confirm the invitation, adult verification, and current consent.';
        } catch (Throwable $e) {
            error_log('[Roost invitation acceptance unavailable] '.get_class($e));
            $error='Household joining temporarily unavailable.';
        }
    }
}
$pageTitle='Join Roost Rewards Household | Bat Cave Road Farm';
$metaDesc='Private Roost Rewards household invitation acceptance.';
$canonicalUrl=bcrf_farm_url().'/roost-rewards/accept-invitation.php';
require __DIR__.'/../includes/head.php';
include __DIR__.'/../includes/header.php';
?>
<main class="content-wrap1" style="max-width:42rem;margin:2rem auto;padding:1rem">
<h1>Join a Roost Rewards household</h1>
<?php if (!$enabled): ?><p>Household joining is not open yet.</p>
<?php elseif (!$verified): ?><p>Verify your customer account email before joining.</p>
<?php elseif ($success): ?><p role="status">You have joined the household. Redemption permissions remain subject to household policy.</p>
<?php else: ?>
<p>Joining requires a valid invitation for your verified email, staff-approved adult verification, and recorded consent to the current policy.</p>
<p>Review the <a href="<?= htmlspecialchars($policy['policy_url'],ENT_QUOTES,'UTF-8') ?>">Rewards policy</a> and <a href="/roost-rewards/consent.php?purpose=household_join">record household-join consent</a> before proceeding.</p>
<?php if ($error!==''): ?><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<form method="post" action="/roost-rewards/accept-invitation.php" autocomplete="off">
<?= csrf_field() ?>
<p><label for="invitation_token">Invitation code</label>
<input id="invitation_token" type="password" name="invitation_token" minlength="64" maxlength="64" pattern="[a-f0-9]{64}" required autocomplete="off"></p>
<p><label><input type="checkbox" name="confirm_join" value="yes" required> I agree to join the invited household using my existing verification and consent records.</label></p>
<button type="submit">Join household</button>
</form>
<?php endif; ?>
<p><a href="/roost-rewards/invitation.php">Review invitation</a></p>
</main>
<?php require __DIR__.'/../includes/footer.php'; ?>
