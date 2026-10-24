<?php
// jobs/apply.php
// Application form for one job: /jobs/{slug}/apply/  (rewritten to apply.php?slug=).
// GET shows the form; POST checks it and, if OK, saves and redirects to /jobs/thanks/.
// Spam guards: CSRF, hidden "website" field (at the end of the form), at least 4 seconds
// on the page, 5 applications per hour per connection, one application per email per round.
ob_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/site_info.php';
require_once __DIR__ . '/../includes/jobs/apply.php';

$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
$job = null;
if (preg_match('/^[a-z0-9-]{1,160}$/', $slug)) {
    try { $job = jobs_find_by_slug($pdo, $slug); } catch (Throwable $e) { error_log('[jobs] apply load: ' . $e->getMessage()); }
}
if (!$job) { http_response_code(404); ob_clean(); require __DIR__ . '/../404.php'; exit; }
$jobUrl = '/jobs/' . rawurlencode($job['slug']) . '/';
if (!jobs_public_accepting($job)) { header('Location: ' . $jobUrl, true, 303); exit; }

// Every question attached to the job (archiving a question only stops it being added to more jobs).
$questions = jobs_job_questions($pdo, (int)$job['id']);
$brackets  = jobs_age_brackets((int)$job['min_age']);
$tsKey     = 'jobs_form_t_' . (int)$job['id'];
$errors    = [];
$in        = [];
$formMsg   = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = $_POST;
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (!$_POST && $len > 0) {
        // The whole post was over the server limit (usually a huge file).
        $formMsg = 'That upload was too large. Résumés must be a PDF under 5 MB.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $formMsg = 'Your session timed out. Please check your answers and send again.';
    } elseif (honeypot_tripped('website')) {
        header('Location: /jobs/thanks/?j=' . rawurlencode($job['slug']), true, 303); exit;
    } elseif (time() - (int)($_SESSION[$tsKey] ?? 0) < 4) {
        $formMsg = 'That was quick. Please check your answers and press Send again.';
    } elseif (bcrf_rl_count('jobs_apply', bcrf_rl_ip(), 3600) >= 5) {
        $formMsg = 'We\'ve received several applications from this connection in the last hour. Please try again later, or email jobs@batcaveroadfarm.com.';
    } else {
        [$data, $errors] = jobs_apply_validate($job, $questions, $_POST, $_FILES['resume'] ?? null);
        if (!$errors) {
            $dup = $pdo->prepare("SELECT 1 FROM applications WHERE job_cycle_id = ? AND email = ? AND is_spam = 0 LIMIT 1");
            $dup->execute([(int)$job['open_cycle_id'], $data['email']]);
            if ($dup->fetchColumn()) {
                $formMsg = 'You\'ve already applied for this job with that email. We have it, so there\'s no need to send it again. To add anything, email jobs@batcaveroadfarm.com.';
            } else {
                try {
                    $app = jobs_apply_save($pdo, $job, $data, client_ip_hash(), 'website');
                    bcrf_rl_add('jobs_apply', bcrf_rl_ip(), 3600);
                    jobs_apply_notify($pdo, $app, $job);
                    unset($_SESSION[$tsKey]);
                    $_SESSION['jobs_thanks'] = ['slug' => $job['slug'], 'first' => $data['first_name'], 'minor' => $data['age_bracket'] !== '18_plus'];
                    header('Location: /jobs/thanks/?j=' . rawurlencode($job['slug']), true, 303);
                    exit;
                } catch (Throwable $e) {
                    error_log('[jobs] apply save: ' . $e->getMessage());
                    $formMsg = $e->getMessage() === 'closed'
                        ? 'Sorry, this job just closed and isn\'t taking applications anymore.'
                        : 'Something went wrong saving your application. Please try again, or call ' . site_phone_display() . '.';
                }
            }
        } else {
            $formMsg = 'Please fix the ' . (count($errors) === 1 ? 'item' : count($errors) . ' items') . ' marked below.';
            if (!empty($_FILES['resume']['name']) && !isset($errors['resume'])) $errors['_refile'] = 'For security, please choose your résumé file again.';
        }
    }
}
// Fill timer starts the first time the form is shown (kept across error re-shows).
if (empty($_SESSION[$tsKey])) $_SESSION[$tsKey] = time();

// ── View helpers ─────────────────────────────────────────────────────────
$v   = fn($k) => htmlspecialchars((string)($in[$k] ?? ''), ENT_QUOTES);
$err = fn($k) => isset($errors[$k]) ? '<p class="jb-help" style="color:#8f1d1d;font-weight:600" id="err-' . $k . '">' . htmlspecialchars($errors[$k]) . '</p>' : '';
$bad = fn($k) => isset($errors[$k]) ? ' bad' : '';
$aria = fn($k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
$req = '<span class="req" aria-hidden="true">*</span>';
$qin = (array)($in['q'] ?? []);
$availIn = (array)($in['availability'] ?? []);
$ageIn = (string)($in['age_bracket'] ?? (count($brackets) === 1 ? '18_plus' : ''));

$pageTitle    = 'Apply: ' . $job['title'] . ' | Bat Cave Road Farm';
$metaDesc     = 'Apply for ' . $job['title'] . ' at Bat Cave Road Farm in Old Fort, NC.';
$canonicalUrl = 'https://batcaveroadfarm.com' . $jobUrl . 'apply/';
$ogImage      = 'https://batcaveroadfarm.com/img/og-home.jpg';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.jobs.css';
$pageJS       = 'https://batcaveroadfarm.com/js/min.jobs-apply.js';
$breadcrumbs  = [['label' => 'Jobs', 'url' => '/jobs/'], ['label' => $job['title'], 'url' => $jobUrl], ['label' => 'Apply']];
header('X-Robots-Tag: noindex, follow');

require __DIR__ . '/../includes/head.php';
include __DIR__ . '/../includes/header.php';
?>
<div class="jb-wrap">
    <a class="jb-back" href="<?= $jobUrl ?>">&larr; Back to the job</a>
    <div class="jb-hero">
        <h1>Apply: <?= htmlspecialchars($job['title']) ?></h1>
        <p>It takes about 5 minutes. Fields marked * are required.</p>
    </div>

    <?php if ($formMsg !== ''): ?>
    <div class="jb-error" role="alert">
        <?= htmlspecialchars($formMsg) ?>
        <?php if (isset($errors['_refile'])): ?><br><?= htmlspecialchars($errors['_refile']) ?><?php endif; ?>
    </div>
    <?php endif; ?>

    <form id="jb-apply" class="jb-form" method="post" action="<?= $jobUrl ?>apply/" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= JOBS_UPLOAD_MAX ?>">

        <div class="jb-card">
            <h2>About you</h2>
            <div class="jb-grid">
                <div class="jb-field<?= $bad('first_name') ?>">
                    <label class="jb-label" for="f-first">First name <?= $req ?></label>
                    <input class="jb-input" id="f-first" name="first_name" maxlength="100" autocomplete="given-name" required value="<?= $v('first_name') ?>"<?= $aria('first_name') ?>>
                    <?= $err('first_name') ?>
                </div>
                <div class="jb-field<?= $bad('last_name') ?>">
                    <label class="jb-label" for="f-last">Last name <?= $req ?></label>
                    <input class="jb-input" id="f-last" name="last_name" maxlength="100" autocomplete="family-name" required value="<?= $v('last_name') ?>"<?= $aria('last_name') ?>>
                    <?= $err('last_name') ?>
                </div>
                <div class="jb-field<?= $bad('email') ?>">
                    <label class="jb-label" for="f-email">Email <?= $req ?></label>
                    <input class="jb-input" id="f-email" type="email" name="email" maxlength="190" autocomplete="email" inputmode="email" required value="<?= $v('email') ?>"<?= $aria('email') ?>>
                    <?= $err('email') ?>
                </div>
                <div class="jb-field<?= $bad('phone') ?>">
                    <label class="jb-label" for="f-phone">Phone <?= $req ?></label>
                    <input class="jb-input" id="f-phone" type="tel" name="phone" maxlength="30" autocomplete="tel" inputmode="tel" required value="<?= $v('phone') ?>"<?= $aria('phone') ?>>
                    <?= $err('phone') ?>
                </div>
            </div>
            <div class="jb-field" style="margin-top:14px">
                <label class="jb-check"><input type="checkbox" name="is_returning" value="1"<?= !empty($in['is_returning']) ? ' checked' : '' ?>> I've applied or worked at Bat Cave Road Farm before</label>
            </div>
        </div>

        <div class="jb-card">
            <h2>Your age</h2>
            <fieldset class="jb-field<?= $bad('age_bracket') ?>">
                <legend class="jb-label">How old are you? <?= $req ?></legend>
                <p class="jb-help">
                    <?php if ((int)$job['min_age'] >= 18): ?>This job is for ages 18 and older.
                    <?php else: ?>This job is open to ages <?= (int)$job['min_age'] ?> and up. If you're under 18, a parent or guardian needs to give permission below, and we'll start an NC youth employment certificate with you before your first shift.<?php endif; ?>
                    We only ask for an age group, not your birthday.
                </p>
                <div class="jb-choices">
                    <?php foreach ($brackets as $k => $lbl): ?>
                    <label class="jb-choice"><input type="radio" name="age_bracket" value="<?= $k ?>" required<?= $ageIn === $k ? ' checked' : '' ?>> <?= htmlspecialchars($lbl) ?></label>
                    <?php endforeach; ?>
                </div>
                <?= $err('age_bracket') ?>
            </fieldset>

            <?php if (count($brackets) > 1): ?>
            <div id="jb-guardian" class="jb-guardian"<?= $ageIn !== '' && $ageIn !== '18_plus' ? '' : ' hidden' ?>>
                <h3>Parent or guardian</h3>
                <p class="jb-help">We'll copy them on emails about this application.</p>
                <div class="jb-grid" style="margin-top:10px">
                    <div class="jb-field full<?= $bad('guardian_name') ?>">
                        <label class="jb-label" for="g-name">Parent or guardian's name <?= $req ?></label>
                        <input class="jb-input" id="g-name" name="guardian_name" maxlength="150" data-minor-req value="<?= $v('guardian_name') ?>"<?= $aria('guardian_name') ?>>
                        <?= $err('guardian_name') ?>
                    </div>
                    <div class="jb-field<?= $bad('guardian_phone') ?>">
                        <label class="jb-label" for="g-phone">Their phone <?= $req ?></label>
                        <input class="jb-input" id="g-phone" type="tel" name="guardian_phone" maxlength="30" inputmode="tel" data-minor-req value="<?= $v('guardian_phone') ?>"<?= $aria('guardian_phone') ?>>
                        <?= $err('guardian_phone') ?>
                    </div>
                    <div class="jb-field<?= $bad('guardian_email') ?>">
                        <label class="jb-label" for="g-email">Their email <?= $req ?></label>
                        <input class="jb-input" id="g-email" type="email" name="guardian_email" maxlength="190" inputmode="email" data-minor-req value="<?= $v('guardian_email') ?>"<?= $aria('guardian_email') ?>>
                        <?= $err('guardian_email') ?>
                    </div>
                    <div class="jb-field full<?= $bad('guardian_consent') ?>">
                        <label class="jb-check"><input type="checkbox" name="guardian_consent" value="1" data-minor-req<?= !empty($in['guardian_consent']) ? ' checked' : '' ?>> I'm this applicant's parent or guardian, and I give permission for them to apply for this job.</label>
                        <?= $err('guardian_consent') ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="jb-card">
            <h2>When you can work</h2>
            <fieldset class="jb-field<?= $bad('availability') ?>">
                <legend class="jb-label">Pick all that work for you <?= $req ?></legend>
                <div class="jb-choices">
                    <?php foreach (jobs_availability_labels() as $k => $lbl): ?>
                    <label class="jb-choice"><input type="checkbox" name="availability[]" value="<?= $k ?>"<?= in_array($k, $availIn, true) ? ' checked' : '' ?>> <?= $lbl ?></label>
                    <?php endforeach; ?>
                </div>
                <?= $err('availability') ?>
            </fieldset>
            <div class="jb-field<?= $bad('earliest_start') ?>">
                <label class="jb-label" for="f-start">Earliest start date <span class="opt">(optional)</span></label>
                <input class="jb-input" id="f-start" type="date" name="earliest_start" min="<?= jobs_today() ?>" value="<?= $v('earliest_start') ?>"<?= $aria('earliest_start') ?>>
                <?= $err('earliest_start') ?>
            </div>
            <?php if (!empty($job['schedule_notes'])): ?><p class="jb-help">Schedule for this job: <?= htmlspecialchars($job['schedule_notes']) ?></p><?php endif; ?>
        </div>

        <?php if ($questions): ?>
        <div class="jb-card">
            <h2>A few questions</h2>
            <?php foreach ($questions as $q): $qid = (int)$q['question_id']; $k = 'q' . $qid; $isReq = (int)$q['is_required']; $val = $qin[$qid] ?? null; $hid = 'q-' . $qid; ?>
                <?php if (in_array($q['type'], ['yes_no', 'single_choice', 'multi_choice'], true)): ?>
                <fieldset class="jb-field<?= $bad($k) ?>">
                    <legend class="jb-label"><?= htmlspecialchars($q['label']) ?> <?= $isReq ? $req : '<span class="opt">(optional)</span>' ?></legend>
                    <?php if ($q['help_text']): ?><p class="jb-help"><?= htmlspecialchars($q['help_text']) ?></p><?php endif; ?>
                    <div class="jb-choices">
                    <?php if ($q['type'] === 'yes_no'): ?>
                        <?php foreach (['yes' => 'Yes', 'no' => 'No'] as $ov => $ol): ?>
                        <label class="jb-choice"><input type="radio" name="q[<?= $qid ?>]" value="<?= $ov ?>"<?= $isReq ? ' required' : '' ?><?= $val === $ov ? ' checked' : '' ?>> <?= $ol ?></label>
                        <?php endforeach; ?>
                    <?php elseif ($q['type'] === 'single_choice'): ?>
                        <?php foreach ($q['options'] as $o): ?>
                        <label class="jb-choice"><input type="radio" name="q[<?= $qid ?>]" value="<?= (int)$o['id'] ?>"<?= $isReq ? ' required' : '' ?><?= (string)$val === (string)$o['id'] ? ' checked' : '' ?>> <?= htmlspecialchars($o['label']) ?></label>
                        <?php endforeach; ?>
                    <?php else: $vals = array_map('strval', (array)$val); ?>
                        <?php foreach ($q['options'] as $o): ?>
                        <label class="jb-choice"><input type="checkbox" name="q[<?= $qid ?>][]" value="<?= (int)$o['id'] ?>"<?= in_array((string)$o['id'], $vals, true) ? ' checked' : '' ?>> <?= htmlspecialchars($o['label']) ?></label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                    <?= $err($k) ?>
                </fieldset>
                <?php else: $sv = htmlspecialchars(is_string($val) ? $val : '', ENT_QUOTES); ?>
                <div class="jb-field<?= $bad($k) ?>">
                    <label class="jb-label" for="<?= $hid ?>"><?= htmlspecialchars($q['label']) ?> <?= $isReq ? $req : '<span class="opt">(optional)</span>' ?></label>
                    <?php if ($q['help_text']): ?><p class="jb-help"><?= htmlspecialchars($q['help_text']) ?></p><?php endif; ?>
                    <?php if ($q['type'] === 'long_text'): ?>
                        <textarea class="jb-textarea" id="<?= $hid ?>" name="q[<?= $qid ?>]" maxlength="4000"<?= $isReq ? ' required' : '' ?><?= $aria($k) ?>><?= $sv ?></textarea>
                    <?php elseif ($q['type'] === 'number'): ?>
                        <input class="jb-input" id="<?= $hid ?>" type="number" inputmode="decimal" step="any" name="q[<?= $qid ?>]" value="<?= $sv ?>"<?= $isReq ? ' required' : '' ?><?= $aria($k) ?>>
                    <?php elseif ($q['type'] === 'date'): ?>
                        <input class="jb-input" id="<?= $hid ?>" type="date" name="q[<?= $qid ?>]" value="<?= $sv ?>"<?= $isReq ? ' required' : '' ?><?= $aria($k) ?>>
                    <?php else: ?>
                        <input class="jb-input" id="<?= $hid ?>" name="q[<?= $qid ?>]" maxlength="300" value="<?= $sv ?>"<?= $isReq ? ' required' : '' ?><?= $aria($k) ?>>
                    <?php endif; ?>
                    <?= $err($k) ?>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="jb-card">
            <h2>Anything else</h2>
            <div class="jb-field<?= $bad('resume') ?>">
                <label class="jb-label" for="jb-resume">Résumé <span class="opt">(optional, PDF up to 5 MB)</span></label>
                <input class="jb-input" id="jb-resume" type="file" name="resume" accept="application/pdf,.pdf"<?= $aria('resume') ?>>
                <?= $err('resume') ?>
                <p class="jb-help">No résumé? No problem. Most of our jobs don't need one.</p>
            </div>
            <div class="jb-field">
                <label class="jb-label" for="f-note">Tell us about yourself <span class="opt">(optional)</span></label>
                <textarea class="jb-textarea" id="f-note" name="cover_note" maxlength="4000"><?= $v('cover_note') ?></textarea>
            </div>
            <div class="hp" aria-hidden="true">
                <label for="f-website">Leave this empty</label>
                <input id="f-website" type="text" name="website" value="" tabindex="-1" autocomplete="off">
            </div>
        </div>

        <div class="jb-submit">
            <button type="submit" class="cta-btn" data-label="Send application">Send application</button>
        </div>
        <p class="jb-note">We'll email you a copy when it's received. Bat Cave Road Farm is an equal opportunity employer. See how we use and keep applicant information in our <a href="/privacy/#jobs">privacy policy</a>.</p>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
