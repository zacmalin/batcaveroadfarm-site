<?php
// includes/jobs/pipeline.php
// Applicant pipeline service (Jobs pack P4): look up applications, list them
// with filters, move them between statuses (history + emails), notes, spam and
// archive, and the interview calendar invite (.ics).
// Used by admin/handlers/jobs_pipeline.php and admin/views/jobs/applicants.php.

require_once __DIR__ . '/repo.php';
require_once __DIR__ . '/status.php';
require_once __DIR__ . '/mailer.php';

if (!function_exists('jobs_app_find')) {

    /** Statuses still being worked on (shown under "In progress"). */
    function jobs_active_statuses(): array { return ['new', 'reviewing', 'interview', 'offer']; }

    /** Statuses you can pick for many applications at once (no per-person details needed). */
    function jobs_bulk_statuses(): array { return ['reviewing', 'not_selected', 'withdrawn', 'talent_pool', 'new']; }

    /** Pill colours per status (Tailwind classes used by the admin). */
    function jobs_status_classes(): array
    {
        return [
            'new'          => 'bg-amber-100 text-amber-900 border-amber-300',
            'reviewing'    => 'bg-sky-50 text-sky-800 border-sky-200',
            'interview'    => 'bg-violet-50 text-violet-800 border-violet-200',
            'offer'        => 'bg-emerald-50 text-emerald-800 border-emerald-200',
            'hired'        => 'bg-emerald-600 text-white border-emerald-700',
            'not_selected' => 'bg-stone-100 text-stone-600 border-stone-300',
            'withdrawn'    => 'bg-stone-100 text-stone-600 border-stone-300',
            'talent_pool'  => 'bg-yellow-50 text-yellow-800 border-yellow-300',
        ];
    }

    /** Who is doing this (for history, notes and the queue). */
    function jobs_actor(): array
    {
        $id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $name = trim((string)($_SESSION['user_name'] ?? ''));
        return [$id, $name !== '' ? $name : 'Admin'];
    }

    /** One application with its job, category and hiring round. */
    function jobs_app_find(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT a.*, j.title AS job_title, j.slug AS job_slug, j.category_id, c.name AS category_name,
                                    cy.opened_at AS cycle_opened_at, cy.closed_at AS cycle_closed_at
                               FROM applications a
                               JOIN jobs j ON j.id = a.job_id
                               JOIN job_categories c ON c.id = j.category_id
                               JOIN job_cycles cy ON cy.id = a.job_cycle_id
                              WHERE a.id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Job row in the shape the mailer expects (title + category_name). */
    function jobs_app_job(array $app): array
    {
        return ['id' => (int)$app['job_id'], 'title' => $app['job_title'], 'category_name' => $app['category_name'], 'slug' => $app['job_slug']];
    }

    /**
     * Normalise list filters from GET. Keys:
     * job, cat (ids), round (current|all|<cycle id>), status (active|all|<status>),
     * flag (returning|knockout|minor|resume|''), show (''|spam|archived), q (search), sort (new|old|name)
     */
    function jobs_pipeline_filters(array $g): array
    {
        $labels = jobs_status_labels();
        $f = [
            'job'    => max(0, (int)($g['job'] ?? 0)),
            'cat'    => max(0, (int)($g['cat'] ?? 0)),
            'round'  => (string)($g['round'] ?? 'current'),
            'status' => (string)($g['status'] ?? 'active'),
            'flag'   => (string)($g['flag'] ?? ''),
            'show'   => (string)($g['show'] ?? ''),
            'q'      => mb_substr(trim((string)($g['q'] ?? '')), 0, 80),
            'sort'   => (string)($g['sort'] ?? 'new'),
        ];
        if (!in_array($f['round'], ['current', 'all'], true) && !ctype_digit($f['round'])) $f['round'] = 'current';
        if (!in_array($f['status'], ['active', 'all'], true) && !isset($labels[$f['status']])) $f['status'] = 'active';
        if (!in_array($f['flag'], ['', 'returning', 'knockout', 'minor', 'resume'], true)) $f['flag'] = '';
        if (!in_array($f['show'], ['', 'spam', 'archived'], true)) $f['show'] = '';
        if (!in_array($f['sort'], ['new', 'old', 'name'], true)) $f['sort'] = 'new';
        return $f;
    }

    /** WHERE clause + params for everything except the status filter. */
    function jobs_pipeline_where(array $f): array
    {
        $w = []; $p = [];
        if ($f['show'] === 'spam')         { $w[] = 'a.is_spam = 1'; }
        elseif ($f['show'] === 'archived') { $w[] = 'a.is_spam = 0 AND a.archived_at IS NOT NULL'; }
        else                               { $w[] = 'a.is_spam = 0 AND a.archived_at IS NULL'; }

        if ($f['job']) { $w[] = 'a.job_id = ?'; $p[] = $f['job']; }
        if ($f['cat']) { $w[] = 'j.category_id = ?'; $p[] = $f['cat']; }
        if ($f['round'] === 'current') {
            // Open rounds, plus anyone still in progress from a closed round.
            $w[] = "(cy.closed_at IS NULL OR a.status IN ('" . implode("','", jobs_active_statuses()) . "'))";
        } elseif (ctype_digit($f['round'])) {
            $w[] = 'a.job_cycle_id = ?'; $p[] = (int)$f['round'];
        }
        switch ($f['flag']) {
            case 'returning': $w[] = '(a.is_returning = 1 OR a.prior_match = 1)'; break;
            case 'knockout':  $w[] = 'a.knockout_flag = 1'; break;
            case 'minor':     $w[] = "a.age_bracket <> '18_plus'"; break;
            case 'resume':    $w[] = 'EXISTS (SELECT 1 FROM application_files f WHERE f.application_id = a.id)'; break;
        }
        if ($f['q'] !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
            $w[] = "(CONCAT(a.first_name, ' ', a.last_name) LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)";
            array_push($p, $like, $like, $like);
        }
        return [implode(' AND ', $w), $p];
    }

    /** Count per status for the current filters (ignores the status filter). */
    function jobs_pipeline_counts(PDO $pdo, array $f): array
    {
        [$where, $p] = jobs_pipeline_where($f);
        $st = $pdo->prepare("SELECT a.status, COUNT(*) n
                               FROM applications a JOIN jobs j ON j.id = a.job_id JOIN job_cycles cy ON cy.id = a.job_cycle_id
                              WHERE $where GROUP BY a.status");
        $st->execute($p);
        $out = array_fill_keys(array_keys(jobs_status_labels()), 0);
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $s => $n) $out[$s] = (int)$n;
        return $out;
    }

    /** The application list for the pipeline. */
    function jobs_pipeline_list(PDO $pdo, array $f, int $limit = 300): array
    {
        [$where, $p] = jobs_pipeline_where($f);
        if ($f['status'] === 'active') {
            $where .= " AND a.status IN ('" . implode("','", jobs_active_statuses()) . "')";
        } elseif ($f['status'] !== 'all') {
            $where .= ' AND a.status = ?'; $p[] = $f['status'];
        }
        $order = match ($f['sort']) {
            'old'  => 'a.submitted_at ASC, a.id ASC',
            'name' => 'a.last_name, a.first_name, a.id',
            default => 'a.submitted_at DESC, a.id DESC',
        };
        $st = $pdo->prepare("SELECT a.id, a.job_id, a.job_cycle_id, a.first_name, a.last_name, a.email, a.phone, a.age_bracket,
                                    a.status, a.is_spam, a.is_returning, a.prior_match, a.knockout_flag, a.submitted_at,
                                    a.interview_at, a.archived_at, j.title AS job_title, cy.closed_at AS cycle_closed_at,
                                    (SELECT f.id FROM application_files f WHERE f.application_id = a.id ORDER BY f.id LIMIT 1) AS file_id,
                                    (SELECT COUNT(*) FROM application_notes n WHERE n.application_id = a.id) AS note_count
                               FROM applications a JOIN jobs j ON j.id = a.job_id JOIN job_cycles cy ON cy.id = a.job_cycle_id
                              WHERE $where ORDER BY $order LIMIT " . max(1, $limit));
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** New applications waiting (badge + dashboard). */
    function jobs_new_count(PDO $pdo): int
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'new' AND is_spam = 0 AND archived_at IS NULL")->fetchColumn();
    }

    /** Held emails waiting for approval (sent from the queue screen in pack P5). */
    function jobs_queue_pending_count(PDO $pdo): int
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")->fetchColumn();
    }

    /** "Saturday, November 1, 2026 at 10:00 AM" from an Eastern 'Y-m-d H:i:s'. */
    function jobs_fmt_interview(string $dt): string
    {
        $t = strtotime($dt);
        return $t ? date('l, F j, Y \a\t g:i A', $t) : $dt;
    }

    /** Parse the interview date + time inputs (Eastern). Returns 'Y-m-d H:i:00' or null. */
    function jobs_parse_interview(string $date, string $time): ?string
    {
        $d = DateTime::createFromFormat('!Y-m-d H:i', trim($date) . ' ' . trim($time));
        if (!$d) return null;
        $y = (int)$d->format('Y');
        if ($y < 2020 || $y > 2100) return null;
        return $d->format('Y-m-d H:i:00');
    }

    /** Calendar invite for the interview email (Eastern time, 30 minutes by default). */
    function jobs_interview_ics(array $app, string $startEastern, string $location, int $minutes = 30): string
    {
        $tz    = new DateTimeZone('America/New_York');
        $start = new DateTime($startEastern, $tz);
        $end   = (clone $start)->modify('+' . max(5, $minutes) . ' minutes');
        $utc   = new DateTimeZone('UTC');
        $fmt   = fn(DateTime $d) => (clone $d)->setTimezone($utc)->format('Ymd\THis\Z');
        $esc   = fn(string $s) => str_replace(["\\", ";", ",", "\r\n", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n"], $s);
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Bat Cave Road Farm//Jobs//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:interview-' . (int)$app['id'] . '-' . $start->format('YmdHi') . '@batcaveroadfarm.com',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $fmt($start),
            'DTEND:' . $fmt($end),
            'SUMMARY:' . $esc('Interview at Bat Cave Road Farm — ' . ($app['job_title'] ?? 'job')),
            'LOCATION:' . $esc($location),
            'DESCRIPTION:' . $esc('Interview for ' . ($app['job_title'] ?? 'the job') . '. Need a different time? Reply to the email or call the farm.'),
            'END:VEVENT', 'END:VCALENDAR',
        ];
        // Fold long lines at 74 octets (RFC 5545).
        $out = [];
        foreach ($lines as $l) {
            while (strlen($l) > 74) {
                $cut = 74;
                while ($cut > 1 && (ord($l[$cut]) & 0xC0) === 0x80) $cut--;   // don't split a UTF-8 character
                $out[] = substr($l, 0, $cut);
                $l = ' ' . substr($l, $cut);
            }
            $out[] = $l;
        }
        return implode("\r\n", $out) . "\r\n";
    }

    /**
     * Move one application to a new status. Writes the status, history, audit row,
     * then sends or queues the matching email. Never throws for a normal refusal.
     *
     * $opt: note, interview_at ('Y-m-d H:i:00' Eastern), interview_location, send_email (bool),
     *       offer_details (plain text), queue_email (bool, Not Selected)
     * Returns [bool ok, string message].
     */
    function jobs_move_status(PDO $pdo, array $app, string $to, array $opt = []): array
    {
        $labels = jobs_status_labels();
        $from   = (string)$app['status'];
        $name   = trim($app['first_name'] . ' ' . $app['last_name']);
        if (!isset($labels[$to])) return [false, 'Unknown status.'];
        if ($to === 'hired')      return [false, 'Hiring has its own screen, coming in the next update. Use Offer for now.'];
        if ($from === 'hired')    return [false, $name . ' is already hired. Changes to staff go through the hire screen.'];
        if (!empty($app['is_spam'])) return [false, $name . ' is marked as spam. Tap "Not spam" first.'];
        if ($from === $to && $to !== 'interview') return [false, $name . ' is already ' . $labels[$to] . '.'];

        $note = trim((string)($opt['note'] ?? ''));
        [$uid, $uname] = jobs_actor();
        $set  = ['status = ?']; $par = [$to];

        if ($to === 'interview') {
            $at  = $opt['interview_at'] ?? null;
            $loc = mb_substr(trim((string)($opt['interview_location'] ?? '')), 0, 255);
            if (!$at)        return [false, 'Pick the interview date and time.'];
            if ($loc === '') return [false, 'Add where the interview is (the farm address is filled in for you).'];
            $set[] = 'interview_at = ?';       $par[] = $at;
            $set[] = 'interview_location = ?'; $par[] = $loc;
            $note = trim('Interview ' . jobs_fmt_interview($at) . ($note !== '' ? ' — ' . $note : ''));
        }
        if (in_array($to, jobs_closed_statuses(), true)) { $set[] = 'decided_at = ?'; $par[] = jobs_now(); }
        else                                             { $set[] = 'decided_at = NULL'; }
        $par[] = (int)$app['id'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE applications SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
            jobs_add_history($pdo, (int)$app['id'], $from, $to, $note !== '' ? $note : null, $uid, $uname);
            // Leaving Not Selected: drop the held "not selected" email so it can't go out by mistake.
            if ($from === 'not_selected' && $to !== 'not_selected') {
                $pdo->prepare("UPDATE email_queue SET status = 'cancelled', approved_by = ? WHERE application_id = ? AND template_key = 'not_selected' AND status = 'pending'")
                    ->execute([mb_substr($uname, 0, 100), (int)$app['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'applications', (int)$app['id'], $name . ': ' . ($labels[$from] ?? $from) . ' → ' . $labels[$to]);

        $msg  = $name . ' moved to ' . $labels[$to] . '.';
        $app  = array_merge($app, ['status' => $to]);
        $job  = jobs_app_job($app);

        if ($to === 'interview' && !empty($opt['send_email'])) {
            $at  = $opt['interview_at'];
            $loc = mb_substr(trim((string)$opt['interview_location']), 0, 255);
            $ics = jobs_interview_ics($app, $at, $loc);
            $ok  = jobs_send_to_applicant($pdo, 'interview', $app, $job,
                ['interview_datetime' => jobs_fmt_interview($at), 'interview_location' => $loc],
                ['attachments' => [[$ics, 'interview.ics', 'text/calendar; charset=utf-8; method=PUBLISH']]]);
            $msg .= $ok ? ' Interview email sent with a calendar invite.' : ' The interview email did NOT send — see the email list on their page.';
        }
        if ($to === 'offer' && !empty($opt['send_email'])) {
            $details = trim((string)($opt['offer_details'] ?? ''));
            $html    = $details !== '' ? nl2br(htmlspecialchars($details, ENT_QUOTES)) : 'We\'ll go over the details with you.';
            $ok = jobs_send_to_applicant($pdo, 'offer', $app, $job, ['offer_details' => $html]);
            $msg .= $ok ? ' Offer email sent.' : ' The offer email did NOT send — see the email list on their page.';
        }
        if ($to === 'not_selected' && !empty($opt['queue_email'])) {
            $qid  = jobs_queue_for_applicant($pdo, 'not_selected', $app, $job, $uname);
            $msg .= $qid ? ' The "not selected" email is waiting in the queue (nothing sent).' : '';
        }
        return [true, $msg];
    }

    function jobs_add_note(PDO $pdo, int $appId, string $body): bool
    {
        $body = trim(mb_substr($body, 0, 4000));
        if ($body === '') return false;
        [$uid, $uname] = jobs_actor();
        $pdo->prepare('INSERT INTO application_notes (application_id, user_id, user_name, body) VALUES (?, ?, ?, ?)')
            ->execute([$appId, $uid, mb_substr($uname, 0, 100), $body]);
        return true;
    }

    /** Spam on/off. Marking spam cancels any held emails to them. */
    function jobs_set_spam(PDO $pdo, array $app, bool $spam): void
    {
        [$uid, $uname] = jobs_actor();
        $pdo->prepare('UPDATE applications SET is_spam = ? WHERE id = ?')->execute([$spam ? 1 : 0, (int)$app['id']]);
        if ($spam) {
            $pdo->prepare("UPDATE email_queue SET status = 'cancelled', approved_by = ? WHERE application_id = ? AND status = 'pending'")
                ->execute([mb_substr($uname, 0, 100), (int)$app['id']]);
        }
        jobs_add_history($pdo, (int)$app['id'], $app['status'], $app['status'], $spam ? 'Marked as spam' : 'Marked as not spam', $uid, $uname);
    }

    /** Archive on/off (hides from the normal list; nothing is deleted). */
    function jobs_set_archived(PDO $pdo, array $app, bool $archived): void
    {
        [$uid, $uname] = jobs_actor();
        $pdo->prepare('UPDATE applications SET archived_at = ? WHERE id = ?')->execute([$archived ? jobs_now() : null, (int)$app['id']]);
        jobs_add_history($pdo, (int)$app['id'], $app['status'], $app['status'], $archived ? 'Archived' : 'Brought back from archive', $uid, $uname);
    }
}
