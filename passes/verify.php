<?php
// passes/verify.php — staff gate verification page.
// Opened when staff scans a passholder's QR code.
// Anyone with the link sees whether the pass is valid (the unguessable 48-char token is the key).
// Only signed-in staff (includes/pass_staff.php) see the "Log this visit" button (Nov 2026).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/includes/permission_checks.php';
require_once __DIR__ . '/../includes/pass_staff.php';
$canLog = bcrf_pass_can_log();

$token = trim((string)($_GET['token'] ?? ''));
$pass  = null; $pt = null; $visitCount = 0; $lastVisit = null;

if ($token !== '') {
    try {
        $st = $pdo->prepare("SELECT p.*, t.label, t.max_guests FROM passes p
                              JOIN pass_types t ON t.id = p.pass_type_id
                              WHERE p.token = ? LIMIT 1");
        $st->execute([$token]);
        $pass = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($pass) {
            // Check if expired (date-based, regardless of status field)
            if ($pass['status'] === 'active' && $pass['valid_until'] && $pass['valid_until'] < date('Y-m-d')) {
                $pdo->prepare("UPDATE passes SET status='expired' WHERE id=?")->execute([$pass['id']]);
                $pass['status'] = 'expired';
            }
            $vs = $pdo->prepare("SELECT COUNT(*), MAX(visited_at) FROM pass_visits WHERE pass_id=?");
            $vs->execute([$pass['id']]);
            [$visitCount, $lastVisit] = $vs->fetch(PDO::FETCH_NUM) ?: [0, null];
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

// Determine visual state
$isValid   = $pass && $pass['status'] === 'active';
$isExpired = $pass && in_array($pass['status'], ['expired', 'cancelled', 'suspended'], true);
$isPending = $pass && $pass['status'] === 'pending_payment';
$cardClass = $isValid ? 'valid' : ($isPending ? 'pending' : 'invalid');
$emoji     = $isValid ? '✅' : ($isPending ? '⏳' : '❌');
$verdict   = $isValid ? 'VALID PASS' : ($isPending ? 'PAYMENT PENDING' : strtoupper($pass ? $pass['status'] : 'NOT FOUND'));

// CSRF for log visit (staff only)
$logCsrf = $canLog ? ($_SESSION['pass_log_csrf'] ?? ($_SESSION['pass_log_csrf'] = bin2hex(random_bytes(16)))) : '';

// Result of the last "Log this visit" tap
$notice = null;
if (!empty($_GET['logged'])) $notice = ['ok', 'Visit logged.'];
else switch ((string)($_GET['msg'] ?? '')) {
    case 'already':  $notice = ['warn', 'Already logged in the last 5 minutes, so it wasn\'t logged again.']; break;
    case 'signin':   $notice = ['warn', 'Sign in as staff to log visits.']; break;
    case 'csrf':     $notice = ['warn', 'That page was out of date. Tap Log this visit again.']; break;
    case 'inactive': $notice = ['warn', 'This pass isn\'t active, so no visit was logged.']; break;
    case 'error':    $notice = ['warn', 'Something went wrong; the visit wasn\'t logged. Try again.']; break;
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Pass Verification — Bat Cave Road Farm</title>
<link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://batcaveroadfarm.com/css/min.passes.css?v=<?= bcrf_asset_v('/css/min.passes.css') ?>">
</head><body style="background:#f5ead6;margin:0;padding:16px">

<div class="verify-wrap">
    <?php if ($notice): ?>
    <p role="status" style="font-family:'Lora',serif;font-size:.9rem;text-align:center;margin:0 0 12px;padding:10px 12px;border-radius:10px;<?= $notice[0] === 'ok' ? 'background:#e3f0dc;color:#2f5a22' : 'background:#fbefd2;color:#7a5200' ?>"><?= htmlspecialchars($notice[1]) ?></p>
    <?php endif; ?>
    <?php if (!$pass): ?>
    <div class="verify-card invalid">
        <div class="verify-status">❌</div>
        <div class="verify-verdict">NOT FOUND</div>
        <p style="font-family:'Lora',serif;color:#991b1b;font-size:.9rem">This QR code is not valid or may have expired.</p>
    </div>
    <?php else: ?>
    <div class="verify-card <?= $cardClass ?>">
        <div class="verify-status"><?= $emoji ?></div>
        <div class="verify-verdict"><?= htmlspecialchars($verdict) ?></div>
        <div class="verify-holder"><?= htmlspecialchars($pass['holder_name']) ?></div>
        <div class="verify-type"><?= htmlspecialchars($pass['label']) ?></div>

        <?php if ($pass['valid_until']): ?>
        <div class="verify-expiry">
            <?= $isValid ? 'Valid until ' : 'Expired ' ?>
            <strong><?= date('M j, Y', strtotime($pass['valid_until'])) ?></strong>
        </div>
        <?php endif; ?>

        <?php if ($isValid): ?>
        <div class="verify-guests">Up to <?= (int)$pass['max_guests'] ?> guest<?= $pass['max_guests'] > 1 ? 's' : '' ?> this visit</div>

        <?php if ($canLog): ?>
        <form method="POST" action="/passes/log_visit.php">
            <input type="hidden" name="token"     value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
            <input type="hidden" name="log_csrf"  value="<?= htmlspecialchars($logCsrf, ENT_QUOTES) ?>">
            <button type="submit" class="verify-log-btn">✓ Log this visit</button>
        </form>
        <?php else: ?>
        <p style="font-family:'Lora',serif;font-size:.85rem;color:#6b5a48;margin:12px 0 0">Staff: <a href="/admin/login.php" style="color:#8b5c2a">sign in</a> to log this visit, then scan the pass again.</p>
        <?php endif; ?>
        <?php endif; ?>

        <div class="verify-visits">
            <?php if ($visitCount > 0): ?>
            <?= (int)$visitCount ?> visit<?= $visitCount != 1 ? 's' : '' ?> logged
            <?php if ($lastVisit): ?> · last <?= date('M j', strtotime($lastVisit)) ?><?php endif; ?>
            <?php else: ?>
            No visits logged yet
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <p style="text-align:center;margin-top:16px;font-family:'Lora',serif;font-size:.8rem;color:#8b5c2a">
        <a href="/admin/admin.php?view=passes" style="color:#8b5c2a">Admin pass lookup →</a>
    </p>
</div>
</body></html>
