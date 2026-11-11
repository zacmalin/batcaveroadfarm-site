<?php
/**
 * admin/cron/run.php — the Farm OS runner (Phase 0, Nov 2026). One cPanel cron entry, every 5 minutes:
 *   *\/5 * * * *  /usr/bin/php /home2/ddailnmy/public_html/batcaveroadfarm/admin/cron/run.php
 *
 * Each run:
 *   1. Turns new domain events into jobs (includes/queue.php → bcrf_event_subscribers()).
 *   2. Works the job queue for up to 2 minutes, with retries.
 *   3. If "the runner starts the scheduled jobs" is on (System Health), starts each due script from
 *      bcrf_runner_tasks() as its own process. Off by default: cPanel keeps starting them until the runner
 *      has been healthy for a week, then the other cPanel entries are removed.
 *   4. Once a day, deletes finished jobs and handled events older than 90 days.
 * Only one runner works at a time (MySQL lock). Its heartbeat is "run" on System Health.
 * Quiet by default (cPanel emails any cron output); add -v to see what it did.
 */

// Block web requests only. (A non-CLI PHP binary such as /usr/local/bin/php used to make this exit silently,
// so the runner never recorded a run — Nov 5, 2026. Use /usr/bin/php in cPanel.)
if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD'])) { http_response_code(404); exit; }
define('CRON_ALLOWED', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/cron_health.php';
require_once dirname(__DIR__, 2) . '/includes/queue.php';
bcrf_cron_begin('run');
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

$verbose = in_array('-v', $argv ?? [], true);
$out = function (string $s) use ($verbose): void { if ($verbose) echo '[' . date('Y-m-d H:i:s') . '] ' . $s . "\n"; };

// One runner at a time. A second copy (the last run is still busy) exits quietly as a good run.
if ((int)$pdo->query("SELECT GET_LOCK('bcrf_runner', 0)")->fetchColumn() !== 1) {
    $out('Another runner is still working; skipping this turn.');
    exit(0);
}

if (!bcrf_queue_ready($pdo)) {
    bcrf_cron_exit(1, 'The queue tables are missing. Run migration 0004 in Settings › Database.');
}

// 1 + 2: events → jobs, then the queue
$events = bcrf_events_dispatch($pdo);
$stats  = bcrf_queue_work($pdo, 120);
$out("Events handled: {$events}. Jobs done: {$stats['done']}, retrying: {$stats['retry']}, gave up: {$stats['dead']}.");

// 3: scheduled scripts (only when switched on)
$problems = [];
if (bcrf_runner_schedule_on($pdo)) {
    if (!bcrf_runner_can_spawn()) {
        bcrf_cron_exit(1, 'This server does not allow proc_open, so the runner cannot start the scheduled jobs. Turn the switch off on System Health and keep the cPanel entries.');
    }
    $last = [];
    try { $last = $pdo->query("SELECT job, last_start FROM cron_runs")->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) {}
    $root = dirname(__DIR__, 2);
    // Children must run under CLI PHP. cPanel's cron can start this runner as php-cgi, and a child started with
    // php-cgi thinks it's a web request, prints "403 / Direct web access blocked" and exits 0 (Nov 11, 2026).
    $phpCli = PHP_BINARY;
    if (PHP_SAPI !== 'cli' || preg_match('/php-cgi$/', $phpCli)) {
        $phpCli = '';
        foreach ([preg_replace('/php-cgi$/', 'php', PHP_BINARY), '/usr/local/bin/php', '/usr/bin/php'] as $cand) {
            if ($cand && $cand !== PHP_BINARY && is_file($cand) && is_executable($cand)) { $phpCli = $cand; break; }
        }
        if ($phpCli === '') bcrf_cron_exit(1, 'No CLI PHP binary found for starting the scheduled jobs (runner is ' . PHP_BINARY . ').');
    }
    foreach (bcrf_runner_tasks() as $job => [$script, $sched]) {
        if (bcrf_runner_next($sched, $last[$job] ?? null) > time()) continue;
        $path = $root . '/' . $script;
        if (!is_file($path)) { $problems[] = "{$job}: {$script} is missing"; continue; }
        $out("Starting {$job}");
        // Note the start here too, so a script that dies before its own heartbeat isn't restarted every 5 minutes.
        try {
            $pdo->prepare("INSERT INTO cron_runs (job, last_start) VALUES (?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_start = UTC_TIMESTAMP()")->execute([$job]);
        } catch (Throwable $e) {}
        $t0 = microtime(true);
        $timeout = (int)($sched['timeout'] ?? 10) * 60;
        $p = proc_open([$phpCli, $path], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($path));
        if (!is_resource($p)) { $problems[] = "{$job}: could not start"; continue; }
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $log = '';
        while (true) {
            $log .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) break;
            if (microtime(true) - $t0 > $timeout) { proc_terminate($p); $problems[] = "{$job}: stopped after {$sched['timeout']} min"; break; }
            usleep(200000);
        }
        $log .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = $st['running'] ? -1 : (int)$st['exitcode'];
        proc_close($p);
        // A child that answered like a web page never ran its job (and never wrote its heartbeat).
        if (preg_match('/^Status: \d{3}|Direct web access blocked/m', $log)) $problems[] = "{$job}: started as a web request, did not run";
        $out(sprintf('%s finished (exit %d, %.1fs)%s', $job, $code, microtime(true) - $t0, trim($log) !== '' ? ': ' . mb_substr(trim($log), 0, 400) : ''));
        // Each script records its own heartbeat; a non-zero exit is already a failure there.
    }
}

// 4: daily cleanup (first run after 4 AM farm time)
try {
    $today = date('Y-m-d');
    $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'runner_pruned_on'");
    $st->execute();
    if ((int)date('G') >= 4 && (string)$st->fetchColumn() !== $today) {
        $n = bcrf_queue_prune($pdo);
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('runner_pruned_on', ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$today]);
        $out("Cleanup: removed {$n['jobs']} finished jobs and {$n['events']} old events.");
    }
} catch (Throwable $e) { $problems[] = 'cleanup: ' . $e->getMessage(); }

$pdo->query("SELECT RELEASE_LOCK('bcrf_runner')");
if ($problems) bcrf_cron_exit(1, implode('; ', $problems));
