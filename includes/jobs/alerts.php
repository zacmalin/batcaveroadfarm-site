<?php
// includes/jobs/alerts.php
// Job alerts + Talent Pool notices (Jobs pack P7).
//  - Signup is double opt-in. Nothing is stored until the owner taps the signed
//    link in the confirm email (HMAC over email + choices + time, 7 days), the
//    same pattern as the mailing list (subscribe.php / subscribe-confirm.php).
//  - Every alert carries a signed one-click unsubscribe link + List-Unsubscribe headers.
//  - "Let people know" on the job editor sends, for the open round:
//      alert_new_job      to confirmed subscribers matching area + type
//      talent_pool_reopen to Talent Pool applicants from earlier rounds of this job
//    in batches; nobody gets the same round twice.
// Needs BCRF_LIST_SECRET (already set for the mailing list).

require_once __DIR__ . '/pipeline.php';

if (!function_exists('jobs_alert_sig')) {

    if (!defined('JOBS_ALERT_LINK_DAYS')) define('JOBS_ALERT_LINK_DAYS', 7);
    if (!defined('JOBS_NOTIFY_BATCH'))    define('JOBS_NOTIFY_BATCH', 40);
    if (!defined('JOBS_SITE_URL_ALERTS')) define('JOBS_SITE_URL_ALERTS', 'https://batcaveroadfarm.com');

    function jobs_alert_ready(): bool
    {
        return defined('BCRF_LIST_SECRET') && BCRF_LIST_SECRET !== '';
    }

    /** 32-char signature for one purpose ('confirm' | 'unsub'). '' if no secret. */
    function jobs_alert_sig(string $purpose, string $payload): string
    {
        if (!jobs_alert_ready()) return '';
        return substr(hash_hmac('sha256', 'jobalert|' . $purpose . '|' . $payload, BCRF_LIST_SECRET), 0, 32);
    }

    /** Clean the picked areas / types: known ids and keys only, sorted, unique. */
    function jobs_alert_clean_choices(PDO $pdo, $cats, $types): array
    {
        $validCats = array_map(fn($c) => (int)$c['id'], jobs_categories($pdo, true));
        $c = array_values(array_unique(array_filter(array_map('intval', (array)$cats), fn($i) => in_array($i, $validCats, true))));
        sort($c);
        $t = array_values(array_unique(array_filter(array_map('strval', (array)$types), fn($k) => isset(jobs_engagement_labels()[$k]))));
        sort($t);
        return [$c, $t];
    }

    function jobs_alert_payload(string $email, array $cats, array $types, int $ts): string
    {
        return strtolower(trim($email)) . '|' . implode('.', $cats) . '|' . implode('.', $types) . '|' . $ts;
    }

    function jobs_alert_confirm_url(string $email, array $cats, array $types, int $ts): string
    {
        $q = ['e' => strtolower(trim($email)), 'c' => implode('.', $cats), 'y' => implode('.', $types), 'ts' => $ts,
              't' => jobs_alert_sig('confirm', jobs_alert_payload($email, $cats, $types, $ts))];
        return JOBS_SITE_URL_ALERTS . '/jobs/alerts/confirm.php?' . http_build_query($q);
    }

    /** 'ok' | 'expired' | 'invalid' for a confirm link's values. */
    function jobs_alert_confirm_check(string $email, array $cats, array $types, int $ts, string $sig): string
    {
        $want = jobs_alert_sig('confirm', jobs_alert_payload($email, $cats, $types, $ts));
        if ($want === '' || strlen($sig) !== 32 || !hash_equals($want, $sig)) return 'invalid';
        if ($ts > time() + 300 || $ts < time() - JOBS_ALERT_LINK_DAYS * 86400) return 'expired';
        return 'ok';
    }

    function jobs_alert_unsub_url(string $email): string
    {
        $e = strtolower(trim($email));
        return JOBS_SITE_URL_ALERTS . '/jobs/alerts/unsubscribe.php?e=' . rawurlencode($e) . '&t=' . jobs_alert_sig('unsub', $e);
    }

    function jobs_alert_unsub_verify(string $email, string $sig): bool
    {
        $want = jobs_alert_sig('unsub', strtolower(trim($email)));
        return $want !== '' && strlen($sig) === 32 && hash_equals($want, $sig);
    }

    /** "Farmstand, Thrift Shop · Paid" style summary of someone's choices. */
    function jobs_alert_choice_text(PDO $pdo, array $cats, array $types): string
    {
        $names = [];
        foreach (jobs_categories($pdo) as $c) if (in_array((int)$c['id'], $cats, true)) $names[] = $c['name'];
        $eng = array_map(fn($k) => jobs_engagement_labels()[$k] ?? $k, $types);
        return ($names ? implode(', ', $names) : 'All areas') . ' · ' . ($eng ? implode(', ', $eng) : 'All job types');
    }

    /** Email the confirm link. Returns false if the send failed or the template is off. */
    function jobs_alert_send_confirm(PDO $pdo, string $email, array $cats, array $types): bool
    {
        $r = jobs_render_template($pdo, 'alert_confirm', array_merge(jobs_merge_vars([], []), [
            'confirm_link' => jobs_alert_confirm_url($email, $cats, $types, time()),
        ]));
        if (!$r) return false;
        return jobs_mail_send($pdo, $email, $r[0], $r[1], ['template_key' => 'alert_confirm']);
    }

    /** Save (or update) a confirmed signup. */
    function jobs_alert_subscribe(PDO $pdo, string $email, array $cats, array $types, string $ip = ''): void
    {
        $now = jobs_now();
        $pdo->prepare("INSERT INTO job_alert_subscribers (email, category_ids, engagement_types, confirmed_at, unsubscribed_at, ip_hash)
                       VALUES (?, ?, ?, ?, NULL, ?)
                       ON DUPLICATE KEY UPDATE category_ids = VALUES(category_ids), engagement_types = VALUES(engagement_types),
                                               confirmed_at = VALUES(confirmed_at), unsubscribed_at = NULL, ip_hash = VALUES(ip_hash)")
            ->execute([strtolower(trim($email)), json_encode($cats), json_encode($types), $now, $ip !== '' ? hash('sha256', $ip) : null]);
    }

    /** Stop alerts for an address. Returns true if an active signup was found. */
    function jobs_alert_unsubscribe(PDO $pdo, string $email): bool
    {
        $st = $pdo->prepare('UPDATE job_alert_subscribers SET unsubscribed_at = ? WHERE email = ? AND unsubscribed_at IS NULL');
        $st->execute([jobs_now(), strtolower(trim($email))]);
        return $st->rowCount() > 0;
    }

    function jobs_alert_counts(PDO $pdo): array
    {
        $r = $pdo->query("SELECT SUM(confirmed_at IS NOT NULL AND unsubscribed_at IS NULL) AS active, SUM(unsubscribed_at IS NOT NULL) AS stopped FROM job_alert_subscribers")->fetch(PDO::FETCH_ASSOC);
        return ['active' => (int)($r['active'] ?? 0), 'stopped' => (int)($r['stopped'] ?? 0)];
    }

    // ── Who to tell about an open round ─────────────────────────────────────

    /** The alert subject for $job (also how a round's sends are told apart in email_log). */
    function jobs_alert_subject(PDO $pdo, array $job): string
    {
        $tpl = jobs_template($pdo, 'alert_new_job');
        $sub = $tpl ? (string)$tpl['subject'] : 'Now hiring: {position}';
        return mb_substr(jobs_render($sub, ['position' => (string)$job['title'], 'category' => (string)($job['category_name'] ?? '')], false), 0, 200);
    }

    /**
     * Confirmed subscribers who match $job and haven't been emailed about this
     * job's current round. "Already emailed" = an alert_new_job row in email_log
     * (sent or failed) to that address since the round opened, with this job's
     * subject — so two jobs opening the same week each get their own alert.
     */
    function jobs_alert_matches(PDO $pdo, array $job, array $cycle): array
    {
        $st = $pdo->prepare("SELECT s.* FROM job_alert_subscribers s
                              WHERE s.confirmed_at IS NOT NULL AND s.unsubscribed_at IS NULL
                                AND NOT EXISTS (SELECT 1 FROM email_log l WHERE l.template_key = 'alert_new_job'
                                                   AND l.application_id IS NULL AND l.to_email = s.email
                                                   AND l.sent_at >= ? AND l.subject = ?)
                              ORDER BY s.id");
        $st->execute([$cycle['opened_at'], jobs_alert_subject($pdo, $job)]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $c = json_decode((string)$s['category_ids'], true) ?: [];
            $t = json_decode((string)$s['engagement_types'], true) ?: [];
            if ($c && !in_array((int)$job['category_id'], array_map('intval', $c), true)) continue;
            if ($t && !in_array((string)$job['engagement_type'], $t, true)) continue;
            $out[] = $s;
        }
        return $out;
    }

    /**
     * Talent Pool applicants from earlier rounds of this job, one per email (their
     * latest application), skipping anyone who already applied this round or was
     * already told about it, spam and archived.
     */
    function jobs_pool_matches(PDO $pdo, array $job, array $cycle): array
    {
        $st = $pdo->prepare("SELECT a.* FROM applications a
                              WHERE a.job_id = ? AND a.status = 'talent_pool' AND a.is_spam = 0 AND a.archived_at IS NULL AND a.anonymized_at IS NULL
                                AND a.job_cycle_id <> ?
                                AND NOT EXISTS (SELECT 1 FROM applications b WHERE b.job_cycle_id = ? AND LOWER(b.email) = LOWER(a.email))
                                AND NOT EXISTS (SELECT 1 FROM email_log l JOIN applications c ON c.id = l.application_id
                                                 WHERE LOWER(c.email) = LOWER(a.email) AND l.template_key = 'talent_pool_reopen'
                                                   AND l.status IN ('sent','failed') AND l.sent_at >= ?)
                              ORDER BY a.submitted_at DESC, a.id DESC");
        $st->execute([(int)$job['id'], (int)$cycle['id'], (int)$cycle['id'], $cycle['opened_at']]);
        $seen = [];
        $out  = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $k = strtolower(trim($a['email']));
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            $out[] = $a;
        }
        return $out;
    }

    function jobs_job_link(array $job): string
    {
        return JOBS_SITE_URL_ALERTS . '/jobs/' . rawurlencode((string)$job['slug']) . '/';
    }

    /**
     * Send the next batch for the open round of $jobId. $which: ['alerts', 'pool'].
     * Returns ['sent' => n, 'failed' => n, 'left_alerts' => n, 'left_pool' => n, 'error' => ''].
     */
    function jobs_notify_send(PDO $pdo, int $jobId, array $which): array
    {
        $res = ['sent' => 0, 'failed' => 0, 'left_alerts' => 0, 'left_pool' => 0, 'error' => ''];
        $job = jobs_find_by_slug($pdo, (string)($pdo->query('SELECT slug FROM jobs WHERE id = ' . (int)$jobId)->fetchColumn() ?: ''));
        if (!$job || !jobs_public_accepting($job)) { $res['error'] = 'This job isn\'t taking applications right now, so there\'s nothing to announce.'; return $res; }
        $cycle = jobs_open_cycle_row($pdo, $jobId);
        if (!$cycle) { $res['error'] = 'Hiring isn\'t open for this job.'; return $res; }

        $lock = 'bcrf_jobs_notify_' . $jobId;
        if ((int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            $res['error'] = 'A send for this job is already running. Wait a moment and refresh.';
            return $res;
        }
        try {
            [$uid, $uname] = jobs_actor();
            $budget = JOBS_NOTIFY_BATCH;
            $jobVars = [
                'position'    => (string)$job['title'],
                'category'    => (string)$job['category_name'],
                'pay_summary' => jobs_pay_summary($job) ?: ($job['engagement_type'] === 'internship' ? 'Internship' : ''),
                'closes_date' => $job['closes_at'] ? date('F j, Y', strtotime($job['closes_at'])) : 'open until filled',
                'job_link'    => jobs_job_link($job),
            ];

            if (in_array('alerts', $which, true)) {
                $tpl = jobs_template($pdo, 'alert_new_job');
                if (!$tpl) $res['error'] = 'The "Job alert — new opening" template is turned off, so alerts were skipped.';
                else {
                    $mark = $pdo->prepare('UPDATE job_alert_subscribers SET last_sent_at = ? WHERE id = ?');
                    foreach (jobs_alert_matches($pdo, $job, $cycle) as $s) {
                        if ($budget <= 0) break;
                        $budget--;
                        $unsub = jobs_alert_unsub_url($s['email']);
                        $vars  = array_merge(jobs_merge_vars([], $job), $jobVars, ['unsubscribe_link' => $unsub]);
                        $ok = jobs_mail_send($pdo, $s['email'], jobs_alert_subject($pdo, $job), jobs_render($tpl['body_html'], $vars, true), [
                            'template_key' => 'alert_new_job', 'unsubscribe' => true, 'list_unsubscribe' => $unsub,
                        ]);
                        // The email_log row (sent or failed) keeps them out of this round's list,
                        // so a bad address can't be retried forever. last_sent_at is for the Alerts screen.
                        if ($ok) $mark->execute([jobs_now(), (int)$s['id']]);
                        $ok ? $res['sent']++ : $res['failed']++;
                    }
                    $pdo->prepare('UPDATE job_cycles SET alerts_sent_at = COALESCE(alerts_sent_at, ?) WHERE id = ?')->execute([jobs_now(), (int)$cycle['id']]);
                }
            }

            if (in_array('pool', $which, true) && $budget > 0) {
                if (!jobs_template($pdo, 'talent_pool_reopen')) {
                    $res['error'] = trim($res['error'] . ' The "Talent Pool — job reopened" template is turned off, so Talent Pool was skipped.');
                } else {
                    foreach (jobs_pool_matches($pdo, $job, $cycle) as $a) {
                        if ($budget <= 0) break;
                        $budget--;
                        $app = jobs_app_find($pdo, (int)$a['id']) ?: $a;
                        $ok  = jobs_send_to_applicant($pdo, 'talent_pool_reopen', $app, $job, ['job_link' => $jobVars['job_link']]);
                        jobs_add_history($pdo, (int)$a['id'], 'talent_pool', 'talent_pool',
                            'Emailed that ' . $job['title'] . ' is open again' . ($ok ? '' : ' (failed to send)'), $uid, $uname);
                        // A failed send is logged too, and the logged row keeps them out of this round's list
                        // (so one bad address can't be retried forever). It shows under Sent log > Failed.
                        $ok ? $res['sent']++ : $res['failed']++;
                    }
                    $pdo->prepare('UPDATE job_cycles SET talent_pool_notified_at = COALESCE(talent_pool_notified_at, ?) WHERE id = ?')->execute([jobs_now(), (int)$cycle['id']]);
                }
            }

            $res['left_alerts'] = count(jobs_alert_matches($pdo, $job, $cycle));
            $res['left_pool']   = count(jobs_pool_matches($pdo, $job, $cycle));
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
        }
        if (function_exists('erp_audit') && ($res['sent'] || $res['failed'])) {
            erp_audit($pdo, 'update', 'jobs', $jobId, $job['title'] . ': let people know — ' . $res['sent'] . ' sent, ' . $res['failed'] . ' failed');
        }
        return $res;
    }

    /** Counts for the job editor panel (0s if hiring isn't open). */
    function jobs_notify_counts(PDO $pdo, int $jobId): array
    {
        $out = ['alerts' => 0, 'pool' => 0, 'public' => false, 'alerts_sent_at' => null, 'pool_sent_at' => null];
        $slug = $pdo->query('SELECT slug FROM jobs WHERE id = ' . (int)$jobId)->fetchColumn();
        $job  = $slug ? jobs_find_by_slug($pdo, (string)$slug) : null;
        $cy   = jobs_open_cycle_row($pdo, $jobId);
        if (!$job || !$cy) return $out;
        $out['public'] = jobs_public_accepting($job);
        $out['alerts'] = count(jobs_alert_matches($pdo, $job, $cy));
        $out['pool']   = count(jobs_pool_matches($pdo, $job, $cy));
        $out['alerts_sent_at'] = $cy['alerts_sent_at'] ?? null;
        $out['pool_sent_at']   = $cy['talent_pool_notified_at'] ?? null;
        return $out;
    }
}
