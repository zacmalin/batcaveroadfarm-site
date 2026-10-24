<?php
// includes/jobs/emails.php
// Hiring emails service (Jobs pack P5): template catalog + merge-tag lists,
// sample values for previews/test sends, template save/reset, and the
// approval queue (send, edit, cancel). Used by admin/handlers/jobs_emails.php
// and admin/views/jobs/emails.php.

require_once __DIR__ . '/pipeline.php';   // mailer, status, jobs_actor()
require_once __DIR__ . '/default_templates.php';

if (!function_exists('jobs_template_catalog')) {

    /**
     * Every Jobs template: when it goes out, and which merge tags it can use.
     * Tags not listed for a template are blocked on save (catches typos).
     */
    function jobs_template_catalog(): array
    {
        $who = ['first_name', 'last_name', 'position', 'category', 'farm_address', 'farm_phone'];
        return [
            'received'            => ['when' => 'Right after someone applies. Guardian CC\'d for under-18s.', 'tags' => $who],
            'admin_new_applicant' => ['when' => 'To jobs@ right after someone applies.', 'tags' => array_merge($who, ['applicant_name', 'application_link', 'flags'])],
            'interview'           => ['when' => 'When you move someone to Interview (with a calendar invite).', 'tags' => array_merge($who, ['interview_datetime', 'interview_location'])],
            'offer'               => ['when' => 'When you move someone to Offer and tick "Send".', 'tags' => array_merge($who, ['offer_details'])],
            'hired'               => ['when' => 'After the hire screen (coming next): welcome + set-password link.', 'tags' => array_merge($who, ['start_date', 'set_password_link'])],
            'not_selected'        => ['when' => 'Held in the queue until you approve it.', 'tags' => $who],
            'alert_confirm'       => ['when' => 'Job alerts signup (coming later): confirm link.', 'tags' => ['confirm_link', 'farm_address', 'farm_phone']],
            'alert_new_job'       => ['when' => 'Job alerts (coming later): a matching job opens.', 'tags' => ['position', 'category', 'pay_summary', 'closes_date', 'job_link', 'unsubscribe_link', 'farm_address', 'farm_phone']],
            'talent_pool_reopen'  => ['when' => 'Talent Pool (coming later): a job they fit reopens.', 'tags' => array_merge($who, ['job_link'])],
        ];
    }

    /** What each tag means (shown in the editor). */
    function jobs_tag_help(): array
    {
        return [
            'first_name' => 'Applicant first name', 'last_name' => 'Applicant last name', 'applicant_name' => 'Full name',
            'position' => 'Job title', 'category' => 'Area (Farmstand, Thrift Shop…)', 'farm_address' => 'Farm address',
            'farm_phone' => 'Farm phone', 'application_link' => 'Admin link to the application', 'flags' => 'Under 18 / knockout / returning notes',
            'interview_datetime' => 'Interview day and time', 'interview_location' => 'Where the interview is', 'offer_details' => 'What you type on the Offer screen',
            'start_date' => 'First day', 'set_password_link' => 'Set-password link for their login', 'confirm_link' => 'Confirm-signup link',
            'pay_summary' => 'Pay line', 'closes_date' => 'Last day to apply', 'job_link' => 'Public job page link', 'unsubscribe_link' => 'Unsubscribe link',
        ];
    }

    /** Sample values for the preview and test sends. */
    function jobs_sample_vars(): array
    {
        $v = jobs_merge_vars(['id' => 0, 'first_name' => 'Sam', 'last_name' => 'Sample'], ['title' => 'Farmstand Associate', 'category_name' => 'Farmstand']);
        return array_merge($v, [
            'application_link'   => 'https://batcaveroadfarm.com/admin/admin.php?view=jobs',
            'flags'              => '<ul style="margin:0;padding-left:18px;"><li>Under 18 (16–17) — guardian: Pat Sample</li></ul>',
            'interview_datetime' => jobs_fmt_interview_sample(),
            'interview_location' => $v['farm_address'],
            'offer_details'      => '$12.00/hr<br>Saturdays and Sundays, 10am to 4pm<br>Start date: next Saturday',
            'start_date'         => date('l, F j', strtotime('next saturday')),
            'set_password_link'  => 'https://batcaveroadfarm.com/admin/login.php',
            'confirm_link'       => 'https://batcaveroadfarm.com/jobs/',
            'pay_summary'        => '$12.00–$14.00/hr',
            'closes_date'        => date('F j', strtotime('+3 weeks')),
            'job_link'           => 'https://batcaveroadfarm.com/jobs/',
            'unsubscribe_link'   => 'https://batcaveroadfarm.com/jobs/',
        ]);
    }

    function jobs_fmt_interview_sample(): string
    {
        return date('l, F j, Y \a\t g:i A', strtotime('next saturday 10:00'));
    }

    /** All templates (including turned-off ones), in catalog order. */
    function jobs_templates_all(PDO $pdo): array
    {
        $rows = [];
        foreach ($pdo->query('SELECT * FROM email_templates')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $rows[$r['tpl_key']] = $r;
        $out = [];
        foreach (array_keys(jobs_template_catalog()) as $k) if (isset($rows[$k])) { $out[$k] = $rows[$k]; unset($rows[$k]); }
        return $out + $rows;
    }

    /** Tags used in $text that the template doesn't support. */
    function jobs_unknown_tags(string $key, string $text): array
    {
        $ok = jobs_template_catalog()[$key]['tags'] ?? array_keys(jobs_tag_help());
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);
        return array_values(array_diff(array_unique($m[1]), $ok));
    }

    /** Validate + save a template. Returns [ok, message]. */
    function jobs_template_save(PDO $pdo, string $key, string $subject, string $body, bool $active): array
    {
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $body    = trim($body);
        if ($subject === '' || mb_strlen($subject) > 200) return [false, 'The subject needs 1–200 characters.'];
        if ($body === '')                                 return [false, 'The message can\'t be empty.'];
        if (strlen($body) > 60000)                        return [false, 'The message is too long.'];
        if (preg_match('#<\s*(script|iframe|object|embed|form)\b#i', $body)) return [false, 'Scripts, frames and forms aren\'t allowed in emails.'];
        $bad = jobs_unknown_tags($key, $subject . ' ' . $body);
        if ($bad) return [false, 'This email can\'t use: {' . implode('}, {', $bad) . '}. Check the spelling against the list of tags.'];
        $st = $pdo->prepare('UPDATE email_templates SET subject = ?, body_html = ?, is_active = ? WHERE tpl_key = ?');
        $st->execute([$subject, $body, $active ? 1 : 0, $key]);
        return [true, 'Saved.'];
    }

    /** Put a template back to the original wording. */
    function jobs_template_reset(PDO $pdo, string $key): bool
    {
        $d = jobs_default_templates()[$key] ?? null;
        if (!$d) return false;
        $pdo->prepare('UPDATE email_templates SET subject = ?, body_html = ?, is_active = 1 WHERE tpl_key = ?')->execute([$d['subject'], $d['body'], $key]);
        return true;
    }

    /** Full email HTML as the recipient sees it (header + body + footer). */
    function jobs_email_preview_html(string $inner): string
    {
        return bcf_email_header('Jobs') . $inner . bcf_email_footer(false);
    }

    /** Send a template with sample values to $to. Not tied to an application. */
    function jobs_template_test_send(PDO $pdo, array $tpl, string $to): bool
    {
        $vars = jobs_sample_vars();
        $subj = '[Test] ' . jobs_render($tpl['subject'], $vars, false);
        $body = jobs_render($tpl['body_html'], $vars, true);
        return jobs_mail_send($pdo, $to, $subj, $body, ['template_key' => 'test_' . $tpl['tpl_key']]);
    }

    // ── Queue ─────────────────────────────────────────────────────────────────

    function jobs_queue_find(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT q.*, a.first_name, a.last_name, a.status AS app_status, a.is_spam, a.job_id, j.title AS job_title
                               FROM email_queue q
                               LEFT JOIN applications a ON a.id = q.application_id
                               LEFT JOIN jobs j ON j.id = a.job_id
                              WHERE q.id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Which application status a held email belongs with (sending is blocked if they've moved on). */
    function jobs_queue_expected_status(string $key): ?string
    {
        return ['not_selected' => 'not_selected'][$key] ?? null;
    }

    /**
     * Send one held email. Safe against double taps (named DB lock + status check).
     * Returns [ok, message].
     */
    function jobs_queue_send(PDO $pdo, int $id): array
    {
        [$uid, $uname] = jobs_actor();
        $lock = 'bcrf_jobs_q_' . $id;
        $got  = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn();
        if ($got !== 1) return [false, 'That email is already being sent.'];
        try {
            $q = jobs_queue_find($pdo, $id);
            if (!$q) return [false, 'That email is gone from the queue.'];
            $who = trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? '')) ?: $q['to_email'];
            if (!in_array($q['status'], ['pending', 'failed'], true)) return [false, 'Already ' . $q['status'] . ': ' . $who . '.'];
            if ($q['application_id'] !== null) {
                if ($q['app_status'] === null) {
                    $pdo->prepare("UPDATE email_queue SET status = 'cancelled', approved_by = ? WHERE id = ?")->execute([mb_substr($uname, 0, 100), $id]);
                    return [false, 'The application was deleted, so the email was cancelled.'];
                }
                if ((int)$q['is_spam']) return [false, $who . ' is marked as spam. Not sent.'];
                $exp = jobs_queue_expected_status($q['template_key']);
                if ($exp && $q['app_status'] !== $exp) {
                    return [false, $who . ' is now ' . (jobs_status_labels()[$q['app_status']] ?? $q['app_status']) . ', so this email wasn\'t sent. Cancel it or move them back first.'];
                }
            }
            $cc = array_values(array_filter(array_map('trim', explode(',', (string)$q['cc_email']))));
            $ok = jobs_mail_send($pdo, $q['to_email'], $q['rendered_subject'], $q['rendered_body'], [
                'cc' => $cc, 'application_id' => $q['application_id'] !== null ? (int)$q['application_id'] : null, 'template_key' => $q['template_key'],
            ]);
            $err = $ok ? null : mb_substr((string)($GLOBALS['bcf_mail_last_error'] ?? 'send failed'), 0, 500);
            $pdo->prepare('UPDATE email_queue SET status = ?, approved_by = ?, sent_at = ?, error = ? WHERE id = ?')
                ->execute([$ok ? 'sent' : 'failed', mb_substr($uname, 0, 100), $ok ? jobs_now() : null, $err, $id]);
            if ($q['application_id'] !== null) {
                $label = ['not_selected' => '"Not selected" email'][$q['template_key']] ?? 'Held email';
                jobs_add_history($pdo, (int)$q['application_id'], $q['app_status'], $q['app_status'], $label . ($ok ? ' approved and sent' : ' failed to send'), $uid, $uname);
            }
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'email_queue', $id, ($ok ? 'Sent ' : 'Send failed ') . $q['template_key'] . ' to ' . $who);
            return $ok ? [true, 'Sent to ' . $who . '.'] : [false, 'Couldn\'t send to ' . $who . ': ' . ($err ?: 'unknown error') . '. It stays in the queue to retry.'];
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
        }
    }

    function jobs_queue_cancel(PDO $pdo, int $id): bool
    {
        [$uid, $uname] = jobs_actor();
        $q = jobs_queue_find($pdo, $id);
        if (!$q || !in_array($q['status'], ['pending', 'failed'], true)) return false;
        $pdo->prepare("UPDATE email_queue SET status = 'cancelled', approved_by = ? WHERE id = ? AND status IN ('pending','failed')")->execute([mb_substr($uname, 0, 100), $id]);
        if ($q['application_id'] !== null && $q['app_status'] !== null) {
            jobs_add_history($pdo, (int)$q['application_id'], $q['app_status'], $q['app_status'], 'Held email cancelled (not sent)', $uid, $uname);
        }
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'email_queue', $id, 'Cancelled ' . $q['template_key'] . ' to ' . $q['to_email']);
        return true;
    }

    /** Edit a held email before sending. */
    function jobs_queue_edit(PDO $pdo, int $id, string $subject, string $body): array
    {
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $body    = trim($body);
        if ($subject === '' || mb_strlen($subject) > 200) return [false, 'The subject needs 1–200 characters.'];
        if ($body === '' || strlen($body) > 60000)        return [false, 'The message is empty or too long.'];
        if (preg_match('#<\s*(script|iframe|object|embed|form)\b#i', $body)) return [false, 'Scripts, frames and forms aren\'t allowed in emails.'];
        if (preg_match('/\{[a-z_]+\}/', $subject . $body)) return [false, 'This email is already filled in, so {tags} won\'t be replaced. Type the actual words instead.'];
        $q = jobs_queue_find($pdo, $id);
        if (!$q || !in_array($q['status'], ['pending', 'failed'], true)) return [false, 'That email was already sent or cancelled.'];
        $pdo->prepare('UPDATE email_queue SET rendered_subject = ?, rendered_body = ? WHERE id = ?')->execute([$subject, $body, $id]);
        return [true, 'Saved. It\'s still waiting — tap Send when ready.'];
    }
}
