<?php
// includes/jobs/retention.php
// Record keeping for Hiring (Jobs pack P8).
//
// Federal rule (EEOC, 29 CFR 1602.14): keep applications and résumés at least
// 1 year from the decision; for people you hired, 1 year after their job ends.
// After that, "Clear old records" removes the personal details:
//   - résumé files are deleted from the server,
//   - answers, private notes and held emails are deleted,
//   - name/email/phone/guardian/cover note are wiped on the application,
//   - the sent-email log keeps the date and template but loses the address,
//   - what stays: job, round, status, age group and dates (for your own counts).
// Spam is deleted outright after 30 days. Stopped job-alert signups are
// deleted after 90 days. Nothing here runs by itself; an administrator taps it.

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/emails.php';   // pipeline (jobs_actor, history), mailer, template catalog
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/hire.php';     // youth certificate count for the checks

if (!function_exists('jobs_retention_months_options')) {

    if (!defined('JOBS_RETAIN_MIN_MONTHS')) define('JOBS_RETAIN_MIN_MONTHS', 12);
    if (!defined('JOBS_SPAM_DAYS'))         define('JOBS_SPAM_DAYS', 30);
    if (!defined('JOBS_ALERT_STOP_DAYS'))   define('JOBS_ALERT_STOP_DAYS', 90);
    if (!defined('JOBS_PURGE_BATCH'))       define('JOBS_PURGE_BATCH', 100);

    function jobs_retention_months_options(): array { return [12 => '1 year', 18 => '18 months', 24 => '2 years', 36 => '3 years']; }

    function jobs_retention_months($v): int
    {
        $v = (int)$v;
        return isset(jobs_retention_months_options()[$v]) ? $v : JOBS_RETAIN_MIN_MONTHS;
    }

    /** Eastern 'Y-m-d H:i:s' that many months/days ago. */
    function jobs_ago(int $months = 0, int $days = 0): string
    {
        $d = new DateTime(jobs_now(), new DateTimeZone('America/New_York'));
        if ($months) $d->modify('-' . $months . ' months');
        if ($days)   $d->modify('-' . $days . ' days');
        return $d->format('Y-m-d H:i:s');
    }

    /**
     * SQL for the date each application's clock starts from (NULL = keep for now):
     *   Not Selected / Withdrawn  the decision date
     *   Hired                     the last day of their job from this application
     *                             (NULL while any job from it is still going)
     *   Talent Pool               last change, only if $pool (you keep these on purpose)
     *   New/Reviewing/Interview/Offer  the day the round closed (NULL while open)
     */
    function jobs_retention_clock_sql(bool $pool): string
    {
        return "CASE
            WHEN a.status IN ('not_selected','withdrawn') THEN COALESCE(a.decided_at, cy.closed_at)
            WHEN a.status = 'hired' THEN (SELECT CASE WHEN COUNT(*) = 0 THEN a.decided_at
                                                     WHEN SUM(e.end_date IS NULL) > 0 THEN NULL
                                                     ELSE CONCAT(MAX(e.end_date), ' 23:59:59') END
                                           FROM user_employment e WHERE e.application_id = a.id)
            WHEN a.status = 'talent_pool' THEN " . ($pool ? "(SELECT MAX(h.changed_at) FROM application_status_history h WHERE h.application_id = a.id)" : "NULL") . "
            ELSE cy.closed_at END";
    }

    /** Applications past the keep period: [ids], plus count/oldest when $countOnly. */
    function jobs_retention_due(PDO $pdo, int $months, bool $pool, int $limit = 0): array
    {
        $sql = "SELECT * FROM (SELECT a.id, a.status, (" . jobs_retention_clock_sql($pool) . ") AS clock
                  FROM applications a JOIN job_cycles cy ON cy.id = a.job_cycle_id
                 WHERE a.is_spam = 0 AND a.anonymized_at IS NULL) t
                 WHERE t.clock IS NOT NULL AND t.clock < ? ORDER BY t.clock, t.id" . ($limit > 0 ? ' LIMIT ' . (int)$limit : '');
        $st = $pdo->prepare($sql);
        $st->execute([jobs_ago($months)]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Numbers for the Records screen. */
    function jobs_retention_summary(PDO $pdo, int $months, bool $pool): array
    {
        $due = jobs_retention_due($pdo, $months, $pool);
        $by  = [];
        foreach ($due as $r) $by[$r['status']] = ($by[$r['status']] ?? 0) + 1;
        $ids = array_map(fn($r) => (int)$r['id'], $due);
        $files = 0;
        if ($ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $files += (int)$pdo->query('SELECT COUNT(*) FROM application_files WHERE application_id IN (' . implode(',', $chunk) . ')')->fetchColumn();
            }
        }
        $poolCount = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'talent_pool' AND is_spam = 0 AND anonymized_at IS NULL")->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE is_spam = 1 AND submitted_at < ?');
        $st->execute([jobs_ago(0, JOBS_SPAM_DAYS)]);
        $spam = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM job_alert_subscribers WHERE unsubscribed_at IS NOT NULL AND unsubscribed_at < ?');
        $st->execute([jobs_ago(0, JOBS_ALERT_STOP_DAYS)]);
        $alerts = (int)$st->fetchColumn();
        $cleared = (int)$pdo->query('SELECT COUNT(*) FROM applications WHERE anonymized_at IS NOT NULL')->fetchColumn();
        return [
            'due' => count($due), 'by_status' => $by, 'files' => $files, 'oldest' => $due[0]['clock'] ?? null,
            'pool_total' => $poolCount, 'spam' => $spam, 'alerts' => $alerts, 'orphans' => count(jobs_orphan_files($pdo)),
            'cleared' => $cleared,
        ];
    }

    /** Remove one application's personal details. Returns stored file names to delete after commit. */
    function jobs_anonymize_one(PDO $pdo, int $appId, int $months, string $uname, ?int $uid): array
    {
        $st = $pdo->prepare('SELECT stored_name FROM application_files WHERE application_id = ?');
        $st->execute([$appId]);
        $files = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $now = jobs_now();
        foreach (['DELETE FROM application_files WHERE application_id = ?',
                  'DELETE FROM application_answers WHERE application_id = ?',
                  'DELETE FROM application_notes WHERE application_id = ?',
                  'DELETE FROM email_queue WHERE application_id = ?',
                  'UPDATE application_status_history SET note = NULL WHERE application_id = ?',
                  "UPDATE email_log SET to_email = 'removed', cc_email = NULL, subject = '(details removed)', error = NULL WHERE application_id = ?"] as $q) {
            $pdo->prepare($q)->execute([$appId]);
        }
        $pdo->prepare("UPDATE applications SET first_name = 'Removed', last_name = 'applicant', email = CONCAT('removed-', id, '@invalid'),
                              phone = NULL, guardian_name = NULL, guardian_phone = NULL, guardian_email = NULL,
                              availability = NULL, earliest_start = NULL, cover_note = NULL, ip_hash = NULL,
                              interview_location = NULL, anonymized_at = ?, archived_at = COALESCE(archived_at, ?)
                        WHERE id = ? AND anonymized_at IS NULL")
            ->execute([$now, $now, $appId]);
        jobs_add_history($pdo, $appId, null, (string)$pdo->query('SELECT status FROM applications WHERE id = ' . $appId)->fetchColumn(),
            'Personal details removed (past the ' . $months . '-month keep period)', $uid, $uname);
        return $files;
    }

    /** Clear the next batch. Returns [cleared, files_deleted, left]. */
    function jobs_retention_purge(PDO $pdo, int $months, bool $pool): array
    {
        $months = max(JOBS_RETAIN_MIN_MONTHS, $months);
        [$uid, $uname] = jobs_actor();
        $done = 0; $gone = 0;
        foreach (jobs_retention_due($pdo, $months, $pool, JOBS_PURGE_BATCH) as $r) {
            $pdo->beginTransaction();
            try {
                $files = jobs_anonymize_one($pdo, (int)$r['id'], $months, $uname, $uid);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[jobs retention] app ' . $r['id'] . ': ' . $e->getMessage());
                continue;
            }
            $done++;
            foreach ($files as $f) { if (jobs_upload_path((string)$f) !== '') { jobs_upload_delete((string)$f); $gone++; } }
        }
        $left = count(jobs_retention_due($pdo, $months, $pool));
        if ($done && function_exists('erp_audit')) {
            erp_audit($pdo, 'update', 'applications', 0, 'Hiring records: cleared personal details on ' . $done . ' application' . ($done === 1 ? '' : 's')
                . ' (' . $gone . ' résumé file' . ($gone === 1 ? '' : 's') . ' deleted; keep period ' . $months . ' months' . ($pool ? ', Talent Pool included' : '') . ')');
        }
        return [$done, $gone, $left];
    }

    /** Delete spam older than 30 days outright. Returns [deleted, left]. */
    function jobs_spam_purge(PDO $pdo): array
    {
        $st = $pdo->prepare('SELECT id FROM applications WHERE is_spam = 1 AND submitted_at < ? ORDER BY id LIMIT ' . (JOBS_PURGE_BATCH * 2));
        $st->execute([jobs_ago(0, JOBS_SPAM_DAYS)]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $id = (int)$id;
            $fs = $pdo->prepare('SELECT stored_name FROM application_files WHERE application_id = ?');
            $fs->execute([$id]);
            $files = $fs->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM email_log WHERE application_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM applications WHERE id = ? AND is_spam = 1')->execute([$id]);   // answers, files, notes, history, queue cascade
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[jobs retention] spam ' . $id . ': ' . $e->getMessage());
                continue;
            }
            foreach ($files as $f) jobs_upload_delete((string)$f);
            $n++;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE is_spam = 1 AND submitted_at < ?');
        $st->execute([jobs_ago(0, JOBS_SPAM_DAYS)]);
        if ($n && function_exists('erp_audit')) erp_audit($pdo, 'delete', 'applications', 0, 'Hiring records: deleted ' . $n . ' spam application' . ($n === 1 ? '' : 's') . ' older than ' . JOBS_SPAM_DAYS . ' days');
        return [$n, (int)$st->fetchColumn()];
    }

    /** Delete job-alert signups stopped 90+ days ago, and their old alert log rows. */
    function jobs_alerts_purge(PDO $pdo): int
    {
        $cut = jobs_ago(0, JOBS_ALERT_STOP_DAYS);
        $pdo->prepare("DELETE l FROM email_log l JOIN job_alert_subscribers s ON s.email = l.to_email
                        WHERE l.application_id IS NULL AND l.template_key IN ('alert_new_job','alert_confirm')
                          AND s.unsubscribed_at IS NOT NULL AND s.unsubscribed_at < ?")->execute([$cut]);
        $st = $pdo->prepare('DELETE FROM job_alert_subscribers WHERE unsubscribed_at IS NOT NULL AND unsubscribed_at < ?');
        $st->execute([$cut]);
        $n = $st->rowCount();
        if ($n && function_exists('erp_audit')) erp_audit($pdo, 'delete', 'job_alert_subscribers', 0, 'Hiring records: deleted ' . $n . ' stopped job-alert signup' . ($n === 1 ? '' : 's'));
        return $n;
    }

    /**
     * Résumé files on the server with no application (an upload whose form
     * didn't finish saving, or a manual copy). Only files older than a day.
     */
    function jobs_orphan_files(PDO $pdo): array
    {
        $dir = jobs_upload_dir();
        if (!is_dir($dir)) return [];
        $known = array_flip($pdo->query('SELECT stored_name FROM application_files')->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (!preg_match('/^[a-f0-9]{32}$/', $f) || isset($known[$f])) continue;
            if (@filemtime($dir . '/' . $f) > time() - 86400) continue;
            $out[] = $f;
        }
        return $out;
    }

    function jobs_orphans_purge(PDO $pdo): int
    {
        $n = 0;
        foreach (jobs_orphan_files($pdo) as $f) { jobs_upload_delete($f); $n++; }
        if ($n && function_exists('erp_audit')) erp_audit($pdo, 'delete', 'application_files', 0, 'Hiring records: deleted ' . $n . ' leftover résumé file' . ($n === 1 ? '' : 's'));
        return $n;
    }

    /**
     * Setup checks for the Records screen: [label, ok(bool|null for info), detail].
     */
    function jobs_health_checks(PDO $pdo): array
    {
        $c = [];
        $dir = jobs_upload_dir();
        $inWeb = str_contains($dir, '/public_html/');
        $c[] = ['Résumé folder', is_dir($dir) && is_writable($dir) && !$inWeb,
                !is_dir($dir) ? 'Missing: ' . $dir : (!is_writable($dir) ? 'Can\'t save files to ' . $dir : ($inWeb ? 'It\'s inside public_html. Move it outside the website folder.' : 'OK, outside the website folder'))];
        $c[] = ['Email login (SMTP)', function_exists('bcf_smtp_available') ? bcf_smtp_available() : null,
                function_exists('bcf_smtp_available') && bcf_smtp_available() ? 'Set' : 'Not set: emails use the basic server mailer and may land in spam'];
        $c[] = ['Link secret', defined('BCRF_LIST_SECRET') && BCRF_LIST_SECRET !== '', defined('BCRF_LIST_SECRET') && BCRF_LIST_SECRET !== '' ? 'Set (job alerts work)' : 'Missing: job alerts are off'];

        $have = [];
        foreach ($pdo->query('SELECT tpl_key, is_active FROM email_templates')->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['tpl_key']] = (int)$r['is_active'];
        $missing = []; $off = [];
        foreach (array_keys(jobs_template_catalog()) as $k) {
            if (!isset($have[$k])) $missing[] = $k; elseif (!$have[$k]) $off[] = $k;
        }
        $c[] = ['Email templates', !$missing ? (!$off ? true : null) : false,
                $missing ? 'Missing: ' . implode(', ', $missing) . '. Run /admin/jobs_install.php again.' : ($off ? 'Turned off: ' . implode(', ', $off) : 'All ' . count($have) . ' on')];

        $st = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE status = 'pending' AND created_at < ?");
        $st->execute([jobs_ago(0, 3)]);
        $old = (int)$st->fetchColumn();
        $c[] = ['Waiting emails', $old === 0, $old ? $old . ' held more than 3 days (Hiring > Emails)' : 'None waiting long'];

        $st = $pdo->prepare("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND sent_at > ?");
        $st->execute([jobs_ago(0, 30)]);
        $fail = (int)$st->fetchColumn();
        $c[] = ['Failed emails (30 days)', $fail === 0, $fail ? $fail . ' failed (Hiring > Emails > Sent log)' : 'None'];

        $st = $pdo->prepare('SELECT COUNT(*) FROM job_cycles WHERE closed_at IS NULL AND closes_at IS NOT NULL AND closes_at < ?');
        $st->execute([jobs_today()]);
        $late = (int)$st->fetchColumn();
        $c[] = ['Open rounds past their closing date', $late === 0, $late ? $late . ' (the public page stops taking applications; close or extend them in Hiring > Jobs)' : 'None'];

        if (function_exists('jobs_certs_needed_count')) {
            $n = jobs_certs_needed_count($pdo);
            $c[] = ['Youth certificates', $n === 0, $n ? $n . ' under-18 hire' . ($n === 1 ? '' : 's') . ' still need one (Hiring > Staff)' : 'None missing'];
        }
        return $c;
    }
}
