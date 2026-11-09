<?php
// Standalone, launch-gated staff verification. Not linked from navigation.
// Activation requires explicit operational approval and deployed migrations.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/rate_limit.php';
require_once __DIR__.'/../includes/roost-staff-verification-access.php';
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '')!=='administrator') {
    http_response_code(403);
    exit('Access denied.');
}
try {
    $q=$pdo->prepare('SELECT role,account_status FROM users WHERE id=? LIMIT 1');
    $q->execute([(int)$_SESSION['user_id']]);
    $staff=$q->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[Roost staff access unavailable] '.get_class($e));
    http_response_code(503);
    exit('Verification unavailable.');
}
if (!$staff || !bcrf_roost_staff_verification_access((string)$staff['role'],($staff['account_status'] ?? '')==='active')) {
    http_response_code(403);
    exit('Access denied.');
}
$enabled=getenv('BCRF_ROOST_STAFF_VERIFICATION_ENABLED')==='1';
$error='';
$success=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled) $error='Adult verification is not enabled.';
    elseif (!csrf_verify($_POST['csrf_token'] ?? null)) $error='Session expired. Reload and retry.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_staff_verification_ip',bcrf_rl_ip(),20,3600)
        || bcrf_rl_hit('roost_staff_verification_actor',(string)$_SESSION['user_id'],12,3600))
        $error='Too many attempts. Please try later.';
    elseif (($_POST['adult_evidence_confirmed'] ?? '')!=='yes')
        $error='Independent adult verification must be confirmed.';
    else {
        try {
            require_once __DIR__.'/../includes/roost-staff-adult-verification.php';
            $id=filter_var($_POST['customer_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) throw new InvalidArgumentException('Invalid customer.');
            bcrf_roost_record_staff_adult_verification($pdo,(int)$id,(int)$_SESSION['user_id'],
                (string)($_POST['method'] ?? ''),trim((string)($_POST['evidence_reference'] ?? '')));
            $success=true;
        } catch (InvalidArgumentException|DomainException $e) {
            $error='Verification rejected. Check customer eligibility and evidence.';
        } catch (Throwable $e) {
            error_log('[Roost staff verification unavailable] '.get_class($e));
            $error='Verification unavailable.';
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Roost adult verification | Staff</title></head>
<body><main style="max-width:38rem;margin:2rem auto;font:1rem system-ui;padding:1rem">
<h1>Roost Rewards adult verification</h1>
<?php if (!$enabled): ?>
<p>Verification is not enabled. No customer records can be changed here.</p>
<?php else: ?>
<p>Administrator only. Independently verify the adult before submitting. Never enter ID numbers, birth dates, or document contents.</p>
<?php if ($error!==''): ?><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<?php if ($success): ?><p role="status">Adult verification evidence recorded.</p><?php endif; ?>
<form method="post" action="/admin/roost_adult_verification.php" autocomplete="off">
<?= csrf_field() ?>
<p><label>Customer ID <input type="number" name="customer_id" min="1" required></label></p>
<p><label>Verification method <select name="method" required>
<option value="">Select method</option><option value="staff_review">Staff review</option>
<option value="in_person_id_check">In-person ID check</option></select></label></p>
<p><label>Opaque audit reference <input type="text" name="evidence_reference" maxlength="120" pattern="[A-Za-z0-9._:-]{1,120}" required></label></p>
<p><label><input type="checkbox" name="adult_evidence_confirmed" value="yes" required>
I independently verified this adult using the selected approved method.</label></p>
<button type="submit">Record verification</button>
</form>
<?php endif; ?>
<p><a href="/admin/admin.php">Return to admin</a></p>
</main></body></html>
