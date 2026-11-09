<?php
// Customer enrollment is disabled until explicit launch approval. This route
// never accepts customer-supplied adult verification or consent flags.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customer_auth.php';
require_once __DIR__.'/../includes/farm.php';
require_once __DIR__.'/../includes/rate_limit.php';
require_once __DIR__.'/../includes/roost-consent-form-config.php';
if (!is_logged_in()) {
    header('Location: /account/login.php?next=%2Froost-rewards%2Fenroll.php');
    exit;
}
$customer=bcrf_customer_find($pdo,current_user_id());
$verified=$customer && bcrf_customer_active($customer) && !empty($customer['email_verified_at']);
$policy=bcrf_roost_consent_form_config();
$enabled=getenv('BCRF_ROOST_ENROLLMENT_ENABLED')==='1' && $policy!==null;
$error='';
$success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled) $error='Enrollment is not open.';
    elseif (!$verified) $error='Verify your account email first.';
    elseif (!csrf_verify($_POST['csrf_token'] ?? null)) $error='Session expired. Please retry.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_enroll_ip',bcrf_rl_ip(),15,3600)
        || bcrf_rl_hit('roost_enroll_account',(string)current_user_id(),5,3600))
        $error='Too many attempts. Please try again later.';
    elseif (($_POST['confirm_enrollment'] ?? '')!=='yes')
        $error='Confirm your request to enroll.';
    else {
        try {
            require_once __DIR__.'/../includes/roost-customer-consent-status.php';
            $consent=bcrf_roost_customer_consent_status($pdo,(int)$customer['id'],'rewards_enrollment');
            if (!$consent || $consent['status']!=='granted' || $consent['policy_version']!==$policy['version'])
                throw new DomainException('Current policy consent required.');
            require_once __DIR__.'/../includes/roost-verification-consent.php';
            if (!bcrf_roost_verified_adult_with_consent($pdo,(int)$customer['id'],'rewards_enrollment'))
                throw new DomainException('Staff-approved adult verification required.');
            require_once __DIR__.'/../includes/roost-household-enrollment.php';
            bcrf_roost_enroll_verified_adult($pdo,(int)$customer['id'],true,true);
            $success=true;
        } catch (DomainException|InvalidArgumentException $e) {
            $error='Enrollment is not available for this account. Confirm adult verification and current consent.';
        } catch (Throwable $e) {
            error_log('[Roost enrollment unavailable] '.get_class($e));
            $error='Enrollment temporarily unavailable.';
        }
    }
}
$pageTitle='Roost Rewards Enrollment | Bat Cave Road Farm';
$metaDesc='Private Roost Rewards enrollment.';
$canonicalUrl=bcrf_farm_url().'/roost-rewards/enroll.php';
require __DIR__.'/../includes/head.php';
include __DIR__.'/../includes/header.php';
?>
<main class="content-wrap1" style="max-width:42rem;margin:2rem auto;padding:1rem">
<h1>Roost Rewards enrollment</h1>
<?php if (!$enabled): ?><p>Enrollment is not open yet.</p>
<?php elseif (!$verified): ?><p>Verify your customer account email before enrolling.</p>
<?php elseif ($success): ?><p role="status">Your Rewards household was created.</p>
<?php else: ?>
<p>Enrollment requires staff-approved adult verification and recorded agreement to the current Rewards policy. This form cannot verify adulthood or record consent.</p>
<p>Review the <a href="<?= htmlspecialchars($policy['policy_url'],ENT_QUOTES,'UTF-8') ?>">Rewards policy</a> and <a href="/roost-rewards/consent.php?purpose=rewards_enrollment">record your consent</a> before enrolling.</p>
<?php if ($error!==''): ?><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<form method="post" action="/roost-rewards/enroll.php">
<?= csrf_field() ?>
<p><label><input type="checkbox" name="confirm_enrollment" value="yes" required> Create my Rewards household using my existing verified eligibility and consent records.</label></p>
<button type="submit">Request enrollment</button>
</form>
<?php endif; ?>
<p><a href="/roost-rewards/balance.php">Return to Rewards</a></p>
</main>
<?php require __DIR__.'/../includes/footer.php'; ?>
