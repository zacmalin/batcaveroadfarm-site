<?php
// Administrator-only Rewards benefit configuration. No automatic publication.
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
    http_response_code(503); exit('Benefits configuration unavailable.');
}
$enabled=getenv('BCRF_ROOST_BENEFITS_ADMIN_ENABLED')==='1';
$error=''; $message='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$enabled) $error='Benefit configuration is disabled.';
    elseif (!csrf_verify($_POST['csrf_token']??null)) $error='Session expired.';
    elseif (bcrf_rl_dir()==='' || bcrf_rl_hit('roost_benefits_admin',(string)$_SESSION['user_id'],20,3600))
        $error='Too many attempts. Try later.';
    else {
        try {
            $code=trim((string)($_POST['code']??''));
            $title=trim((string)($_POST['title']??''));
            $description=trim((string)($_POST['description']??''));
            $tier=filter_var($_POST['tier_points']??null,FILTER_VALIDATE_INT);
            $action=(string)($_POST['action']??'draft');
            if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/',$code) ||
                $title==='' || mb_strlen($title)>120 || mb_strlen($description)>2000 ||
                !in_array($tier,[250,500,1000,2000],true) ||
                !in_array($action,['draft','activate','retire'],true))
                throw new InvalidArgumentException('Invalid benefit configuration.');
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT status FROM reward_redemption_benefits WHERE code=? FOR UPDATE');
            $q->execute([$code]);
            $old=$q->fetchColumn();
            if ($old!==false && $old==='active' && $action!=='retire')
                throw new DomainException('Retire an active benefit before changing it.');
            if ($old===false && $action==='retire')
                throw new DomainException('Benefit does not exist.');
            if ($action==='activate') {
                $q=$pdo->prepare("SELECT COUNT(*) FROM reward_redemption_benefits
                    WHERE tier_points=? AND status='active' AND code<>?");
                $q->execute([$tier,$code]);
                if ((int)$q->fetchColumn()>0)
                    throw new DomainException('Tier already has an active benefit.');
            }
            $status=$action==='activate'?'active':($action==='retire'?'retired':'draft');
            $q=$pdo->prepare("INSERT INTO reward_redemption_benefits
                (code,tier_points,title,description,fulfillment_type,status,approved_by_staff_id,
                 approved_at,created_at,updated_at)
                VALUES (?,?,?,?,'internal_voucher',?,?,IF(?='active',UTC_TIMESTAMP(3),NULL),
                    UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))
                ON DUPLICATE KEY UPDATE tier_points=VALUES(tier_points),title=VALUES(title),
                    description=VALUES(description),fulfillment_type=VALUES(fulfillment_type),
                    status=VALUES(status),approved_by_staff_id=VALUES(approved_by_staff_id),
                    approved_at=VALUES(approved_at),updated_at=UTC_TIMESTAMP(3)");
            $approver=$status==='active'?(int)$_SESSION['user_id']:null;
            $q->execute([$code,$tier,$title,$description,$status,$approver,$status]);
            $pdo->commit();
            $message='Benefit '.$code.' saved as '.$status.'.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Roost benefit configuration rejected] '.get_class($e));
            $error='Unable to save benefit. Check the tier, code, and existing active benefits.';
        }
    }
}
$benefits=[];
if ($enabled) {
    try {
        $benefits=$pdo->query("SELECT code,tier_points,title,description,status,approved_at
            FROM reward_redemption_benefits ORDER BY tier_points,code")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $error='Benefits unavailable.'; }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Rewards benefits | Staff</title></head>
<body><main style="max-width:55rem;margin:2rem auto;padding:1rem;font:1rem system-ui">
<h1>Roost Rewards benefits</h1>
<p><a href="/admin/roost_redemption_queue.php">Redemption queue</a></p>
<?php if (!$enabled): ?><p>Benefit configuration is disabled.</p><?php else: ?>
<p>Configure internal voucher benefits. Activating a benefit requires administrator approval here; customer redemption requests and issuance remain separately disabled until rollout.</p>
<?php if ($error): ?><p role="alert"><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<?php if ($message): ?><p role="status"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<p><label>Code <input name="code" maxlength="64" pattern="[a-z][a-z0-9_]{2,63}" required></label></p>
<p><label>Tier <select name="tier_points"><?php foreach ([250,500,1000,2000] as $tier): ?><option value="<?= $tier ?>"><?= $tier ?> points</option><?php endforeach; ?></select></label></p>
<p><label>Benefit title <input name="title" maxlength="120" required></label></p>
<p><label>Description <textarea name="description" maxlength="2000"></textarea></label></p>
<p><button name="action" value="draft">Save draft</button> <button name="action" value="activate">Approve and activate</button> <button name="action" value="retire">Retire</button></p>
</form>
<h2>Configured benefits</h2>
<?php foreach ($benefits as $b): ?>
<p><strong><?= htmlspecialchars($b['title'],ENT_QUOTES,'UTF-8') ?></strong>
(<?= (int)$b['tier_points'] ?> points) — <?= htmlspecialchars($b['status'],ENT_QUOTES,'UTF-8') ?>;
code <?= htmlspecialchars($b['code'],ENT_QUOTES,'UTF-8') ?></p>
<?php endforeach; ?>
<?php endif; ?></main></body></html>
