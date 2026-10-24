<?php
// jobs/alerts/index.php
// /jobs/alerts/ — job alert signup (Jobs pack P7). Step 1 of a double opt-in:
// nothing is saved here. We email a signed link; the signup is stored only
// when its owner taps it (jobs/alerts/confirm.php). Same reply for every
// address, so the form never reveals who is already signed up.
ob_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/jobs/alerts.php';

$cats  = [];
try { $cats = jobs_categories($pdo, true); } catch (Throwable $e) { $cats = []; }
$ENG   = jobs_engagement_labels();
$ready = jobs_alert_ready() && $cats;

$sent  = false;
$error = '';
$in    = ['email' => '', 'cats' => [], 'types' => []];
$checkInbox = 'Almost done. Check your inbox and tap the link we sent to turn on your job alerts. If you don\'t see it in a few minutes, check your spam folder.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $in = ['email' => $email, 'cats' => (array)($_POST['cats'] ?? []), 'types' => (array)($_POST['types'] ?? [])];
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (honeypot_tripped('website')) {
        $sent = true;                                   // look normal to bots
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'That email doesn\'t look right. Please check it and try again.';
    } elseif (bcrf_rl_hit('jobalert_ip', bcrf_rl_ip(), 5, 3600)) {
        $error = 'Too many signups from this connection. Please try again in an hour.';
    } elseif (bcrf_rl_hit('jobalert_email', $email, 3, 86400)) {
        $sent = true;                                   // same reply; just don't send again today
    } else {
        [$c, $t] = jobs_alert_clean_choices($pdo, $in['cats'], $in['types']);
        if (jobs_alert_send_confirm($pdo, $email, $c, $t)) $sent = true;
        else $error = 'Something went wrong on our end. Please try again in a moment.';
    }
}

ob_end_clean();
header('Cache-Control: no-store, private');

$pageTitle    = 'Job Alerts | Bat Cave Road Farm';
$metaDesc     = 'Get an email when Bat Cave Road Farm posts a job at the farmstand, thrift shop, petting zoo or farm.';
$canonicalUrl = 'https://batcaveroadfarm.com/jobs/alerts/';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.jobs.css';
$breadcrumbs  = [['label' => 'Jobs', 'url' => '/jobs/'], ['label' => 'Job alerts']];
require __DIR__ . '/../../includes/head.php';
include __DIR__ . '/../../includes/header.php';
$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$on = fn(string $k, $v) => in_array((string)$v, array_map('strval', $in[$k]), true) ? ' checked' : '';
?>
<div class="jb-wrap">
    <a class="jb-back" href="/jobs/">&larr; All jobs</a>
    <div class="jb-hero">
        <h1>Job Alerts</h1>
        <p>Get an email when we post a job. Pick what you're interested in, or leave everything unticked to hear about every opening.</p>
    </div>

<?php if (!$ready): ?>
    <div class="jb-card jb-empty"><p>Job alerts aren't available right now. Please check the <a href="/jobs/">Jobs page</a> or email <a href="mailto:jobs@batcaveroadfarm.com">jobs@batcaveroadfarm.com</a>.</p></div>
<?php elseif ($sent): ?>
    <div class="jb-card jb-ok" role="status">
        <h2>Check your email</h2>
        <p><?= $h($checkInbox) ?></p>
        <p>The link works for <?= JOBS_ALERT_LINK_DAYS ?> days.</p>
        <div class="jb-actions"><a class="cta-btn" href="/jobs/">See current jobs</a></div>
    </div>
<?php else: ?>
    <?php if ($error !== ''): ?><div class="jb-error" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <form class="jb-form" method="post" action="/jobs/alerts/" novalidate>
        <?= csrf_field() ?>
        <div class="jb-card">
            <div class="jb-field">
                <label class="jb-label" for="al-email">Email <span class="req" aria-hidden="true">*</span></label>
                <input class="jb-input" id="al-email" type="email" name="email" maxlength="190" autocomplete="email" inputmode="email" required value="<?= $h($in['email']) ?>">
            </div>
        </div>
        <div class="jb-card">
            <fieldset class="jb-field">
                <legend class="jb-label">Which areas?</legend>
                <p class="jb-help">None ticked = all areas.</p>
                <div class="jb-choices">
                    <?php foreach ($cats as $c): ?>
                    <label class="jb-choice"><input type="checkbox" name="cats[]" value="<?= (int)$c['id'] ?>"<?= $on('cats', (int)$c['id']) ?>> <?= $h($c['name']) ?><?= (int)$c['allows_minors'] ? ' <span class="jb-help">(ages 14+ welcome)</span>' : '' ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset class="jb-field" style="margin-top:16px">
                <legend class="jb-label">Which kinds of jobs?</legend>
                <p class="jb-help">None ticked = all kinds.</p>
                <div class="jb-choices">
                    <?php foreach ($ENG as $k => $l): ?>
                    <label class="jb-choice"><input type="checkbox" name="types[]" value="<?= $h($k) ?>"<?= $on('types', $k) ?>> <?= $h($l) ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </div>
        <div class="hp" aria-hidden="true"><label for="al-website">Leave this empty</label><input id="al-website" type="text" name="website" value="" tabindex="-1" autocomplete="off"></div>
        <div class="jb-submit"><button type="submit" class="cta-btn">Send me job alerts</button></div>
        <p class="jb-note">We'll email you a link to confirm. You can stop alerts anytime from the link at the bottom of every alert. Already signed up? Sign up again with the same email to change your choices.</p>
    </form>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
