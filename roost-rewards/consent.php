<?php
// Authenticated, versioned consent collection. Disabled unless launch-approved
// environment configuration is explicitly supplied. Never verifies adulthood.
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
    header('Location: /account/login.php?next=%2Froost-rewards%2Fconsent.php');
    exit;
}
$config=bcrf_roost_consent_form_config();
$customer=bcrf_customer_find($pdo,current_user_id());
$verified=$customer && bcrf_customer_active($customer) && !empty($customer['email_verified_at']);
$purpose=(string)($_POST['purpose'] ?? $_GET['purpose'] ?? '');
$allowed=['rewards_enrollment'=>'Rewards enrollment','household_join'=>'Joining a household'];
if (!isset($allowed[$purpose])) $purpose='';
$error='';
$success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$config) $error='Consent collection is not open.';
    elseif (!$verified) $error='Verify your account email first.';
    elseif (!csrf_verify($_POST['csrf_token'] ?? null)) $error='Your session expired. Please try again.';
    elseif ($purpose==='') $error='Select a consent purpose.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_consent_account',(string)current_user_id(),8,3600))
        $error='Too many attempts. Please try again later.';
    elseif (($_POST['policy_version'] ?? '')!==$config['version'])
        $error='The policy changed. Please review the current policy.';
    elseif (($_POST['agree'] ?? '')!=='yes')
        $error='You must actively agree before recording consent.';
    else {
        try {
            require_once __DIR__.'/../includes/roost-customer-consent-recording.php';
            bcrf_roost_record_customer_consent($pdo,current_user_id(),$purpose,$config['version'],true);
            $success=true;
        } catch (Throwable $e) {
            error_log('[Roost consent recording unavailable] '.get_class($e));
            $error='Unable to record your decision right now.';
        }
    }
}
$pageTitle='Roost Rewards Consent | Bat Cave Road Farm';
$metaDesc='Review and record your Roost Rewards consent.';
$canonicalUrl=bcrf_farm_url().'/roost-rewards/consent.php';
require __DIR__.'/../includes/head.php';
include __DIR__.'/../includes/header.php';
?>
<main class="content-wrap1" style="max-width:42rem;margin:2rem auto;padding:1rem">
<h1>Roost Rewards consent</h1>
<?php if (!$config): ?>
<p>Consent collection is not open yet.</p>
<?php elseif (!$verified): ?>
<p>Verify your account email before recording a Rewards decision.</p>
<?php elseif ($success): ?>
<p role="status">Your consent decision was recorded. This does not verify adulthood, enroll you, or join a household.</p>
<?php else: ?>
<?php if ($error!==''): ?><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<p>Read the <a href="<?= htmlspecialchars($config['policy_url'],ENT_QUOTES,'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Rewards policy</a> before agreeing. Consent is separate from adult verification and marketing preferences.</p>
<form method="post" action="/roost-rewards/consent.php">
<?= csrf_field() ?>
<input type="hidden" name="policy_version" value="<?= htmlspecialchars($config['version'],ENT_QUOTES,'UTF-8') ?>">
<label for="purpose">Consent purpose</label>
<select id="purpose" name="purpose" required>
<option value="">Select one</option>
<?php foreach ($allowed as $value=>$label): ?>
<option value="<?= htmlspecialchars($value,ENT_QUOTES,'UTF-8') ?>" <?= $purpose===$value?'selected':'' ?>><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></option>
<?php endforeach; ?>
</select>
<p><label><input type="checkbox" name="agree" value="yes" required> I have reviewed the linked Rewards policy (version <?= htmlspecialchars($config['version'],ENT_QUOTES,'UTF-8') ?>) and agree to the selected purpose.</label></p>
<button type="submit">Record my consent</button>
</form>
<?php endif; ?>
<p><a href="/roost-rewards/balance.php">Return to Rewards</a></p>
</main>
<?php require __DIR__.'/../includes/footer.php'; ?>
