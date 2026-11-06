<?php // includes/queue.php
/*
 * Farm OS Phase 0: domain events, the job queue, and the runner's schedule (Nov 2026).
 * Tables come from admin/migrations/0004_events_and_queue.php. Every time is UTC.
 *
 *   bcrf_event('content.published', 'recipes', 12, ['title' => '…']);
 *       Writes down that something happened. It's one INSERT, so it's safe inside a save's transaction.
 *       The runner (admin/cron/run.php) reads new events and queues the jobs listed for them in
 *       bcrf_event_subscribers().
 *
 *   bcrf_queue_push('email.send', ['to' => …, 'subject' => …, 'html' => …]);
 *       Queues slow or failure-prone work. The runner does it within ~5 minutes and retries on failure
 *       (after 1, 5, 30, 120 and 720 minutes). After the last try the job is "dead" and shows on
 *       System Health, where it can be retried or discarded.
 *
 * To add a job type: add a handler to bcrf_queue_handlers() (it gets the payload array and throws on
 * failure). To react to an event: add the job to bcrf_event_subscribers().
 */

if (!function_exists('bcrf_event')) {

/** What runs for each job type. Each handler gets (array $payload, PDO $pdo) and throws on failure. */
function bcrf_queue_handlers(): array {
    return [
        // Cloudflare cache purge (whole site, or 'urls' => [...]). No-op without Cloudflare credentials.
        'cdn.purge' => function (array $p, PDO $pdo): void {
            require_once __DIR__ . '/adapter_cloudflare.php';
            if (!bcrf_cdn_ready()) return;
            if (!bcrf_cdn_purge((array)($p['urls'] ?? []))) throw new RuntimeException('Cloudflare did not accept the cache purge (details in the PHP error log).');
        },
        // One email through the email adapter (admin/includes/email_template.php → bcf_send_mail).
        'email.send' => function (array $p, PDO $pdo): void {
            require_once dirname(__DIR__) . '/admin/includes/email_template.php';
            if (empty($p['to']) || empty($p['subject'])) throw new InvalidArgumentException('email.send needs to and subject');
            if (!bcf_send_mail((string)$p['to'], (string)$p['subject'], (string)($p['html'] ?? ''), (array)($p['opts'] ?? []))) {
                throw new RuntimeException('The mail server did not accept the message.');
            }
        },
        // Pinterest bulk drafts (Nov 2026): one checkpointed chunk of a batch, then queue the next chunk.
        // Only ever makes drafts (includes/pinterest.php); nothing is posted.
        'pinterest.batch' => function (array $p, PDO $pdo): void {
            require_once __DIR__ . '/pinterest.php';
            $id = (int)($p['batch_id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('pinterest.batch needs batch_id');
            $r = bcrf_pin_batch_step($pdo, $id);
            if (!$r['done']) {
                // worked 0 = someone else holds the batch (the "process now" button): look again in a minute
                bcrf_queue_push('pinterest.batch', ['batch_id' => $id], ['dedupe' => 'pinterest.batch:' . $id, 'delay' => $r['worked'] ? 0 : 60]);
            }
        },
    ];
}

/** Event type => jobs to queue: [job, payload builder(array $event): array, options]. */
function bcrf_event_subscribers(): array {
    return [
        // New or newly published content: clear the CDN cache so listing pages show it.
        'content.published' => [['cdn.purge', fn(array $e) => [], ['dedupe' => 'cdn.purge:all']]],
        // site_info.changed: Site Info purges right away itself (and queues a retry if that fails).
    ];
}

/** True when the 0004 tables exist (cached per request). */
function bcrf_queue_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
                               WHERE table_schema = DATABASE() AND table_name IN ('domain_events', 'queue_jobs')")->fetchColumn();
        return $ready = ($n === 2);
    } catch (Throwable $e) { return $ready = false; }
}

/** Who is acting: the signed-in staff member, else the system (cron, webhooks). */
function bcrf_actor(): array {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0 && PHP_SAPI !== 'cli') return ['staff', $uid];
    return ['system', null];
}

/**
 * Record a domain event. Returns its id, or 0 if the tables aren't there yet (never throws, so callers
 * don't need a try). $type is "thing.happened", e.g. content.published, booking.confirmed, pass.issued.
 */
function bcrf_event(string $type, string $objectType = '', $objectId = null, array $payload = []): int {
    global $pdo;
    if (!($pdo instanceof PDO) || !bcrf_queue_ready($pdo)) return 0;
    [$actorType, $actorId] = bcrf_actor();
    try {
        $pdo->prepare("INSERT INTO domain_events (occurred_at, type, object_type, object_id, actor_type, actor_id, payload_json)
                       VALUES (UTC_TIMESTAMP(3), ?, ?, ?, ?, ?, ?)")
            ->execute([mb_substr($type, 0, 80), mb_substr($objectType, 0, 100), $objectId === null ? null : mb_substr((string)$objectId, 0, 64),
                       $actorType, $actorId, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[queue] event ' . $type . ': ' . $e->getMessage());
        return 0;
    }
}

/**
 * Queue a job. Options: delay (seconds), max_attempts (default 5), dedupe (skip if a job with this key
 * is still waiting), event_id. Returns the job id, the waiting job's id when deduped, or 0 if the
 * queue isn't set up (never throws).
 */
function bcrf_queue_push(string $job, array $payload = [], array $opt = []): int {
    global $pdo;
    if (!($pdo instanceof PDO) || !bcrf_queue_ready($pdo)) return 0;
    try {
        $dedupe = isset($opt['dedupe']) ? mb_substr((string)$opt['dedupe'], 0, 190) : null;
        if ($dedupe !== null) {
            $st = $pdo->prepare("SELECT id FROM queue_jobs WHERE dedupe_key = ? AND status = 'pending' LIMIT 1");
            $st->execute([$dedupe]);
            if ($id = (int)$st->fetchColumn()) return $id;
        }
        $pdo->prepare("INSERT INTO queue_jobs (job, payload_json, status, attempts, max_attempts, run_after, dedupe_key, event_id, created_at)
                       VALUES (?, ?, 'pending', 0, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, ?, ?, UTC_TIMESTAMP())")
            ->execute([mb_substr($job, 0, 80), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                       max(1, (int)($opt['max_attempts'] ?? 5)), max(0, (int)($opt['delay'] ?? 0)), $dedupe,
                       isset($opt['event_id']) ? (int)$opt['event_id'] : null]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[queue] push ' . $job . ': ' . $e->getMessage());
        return 0;
    }
}

/** Minutes to wait before try number $attempt + 1. */
function bcrf_queue_backoff(int $attempt): int {
    return [1 => 1, 2 => 5, 3 => 30, 4 => 120][$attempt] ?? 720;
}

/** Turn new events into jobs. Returns how many events were handled. */
function bcrf_events_dispatch(PDO $pdo, int $limit = 200): int {
    $subs = bcrf_event_subscribers();
    $rows = $pdo->query("SELECT * FROM domain_events WHERE processed_at IS NULL ORDER BY id LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    $done = $pdo->prepare("UPDATE domain_events SET processed_at = UTC_TIMESTAMP() WHERE id = ?");
    foreach ($rows as $ev) {
        $ev['payload'] = json_decode((string)$ev['payload_json'], true) ?: [];
        foreach ($subs[$ev['type']] ?? [] as [$job, $build, $opt]) {
            bcrf_queue_push($job, $build($ev), $opt + ['event_id' => (int)$ev['id']]);
        }
        $done->execute([$ev['id']]);
    }
    return count($rows);
}

/**
 * Work through due jobs for up to $seconds. Returns ['done' => n, 'retry' => n, 'dead' => n].
 * Only one runner works at a time (admin/cron/run.php holds a database lock).
 */
function bcrf_queue_work(PDO $pdo, int $seconds = 60): array {
    $handlers = bcrf_queue_handlers();
    $stats = ['done' => 0, 'retry' => 0, 'dead' => 0];
    // A job left "running" for 15 minutes was cut off (timeout, server restart): let it run again.
    $pdo->exec("UPDATE queue_jobs SET status = 'pending', locked_at = NULL, locked_by = NULL
                WHERE status = 'running' AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE");
    $me = substr(bin2hex(random_bytes(8)), 0, 16);
    $claim = $pdo->prepare("UPDATE queue_jobs SET status = 'running', locked_at = UTC_TIMESTAMP(), locked_by = ?, attempts = attempts + 1
                            WHERE status = 'pending' AND run_after <= UTC_TIMESTAMP() ORDER BY run_after, id LIMIT 1");
    $get   = $pdo->prepare("SELECT * FROM queue_jobs WHERE locked_by = ? AND status = 'running' LIMIT 1");
    $ok    = $pdo->prepare("UPDATE queue_jobs SET status = 'done', finished_at = UTC_TIMESTAMP(), locked_by = NULL, last_error = NULL WHERE id = ?");
    $fail  = $pdo->prepare("UPDATE queue_jobs SET status = ?, run_after = UTC_TIMESTAMP() + INTERVAL ? MINUTE, locked_at = NULL, locked_by = NULL,
                                   last_error = ?, finished_at = IF(? = 'dead', UTC_TIMESTAMP(), NULL) WHERE id = ?");
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $claim->execute([$me]);
        if ($claim->rowCount() === 0) break;
        $get->execute([$me]);
        $j = $get->fetch(PDO::FETCH_ASSOC);
        if (!$j) break;
        try {
            $h = $handlers[$j['job']] ?? null;
            if (!$h) throw new RuntimeException('No handler for job type "' . $j['job'] . '" in includes/queue.php.');
            $h(json_decode((string)$j['payload_json'], true) ?: [], $pdo);
            $ok->execute([$j['id']]);
            $stats['done']++;
        } catch (Throwable $e) {
            $dead = (int)$j['attempts'] >= (int)$j['max_attempts'];
            $status = $dead ? 'dead' : 'pending';
            $fail->execute([$status, $dead ? 0 : bcrf_queue_backoff((int)$j['attempts']), mb_substr($e->getMessage(), 0, 1000), $status, $j['id']]);
            $stats[$dead ? 'dead' : 'retry']++;
            error_log('[queue] job ' . $j['id'] . ' ' . $j['job'] . ($dead ? ' gave up: ' : ' will retry: ') . $e->getMessage());
        }
        $claim->closeCursor();
    }
    return $stats;
}

/** Delete finished jobs and handled events older than 90 days (dead jobs are kept for review). */
function bcrf_queue_prune(PDO $pdo): array {
    $j = $pdo->exec("DELETE FROM queue_jobs WHERE status = 'done' AND finished_at < UTC_TIMESTAMP() - INTERVAL 90 DAY");
    $e = $pdo->exec("DELETE FROM domain_events WHERE processed_at IS NOT NULL AND processed_at < UTC_TIMESTAMP() - INTERVAL 90 DAY");
    return ['jobs' => (int)$j, 'events' => (int)$e];
}

/** Counts for System Health and the Dashboard banner. */
function bcrf_queue_summary(PDO $pdo): array {
    $s = ['ready' => bcrf_queue_ready($pdo), 'pending' => 0, 'running' => 0, 'dead' => 0, 'retrying' => 0, 'done_24h' => 0,
          'oldest_pending' => null, 'events_waiting' => 0, 'events_24h' => 0];
    if (!$s['ready']) return $s;
    try {
        foreach ($pdo->query("SELECT status, COUNT(*) n FROM queue_jobs GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) as $st => $n) {
            if (isset($s[$st])) $s[$st] = (int)$n;
        }
        $s['retrying']       = (int)$pdo->query("SELECT COUNT(*) FROM queue_jobs WHERE status = 'pending' AND attempts > 0")->fetchColumn();
        $s['done_24h']       = (int)$pdo->query("SELECT COUNT(*) FROM queue_jobs WHERE status = 'done' AND finished_at > UTC_TIMESTAMP() - INTERVAL 1 DAY")->fetchColumn();
        $s['oldest_pending'] = $pdo->query("SELECT MIN(created_at) FROM queue_jobs WHERE status = 'pending' AND run_after <= UTC_TIMESTAMP()")->fetchColumn() ?: null;
        $s['events_waiting'] = (int)$pdo->query("SELECT COUNT(*) FROM domain_events WHERE processed_at IS NULL")->fetchColumn();
        $s['events_24h']     = (int)$pdo->query("SELECT COUNT(*) FROM domain_events WHERE occurred_at > UTC_TIMESTAMP() - INTERVAL 1 DAY")->fetchColumn();
    } catch (Throwable $e) { error_log('[queue] summary: ' . $e->getMessage()); }
    return $s;
}

// ── The runner's schedule ────────────────────────────────────────────────────────────────────────
// Used only when "the runner starts the scheduled jobs" is on (System Health). Until then cPanel keeps
// starting each script itself and the runner only works the queue. Times are farm time (Settings).
// every = minutes between runs; at = 'HH:MM' daily; day = 0 (Sunday) … 6 for weekly; timeout in minutes.

/** job => [script (from the site root), schedule]. Same names as bcrf_cron_jobs() in cron_health.php. */
function bcrf_runner_tasks(): array {
    return [
        'publish_scheduled'      => ['admin/cron/publish_scheduled.php',      ['every' => 15]],
        'send_social'            => ['admin/cron/send_social.php',            ['every' => 15]],
        'send_newsletter'        => ['admin/cron/send_newsletter.php',        ['every' => 20]],
        'send_booking_reminders' => ['admin/cron/send_booking_reminders.php', ['every' => 60]],
        'send_review_requests'   => ['admin/cron/send_review_requests.php',   ['every' => 60]],
        'backup_db'              => ['admin/cron/backup_db.php',              ['at' => '03:15', 'timeout' => 20]],
        'purge_job_records'      => ['admin/cron/purge_job_records.php',      ['at' => '03:20']],
        'backup_files'           => ['admin/cron/backup_files.php',           ['at' => '03:40', 'day' => 0, 'timeout' => 40]],
        'fetch_google_reports'   => ['admin/cron/fetch_google_reports.php',   ['at' => '06:10']],
        'check_stock_alerts'     => ['admin/handlers/check_stock_alerts.php', ['at' => '08:00']],
        'check_social_token'     => ['admin/cron/check_social_token.php',     ['at' => '08:05']],
    ];
}

/** Is the runner starting the scheduled jobs? (site_settings runner_schedule = 1) */
function bcrf_runner_schedule_on(PDO $pdo): bool {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'runner_schedule'");
        $st->execute();
        return (string)$st->fetchColumn() === '1';
    } catch (Throwable $e) { return false; }
}

/**
 * When a task should next start, as a Unix time, given when it last started (UTC 'Y-m-d H:i:s' or null).
 * A job that has never run is due now (interval) or at its next daily/weekly time.
 */
function bcrf_runner_next(array $sched, ?string $lastStartUtc, ?int $now = null): int {
    $now = $now ?? time();
    $last = $lastStartUtc ? strtotime($lastStartUtc . ' UTC') : null;
    if (isset($sched['every'])) {
        // 1 minute early is fine: cron fires every 5 minutes, so "every 15" shouldn't drift to 20.
        return $last ? $last + ((int)$sched['every'] - 1) * 60 : $now;
    }
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    [$hh, $mm] = array_map('intval', explode(':', (string)$sched['at']));
    $d = (new DateTime('@' . $now))->setTimezone($tz);
    $slot = (clone $d)->setTime($hh, $mm);
    if (isset($sched['day'])) {
        $back = ((int)$d->format('w') - (int)$sched['day'] + 7) % 7;
        $slot->modify("-{$back} days");
        if ($slot->getTimestamp() > $now) $slot->modify('-7 days');   // the most recent slot at or before now
        $step = '+7 days';
    } else {
        if ($slot->getTimestamp() > $now) $slot->modify('-1 day');
        $step = '+1 day';
    }
    // Due now if the most recent slot hasn't been run yet (only catch up within 6 hours, so a job that
    // was off for a week doesn't fire at a strange time); otherwise the next slot.
    if (($last === null || $last < $slot->getTimestamp()) && $now - $slot->getTimestamp() < 6 * 3600) return $slot->getTimestamp();
    return $slot->modify($step)->getTimestamp();
}

/** Can this server start a script as a separate process? (Needed for the schedule, not the queue.) */
function bcrf_runner_can_spawn(): bool {
    $off = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('proc_open') && !in_array('proc_open', $off, true);
}

}
