<?php
// Staff-only redemption queue; disabled until approved rollout.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/rate_limit.php';
require_once __DIR__.'/../includes/roost-staff-verification-access.php';
if (empty($_SESSION['user_id']) || ($_SESSION['user_role']??'')!=='administrator') {
    http_response_code(403); exit('Access denied.');
}
try {
    $q=$pdo->prepare('SELECT role,account_status FROM users WHERE id=? LIMIT 1');
    $q->execute([(int)$_SESSION['user_id']]);
    $staff=$q->fetch(PDO::FETCH_ASSOC);
    if (!$staff || !bcrf_roost_staff_verification_access((string)$staff['role'],
        ($staff['account_status']??'')==='active')) {
        http_response_code(403); exit('Access denied.');
    }
} catch (Throwable $e) {
    error_log('[Roost redemption staff access unavailable] '.get_class($e));
    http_response_code(503); exit('Redemption review unavailable.');
}
$enabled=getenv('BCRF_ROOST_REDEMPTION_STAFF_ENABLED')==='1';
$error=''; $message=''; $requests=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled) $error='Redemption review is disabled.';
    elseif (!csrf_verify($_POST['csrf_token']??null)) $error='Session expired.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_redemption_staff_ip',bcrf_rl_ip(),30,3600)
        || bcrf_rl_hit('roost_redemption_staff_actor',(string)$_SESSION['user_id'],20,3600))
        $error='Too many attempts. Try later.';
    else {
        try {
            $id=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $decision=(string)($_POST['decision']??'');
            if (!$id || !in_array($decision,['approved','rejected','issue'],true))
                throw new InvalidArgumentException('Invalid review.');
            if ($decision==='issue') {
                if (getenv('BCRF_ROOST_REDEMPTION_ISSUANCE_ENABLED')!=='1')
                    throw new DomainException('Internal voucher issuance disabled.');
                require_once __DIR__.'/../includes/roost-redemption-issuance.php';
                $result=bcrf_roost_issue_redemption_receipt($pdo,(int)$id,(int)$_SESSION['user_id']);
                $message='Internal reward receipt '.$result['receipt'].' issued and points deducted.';
            } else {
                require_once __DIR__.'/../includes/roost-redemption-review.php';
                $result=bcrf_roost_review_redemption($pdo,(int)$id,(int)$_SESSION['user_id'],$decision);
                $message='Request #'.$result['id'].' is '.$result['status'].'. No points deducted.';
            }
        } catch (BcrfRewardError|InvalidArgumentException $e) {
            $error='Review rejected. Refresh and check request eligibility.';
        } catch (Throwable $e) {
            error_log('[Roost redemption staff review unavailable] '.get_class($e));
            $error='Redemption review temporarily unavailable.';
        }
    }
}
if ($enabled) {
    try {
        $requests=$pdo->query("SELECT r.id,r.household_id,r.customer_id,r.tier_points,
            r.status,r.requested_at,r.reviewed_at,b.title
            FROM reward_redemption_requests r
            LEFT JOIN reward_redemption_benefits b ON b.code=r.benefit_code
            ORDER BY CASE WHEN r.status='requested' THEN 0 ELSE 1 END,r.id DESC
            LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[Roost redemption queue unavailable] '.get_class($e));
        $error='Redemption queue unavailable.';
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Roost redemption queue | Staff</title></head>
<body><main style="max-width:65rem;margin:2rem auto;padding:1rem;font:1rem system-ui">
<h1>Roost Rewards redemption queue</h1>
<?php if (!$enabled): ?><p>Staff redemption review is not enabled.</p>
<?php else: ?>
<p>Review only. Approval does not reserve points. Internal receipt issuance, if separately enabled, atomically deducts points. External benefit delivery is not supported.</p>
<?php if ($error!==''): ?><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<?php if ($message!==''): ?><p role="status"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<?php if (!$requests): ?><p>No requests.</p><?php endif; ?>
<?php foreach ($requests as $request): ?>
<section style="border-bottom:1px solid #aaa;padding:1rem 0">
<h2>Request #<?= (int)$request['id'] ?>: <?= htmlspecialchars($request['title']??'Reward',ENT_QUOTES,'UTF-8') ?></h2>
<p>Household <?= (int)$request['household_id'] ?>; customer <?= (int)$request['customer_id'] ?>;
<?= number_format((int)$request['tier_points']) ?> points;
status <?= htmlspecialchars($request['status'],ENT_QUOTES,'UTF-8') ?>;
requested <?= htmlspecialchars($request['requested_at'],ENT_QUOTES,'UTF-8') ?> UTC.</p>
<?php if ($request['status']==='requested'): ?>
<form method="post" action="/admin/roost_redemption_queue.php">
<?= csrf_field() ?>
<input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
<button type="submit" name="decision" value="approved">Approve</button>
<button type="submit" name="decision" value="rejected">Reject</button>
</form>
<?php elseif ($request['status']==='approved' && getenv('BCRF_ROOST_REDEMPTION_ISSUANCE_ENABLED')==='1'): ?>
<form method="post" action="/admin/roost_redemption_queue.php">
<?= csrf_field() ?>
<input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
<button type="submit" name="decision" value="issue">Issue internal reward receipt and deduct points</button>
</form>
<?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
</main></body></html>
