<?php
// includes/jobs/mailer.php
// Jobs email: load a template from email_templates, fill in merge tags, send
// through bcf_send_mail (PHPMailer SMTP, from support@, reply-to jobs@),
// CC the guardian for under-18 applicants, and write every attempt to email_log.
// Held emails (Not Selected) go to email_queue instead and are sent after approval.

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/../site_info.php';
if (!function_exists('bcf_send_mail')) require_once __DIR__ . '/../../admin/includes/email_template.php';

if (!function_exists('jobs_mail_send')) {

    if (!defined('JOBS_FROM_EMAIL'))   define('JOBS_FROM_EMAIL', 'support@batcaveroadfarm.com');
    if (!defined('JOBS_REPLY_TO'))     define('JOBS_REPLY_TO', 'jobs@batcaveroadfarm.com');
    /** Where "New applicant" alerts go (Zac). Change here if you want a different inbox. */
    if (!defined('JOBS_NOTIFY_EMAIL')) define('JOBS_NOTIFY_EMAIL', 'jobs@batcaveroadfarm.com');

    /** Admin link to one application. (Until the pipeline screen ships, opens the job's application list.) */
    function jobs_admin_app_url(array $app): string
    {
        return 'https://batcaveroadfarm.com/admin/admin.php?view=jobs&app=' . (int)($app['id'] ?? 0);
    }

    /** Merge tags whose values are already HTML (everything else is escaped). */
    function jobs_mail_html_tags(): array { return ['flags', 'offer_details']; }

    function jobs_template(PDO $pdo, string $key): ?array
    {
        $st = $pdo->prepare("SELECT * FROM email_templates WHERE tpl_key = ? AND is_active = 1");
        $st->execute([$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Replace {tags}. $html = true escapes plain values for HTML bodies. Unknown tags become ''. */
    function jobs_render(string $tpl, array $vars, bool $html): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($vars, $html) {
            if (!array_key_exists($m[1], $vars)) return '';
            $v = (string)$vars[$m[1]];
            if (!$html || in_array($m[1], jobs_mail_html_tags(), true)) return $v;
            return htmlspecialchars($v, ENT_QUOTES);
        }, $tpl);
    }

    /** Standard merge values for an application + its job. */
    function jobs_merge_vars(array $app, array $job): array
    {
        $phone = function_exists('site_phone_display') ? site_phone_display() : '';
        if ($phone === '') $phone = '(828) 216-5488';
        return [
            'first_name'     => $app['first_name'] ?? '',
            'last_name'      => $app['last_name'] ?? '',
            'applicant_name' => trim(($app['first_name'] ?? '') . ' ' . ($app['last_name'] ?? '')),
            'position'       => $job['title'] ?? '',
            'category'       => $job['category_name'] ?? '',
            'farm_address'   => site_address() ?: '4132 Bat Cave Road, Old Fort, NC 28762',
            'farm_phone'     => $phone,
            'application_link' => jobs_admin_app_url($app),
        ];
    }

    /** Guardian CC list for under-18 applicants. */
    function jobs_cc_for(array $app): array
    {
        if (($app['age_bracket'] ?? '18_plus') === '18_plus') return [];
        $g = trim((string)($app['guardian_email'] ?? ''));
        return filter_var($g, FILTER_VALIDATE_EMAIL) && strcasecmp($g, (string)$app['email']) !== 0 ? [$g] : [];
    }

    function jobs_mail_log(PDO $pdo, ?int $appId, string $to, array $cc, string $key, string $subject, bool $ok, string $err = ''): void
    {
        try {
            $pdo->prepare("INSERT INTO email_log (application_id, to_email, cc_email, template_key, subject, status, error, sent_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$appId, mb_substr($to, 0, 190), $cc ? mb_substr(implode(', ', $cc), 0, 190) : null, $key,
                           mb_substr($subject, 0, 200), $ok ? 'sent' : 'failed', $err !== '' ? mb_substr($err, 0, 500) : null, jobs_now()]);
        } catch (Throwable $e) {
            error_log('[jobs mailer] email_log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Send a ready-made email and log it. Never throws.
     * $opts: cc[], attachments[[content,name,mime]], subtitle, reply_to, application_id, template_key
     */
    function jobs_mail_send(PDO $pdo, string $to, string $subject, string $innerHtml, array $opts = []): bool
    {
        $cc  = (array)($opts['cc'] ?? []);
        $key = (string)($opts['template_key'] ?? 'custom');
        $ok  = false;
        $err = '';
        try {
            $ok = bcf_send_mail($to, $subject, $innerHtml, [
                'subtitle'    => $opts['subtitle'] ?? 'Jobs',
                'from_email'  => JOBS_FROM_EMAIL,
                'reply_to'    => $opts['reply_to'] ?? JOBS_REPLY_TO,
                'cc'          => $cc,
                'attachments' => $opts['attachments'] ?? [],
            ]);
            $err = $ok ? '' : (string)($GLOBALS['bcf_mail_last_error'] ?? 'send failed');
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
        if (!$ok) error_log('[jobs mailer] ' . $key . ' to ' . $to . ' failed: ' . $err);
        jobs_mail_log($pdo, isset($opts['application_id']) ? (int)$opts['application_id'] : null, $to, $cc, $key, $subject, $ok, $err);
        return $ok;
    }

    /** Render template $key with $vars. Returns [subject, body] or null if the template is missing/off. */
    function jobs_render_template(PDO $pdo, string $key, array $vars): ?array
    {
        $t = jobs_template($pdo, $key);
        if (!$t) return null;
        return [jobs_render($t['subject'], $vars, false), jobs_render($t['body_html'], $vars, true)];
    }

    /** Render + send a template to an applicant (guardian CC'd if under 18). */
    function jobs_send_to_applicant(PDO $pdo, string $key, array $app, array $job, array $extra = [], array $opts = []): bool
    {
        $r = jobs_render_template($pdo, $key, array_merge(jobs_merge_vars($app, $job), $extra));
        if (!$r) { error_log('[jobs mailer] template ' . $key . ' missing or turned off'); return false; }
        return jobs_mail_send($pdo, (string)$app['email'], $r[0], $r[1], array_merge([
            'cc' => jobs_cc_for($app), 'application_id' => (int)$app['id'], 'template_key' => $key,
        ], $opts));
    }

    /** Put a rendered applicant email in the approval queue (status pending). Returns queue id or 0. */
    function jobs_queue_for_applicant(PDO $pdo, string $key, array $app, array $job, string $queuedBy, array $extra = []): int
    {
        $r = jobs_render_template($pdo, $key, array_merge(jobs_merge_vars($app, $job), $extra));
        if (!$r) return 0;
        // One pending copy per application + template.
        $chk = $pdo->prepare("SELECT id FROM email_queue WHERE application_id = ? AND template_key = ? AND status = 'pending'");
        $chk->execute([(int)$app['id'], $key]);
        if ($id = (int)$chk->fetchColumn()) return $id;
        $cc = jobs_cc_for($app);
        $pdo->prepare("INSERT INTO email_queue (application_id, template_key, to_email, cc_email, rendered_subject, rendered_body, status, queued_by)
                       VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)")
            ->execute([(int)$app['id'], $key, $app['email'], $cc ? implode(', ', $cc) : null, $r[0], $r[1], mb_substr($queuedBy, 0, 100)]);
        return (int)$pdo->lastInsertId();
    }
}
