<?php
// includes/jobs/apply.php
// Public application: check the form, then save everything in one transaction
// (application, answers, résumé file, status history, held Not Selected email),
// then send the "received" email (guardian CC'd) and the new-applicant alert.

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/mailer.php';

if (!function_exists('jobs_apply_validate')) {

    /**
     * Check $_POST-style input for $job (a jobs_find_by_slug row) and its questions.
     * Returns [array $data, array $errors] — $errors is field => message.
     */
    function jobs_apply_validate(array $job, array $questions, array $in, ?array $file): array
    {
        $err = [];
        $s = fn($k, $max) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $max);
        $d = [];

        $d['first_name'] = $s('first_name', 100);
        $d['last_name']  = $s('last_name', 100);
        if ($d['first_name'] === '') $err['first_name'] = 'Enter your first name.';
        if ($d['last_name'] === '')  $err['last_name']  = 'Enter your last name.';

        $email = trim((string)($in['email'] ?? ''));
        $d['email'] = filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190 ? strtolower($email) : '';
        if ($d['email'] === '') $err['email'] = 'Enter a valid email address.';

        $d['phone'] = mb_substr(preg_replace('/[^0-9+\-(). ]/', '', (string)($in['phone'] ?? '')), 0, 30);
        if (strlen(preg_replace('/\D/', '', $d['phone'])) < 10) $err['phone'] = 'Enter a phone number with area code.';

        $d['is_returning'] = !empty($in['is_returning']) ? 1 : 0;

        $brackets = jobs_age_brackets((int)$job['min_age']);
        $d['age_bracket'] = (string)($in['age_bracket'] ?? '');
        if (!isset($brackets[$d['age_bracket']])) $err['age_bracket'] = 'Pick your age group.';
        $minor = isset($brackets[$d['age_bracket']]) && $d['age_bracket'] !== '18_plus';

        $d['guardian_name'] = $d['guardian_phone'] = $d['guardian_email'] = $d['guardian_consent_at'] = null;
        if ($minor) {
            $d['guardian_name']  = $s('guardian_name', 150);
            $d['guardian_phone'] = mb_substr(preg_replace('/[^0-9+\-(). ]/', '', (string)($in['guardian_phone'] ?? '')), 0, 30);
            $ge = trim((string)($in['guardian_email'] ?? ''));
            $d['guardian_email'] = filter_var($ge, FILTER_VALIDATE_EMAIL) && strlen($ge) <= 190 ? strtolower($ge) : '';
            if ($d['guardian_name'] === '') $err['guardian_name'] = 'Enter your parent or guardian\'s name.';
            if (strlen(preg_replace('/\D/', '', $d['guardian_phone'])) < 10) $err['guardian_phone'] = 'Enter your parent or guardian\'s phone number.';
            if ($d['guardian_email'] === '') $err['guardian_email'] = 'Enter your parent or guardian\'s email.';
            elseif ($d['guardian_email'] === $d['email']) $err['guardian_email'] = 'Your parent or guardian\'s email needs to be different from yours.';
            if (empty($in['guardian_consent'])) $err['guardian_consent'] = 'Your parent or guardian needs to check the permission box.';
            else $d['guardian_consent_at'] = jobs_now();
        }

        $avail = array_values(array_intersect(array_keys(jobs_availability_labels()), (array)($in['availability'] ?? [])));
        if (!$avail) $err['availability'] = 'Pick at least one time you can work.';
        $d['availability'] = json_encode($avail);

        $d['earliest_start'] = null;
        $es = trim((string)($in['earliest_start'] ?? ''));
        if ($es !== '') {
            $dt = DateTime::createFromFormat('!Y-m-d', $es);
            if (!$dt || $dt->format('Y-m-d') !== $es) $err['earliest_start'] = 'Enter a real date.';
            else $d['earliest_start'] = $es;
        }

        $d['cover_note'] = mb_substr(trim(strip_tags((string)($in['cover_note'] ?? ''))), 0, 4000) ?: null;

        // Job questions
        $answers = [];
        $knockout = false;
        $qin = (array)($in['q'] ?? []);
        foreach ($questions as $q) {
            $qid = (int)$q['question_id'];
            $key = 'q' . $qid;
            $raw = $qin[$qid] ?? null;
            $text = null; $opts = null; $hit = 0;
            switch ($q['type']) {
                case 'yes_no':
                    $v = is_string($raw) ? $raw : '';
                    if ($v === 'yes' || $v === 'no') {
                        $text = $v === 'yes' ? 'Yes' : 'No';
                        if ($q['knockout_answer'] !== 'none' && $q['knockout_answer'] === $v) $hit = 1;
                    }
                    break;
                case 'single_choice':
                case 'multi_choice':
                    $valid = [];
                    foreach ($q['options'] as $o) $valid[(int)$o['id']] = $o;
                    $picked = array_values(array_unique(array_filter(array_map('intval', (array)$raw), fn($id) => isset($valid[$id]))));
                    if ($q['type'] === 'single_choice') $picked = array_slice($picked, 0, 1);
                    if ($picked) {
                        $opts = $picked;
                        $text = implode(', ', array_map(fn($id) => $valid[$id]['label'], $picked));
                        foreach ($picked as $id) if ((int)$valid[$id]['is_knockout']) $hit = 1;
                    }
                    break;
                case 'number':
                    $v = trim((string)(is_string($raw) ? $raw : ''));
                    if ($v !== '') { if (is_numeric($v)) $text = $v; else $err[$key] = 'Enter a number.'; }
                    break;
                case 'date':
                    $v = trim((string)(is_string($raw) ? $raw : ''));
                    if ($v !== '') {
                        $dt = DateTime::createFromFormat('!Y-m-d', $v);
                        if ($dt && $dt->format('Y-m-d') === $v) $text = $v; else $err[$key] = 'Enter a real date.';
                    }
                    break;
                case 'long_text':
                    $v = mb_substr(trim(strip_tags((string)(is_string($raw) ? $raw : ''))), 0, 4000);
                    if ($v !== '') $text = $v;
                    break;
                default: // short_text
                    $v = mb_substr(trim(strip_tags((string)(is_string($raw) ? $raw : ''))), 0, 300);
                    if ($v !== '') $text = $v;
            }
            if ($text === null && (int)$q['is_required'] && !isset($err[$key])) $err[$key] = 'Please answer this question.';
            if ($hit) $knockout = true;
            if ($text !== null) {
                $answers[] = ['question_id' => $qid, 'label' => mb_substr((string)$q['label'], 0, 300), 'text' => $text,
                              'opts' => $opts ? json_encode($opts) : null, 'hit' => $hit];
            }
        }
        $d['_answers'] = $answers;
        $d['_knockout'] = $knockout;

        [$fok, $ferr, $finfo] = jobs_upload_check($file);
        if (!$fok) $err['resume'] = $ferr;
        $d['_file'] = $finfo;

        return [$d, $err];
    }

    /** True when this email has applied before, or has a site account. */
    function jobs_prior_match(PDO $pdo, string $email): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM applications WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        if ($st->fetchColumn()) return true;
        try {
            $st = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = ? LIMIT 1");
            $st->execute([$email]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    /**
     * Save a validated application. Returns the new application row (with id).
     * Throws on failure (nothing is left half-saved; an uploaded file is removed).
     */
    function jobs_apply_save(PDO $pdo, array $job, array $d, string $ipHash, string $source = 'website'): array
    {
        $now = jobs_now();
        $status = $d['_knockout'] ? 'not_selected' : 'new';
        $stored = '';
        $prior = jobs_prior_match($pdo, $d['email']) ? 1 : 0;

        $pdo->beginTransaction();
        try {
            // Re-check the round inside the transaction so a just-closed job can't take applications.
            $st = $pdo->prepare("SELECT id FROM job_cycles WHERE id = ? AND closed_at IS NULL FOR UPDATE");
            $st->execute([(int)$job['open_cycle_id']]);
            if (!$st->fetchColumn()) throw new RuntimeException('closed');

            $pdo->prepare("INSERT INTO applications
                    (job_cycle_id, job_id, first_name, last_name, email, phone, age_bracket,
                     guardian_name, guardian_phone, guardian_email, guardian_consent_at,
                     availability, earliest_start, cover_note, status, is_returning, prior_match,
                     knockout_flag, source, ip_hash, submitted_at, decided_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([(int)$job['open_cycle_id'], (int)$job['id'], $d['first_name'], $d['last_name'], $d['email'], $d['phone'], $d['age_bracket'],
                           $d['guardian_name'], $d['guardian_phone'], $d['guardian_email'], $d['guardian_consent_at'],
                           $d['availability'], $d['earliest_start'], $d['cover_note'], $status, $d['is_returning'], $prior,
                           $d['_knockout'] ? 1 : 0, mb_substr($source, 0, 50), $ipHash, $now, $d['_knockout'] ? $now : null]);
            $appId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare("INSERT INTO application_answers (application_id, question_id, question_label_snapshot, answer_text, answer_option_ids, is_knockout_hit)
                                  VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($d['_answers'] as $a) $ins->execute([$appId, $a['question_id'], $a['label'], $a['text'], $a['opts'], $a['hit']]);

            jobs_add_history($pdo, $appId, null, 'new', 'Applied on the website');
            if ($d['_knockout']) jobs_add_history($pdo, $appId, 'new', 'not_selected', 'Automatic: a screening answer did not meet the job requirements');

            if ($d['_file']) {
                $stored = jobs_upload_store($d['_file']);
                $pdo->prepare("INSERT INTO application_files (application_id, stored_name, original_name, mime, size_bytes) VALUES (?, ?, ?, 'application/pdf', ?)")
                    ->execute([$appId, $stored, $d['_file']['original'], $d['_file']['size']]);
            }

            $app = array_merge($d, ['id' => $appId, 'job_id' => (int)$job['id'], 'status' => $status, 'prior_match' => $prior]);
            // Knockout: the Not Selected email waits in the queue for Zac or Casey to approve.
            if ($d['_knockout']) jobs_queue_for_applicant($pdo, 'not_selected', $app, $job, 'Website (automatic)');

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($stored !== '') jobs_upload_delete($stored);
            throw $e;
        }
        return $app;
    }

    /** After saving: email the applicant (guardian CC) and the hiring inbox. Never throws. */
    function jobs_apply_notify(PDO $pdo, array $app, array $job): void
    {
        try { jobs_send_to_applicant($pdo, 'received', $app, $job); } catch (Throwable $e) { error_log('[jobs apply] received email: ' . $e->getMessage()); }

        try {
            $flags = [];
            if ($app['_knockout']) $flags[] = '&#9888; Screening answer missed a requirement, so it was moved to Not Selected (the email is waiting in the queue).';
            if ($app['age_bracket'] !== '18_plus') $flags[] = 'Under 18 (' . htmlspecialchars(str_replace('_', '–', $app['age_bracket'])) . '). Guardian: ' . htmlspecialchars((string)$app['guardian_name']) . '. Needs an NC youth employment certificate before the first shift.';
            if ($app['is_returning']) $flags[] = 'Says they applied or worked here before.';
            if ($app['prior_match']) $flags[] = 'Email matches an earlier application or site account.';
            if (!empty($app['_file'])) $flags[] = 'Résumé uploaded (PDF). Open it from the admin.';
            $flagHtml = $flags ? implode('<br>', $flags) : 'No flags.';
            $r = jobs_render_template($pdo, 'admin_new_applicant', array_merge(jobs_merge_vars($app, $job), ['flags' => $flagHtml]));
            if ($r) jobs_mail_send($pdo, JOBS_NOTIFY_EMAIL, $r[0], $r[1], ['application_id' => (int)$app['id'], 'template_key' => 'admin_new_applicant', 'subtitle' => 'Hiring', 'reply_to' => $app['email']]);
        } catch (Throwable $e) { error_log('[jobs apply] admin email: ' . $e->getMessage()); }
    }
}
