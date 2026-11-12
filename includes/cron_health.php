<?php
// includes/cron_health.php
//
// Cron job heartbeats (Nov 2026). Each cron script calls bcrf_cron_begin('name') right
// after loading config.php. The run is recorded in cron_runs:
//   - finished normally          → last_ok
//   - bcrf_cron_exit(1) / fatal  → last_fail + message
// A job is "late" when its last good run is older than its allowed gap below (about twice
// its normal schedule). Late or failing jobs show on the admin Dashboard
// (admin/views/layout/cron_health_banner.php), on /admin/system_health.php, and make
// /health.php?jobs=1 answer 503 so UptimeRobot emails you.
//
// Jobs that have never recorded a run are listed on the System Health page but don't
// raise alarms (that usually means the cron isn't set up in cPanel yet).

if (!function_exists('bcrf_cron_jobs')) {

/** name => [label, allowed gap in minutes, schedule note]. Adjust the gaps to match cPanel. */
function bcrf_cron_jobs(): array {
    // Schedules match cPanel → Cron Jobs as of Nov 4, 2026.
    return [
        'run'                    => ['Farm OS runner (job queue)', 20,          'every 5 minutes'],   // admin/cron/run.php (Phase 0)
        'publish_scheduled'      => ['Scheduled blog posts',       60,          'every 15 minutes'],
        'send_social'            => ['Facebook & Instagram posts', 60,          'every 17 minutes'],
        'send_newsletter'        => ['Newsletter sending',         90,          'every 20 minutes'],
        'send_booking_reminders' => ['Booking reminder emails',    3 * 60,      'hourly'],
        'send_review_requests'   => ['Review request emails',      3 * 60,      'hourly'],
        'check_stock_alerts'     => ['Low-stock email',            26 * 60,     'daily, 8:00 AM'],
        'check_social_token'     => ['Facebook token check',       26 * 60,     'daily, 8:05 AM'],
        'backup_db'              => ['Database backup',            26 * 60,     'daily, 3:15 AM'],
        'purge_job_records'      => ['Hiring records cleanup',     26 * 60,     'daily, 3:20 AM'],
        'rewards_expire'         => ['Roost Rewards expiry',       26 * 60,     'daily, 3:25 AM'],
        'generate_tasks'         => ['Chore tasks for the week',   26 * 60,     'daily, 2:30 AM'],   // Farm OS Phase 3 (Nov 11)
        'task_digest'            => ['Morning task list email',    26 * 60,     'daily, 6:30 AM'],   // Phase 3 slice 2 (Nov 11)
        'task_alerts'            => ['Late-task emails',           60,          'every 15 minutes'], // Phase 3 slice 2 (Nov 11)
        'event_tasks'            => ['Party & booking checklists', 60,          'every 15 minutes'], // Phase 3 slice 3 (Nov 11)
        'backup_files'           => ['Website files backup',       8 * 24 * 60, 'weekly, Sunday 3:40 AM'],
        'fetch_google_reports'   => ['Search & Analytics refresh', 26 * 60,     'daily, 6:10 AM'],
        'fetch_social_reports'   => ['Social Media refresh',       26 * 60,     'daily, 6:20 AM'],
    ];
}

/** The cron_runs table comes from migration 0001 (Farm OS Phase 0), so this no longer changes the database. */
function bcrf_cron_schema(PDO $pdo): void {
}

/** Start recording this run. Safe to call when the DB is down (does nothing). */
function bcrf_cron_begin(string $job): void {
    global $pdo;
    $GLOBALS['__bcrf_cron'] = ['job' => $job, 't0' => microtime(true), 'failed' => null];
    try {
        bcrf_cron_schema($pdo);
        $now = gmdate('Y-m-d H:i:s');   // all times stored in UTC (MySQL's own clock/zone isn't trusted)
        $pdo->prepare("INSERT INTO cron_runs (job, last_start) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_start = ?")->execute([$job, $now, $now]);
    } catch (Throwable $e) { error_log('[cron_health] begin ' . $job . ': ' . $e->getMessage()); }
    register_shutdown_function('bcrf_cron_finish');
}

/** Mark the run failed (message shown in the admin) and exit with $code. */
function bcrf_cron_exit(int $code = 1, string $message = ''): void {
    if (isset($GLOBALS['__bcrf_cron'])) {
        $GLOBALS['__bcrf_cron']['failed'] = $message !== '' ? $message : 'Stopped with an error (exit code ' . $code . '). Details are in the cron email / PHP error log.';
    }
    exit($code);
}

/** Shutdown handler: record ok, a failure, or a fatal PHP error. */
function bcrf_cron_finish(): void {
    global $pdo;
    $c = $GLOBALS['__bcrf_cron'] ?? null;
    if (!$c || !empty($c['recorded'])) return;
    $GLOBALS['__bcrf_cron']['recorded'] = true;
    $err = error_get_last();
    $fatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    $failMsg = $c['failed'] ?? ($fatal ? 'PHP fatal error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')' : null);
    $ms = (int)round((microtime(true) - $c['t0']) * 1000);
    try {
        if ($failMsg === null) {
            $pdo->prepare("UPDATE cron_runs SET last_ok = ?, last_ms = ?, fails_in_row = 0 WHERE job = ?")->execute([gmdate('Y-m-d H:i:s'), $ms, $c['job']]);
        } else {
            $pdo->prepare("UPDATE cron_runs SET last_fail = ?, last_ms = ?, last_message = ?, fails_in_row = fails_in_row + 1 WHERE job = ?")
                ->execute([gmdate('Y-m-d H:i:s'), $ms, mb_substr($failMsg, 0, 500), $c['job']]);
        }
    } catch (Throwable $e) { error_log('[cron_health] finish ' . $c['job'] . ': ' . $e->getMessage()); }
}

/**
 * Status of every known job:
 *   state: ok | late | failing | never
 */
function bcrf_cron_status(PDO $pdo): array {
    $rows = [];
    try {
        bcrf_cron_schema($pdo);
        foreach ($pdo->query("SELECT * FROM cron_runs")->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['job']] = $r;
    } catch (Throwable $e) { /* table missing → all "never" */ }
    $now = time();
    $out = [];
    foreach (bcrf_cron_jobs() as $job => [$label, $gapMin, $sched]) {
        $r = $rows[$job] ?? null;
        $ok = $r && $r['last_ok'] ? strtotime($r['last_ok'] . ' UTC') : null;
        $fail = $r && $r['last_fail'] ? strtotime($r['last_fail'] . ' UTC') : null;
        if (!$r || (!$ok && !$fail)) $state = 'never';
        elseif ($fail && (!$ok || $fail > $ok)) $state = 'failing';
        elseif ($now - $ok > $gapMin * 60) $state = 'late';
        else $state = 'ok';
        $out[$job] = ['job' => $job, 'label' => $label, 'schedule' => $sched, 'gap_min' => $gapMin, 'state' => $state,
                      'last_ok' => $r['last_ok'] ?? null, 'last_fail' => $r['last_fail'] ?? null, 'last_start' => $r['last_start'] ?? null,
                      'message' => $r['last_message'] ?? null, 'ms' => $r['last_ms'] ?? null, 'fails_in_row' => (int)($r['fails_in_row'] ?? 0)];
    }
    return $out;
}

/** Jobs needing attention (late or failing). */
function bcrf_cron_problems(PDO $pdo): array {
    return array_values(array_filter(bcrf_cron_status($pdo), fn($j) => in_array($j['state'], ['late', 'failing'], true)));
}

/** "3 hours ago" style. */
function bcrf_ago(?string $dt): string {
    if (!$dt) return 'never';
    $s = max(0, time() - strtotime($dt . ' UTC'));   // stored in UTC
    if ($s < 90) return 'just now';
    if ($s < 5400) return round($s / 60) . ' min ago';
    if ($s < 129600) return round($s / 3600) . ' hours ago';
    return round($s / 86400) . ' days ago';
}

}
