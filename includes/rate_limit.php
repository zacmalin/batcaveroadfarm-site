<?php
/**
 * includes/rate_limit.php — server-side rate limiting (fix pack K).
 *
 * Unlike throttle_hit() in includes/auth.php (stored in the visitor's session,
 * so a bot can reset it by dropping cookies), these counters live on the
 * server, keyed by IP address or email. No database table is needed: each
 * counter is a tiny file in a folder OUTSIDE the web root:
 *
 *     /home2/<user>/bcrf-ratelimit/      (created automatically, mode 0700)
 *
 * Only a SHA-256 hash of the key is used as the filename, so no raw IPs or
 * email addresses are written to disk. Dependency-free (no config.php, no PDO),
 * so the contact form handler can use it too.
 *
 * Typical use:
 *     if (bcrf_rl_count('staff_ip', bcrf_rl_ip(), 900) >= 5) { ...blocked... }
 *     ... on failure:  bcrf_rl_add('staff_ip', bcrf_rl_ip(), 900);
 *     ... on success:  bcrf_rl_clear('staff_ip', bcrf_rl_ip());
 */

if (!function_exists('bcrf_rl_dir')) {

    /** Folder that holds the counters. Home folder first, system temp as fallback. */
    function bcrf_rl_dir(): string
    {
        static $dir = null;
        if ($dir !== null) return $dir;

        $candidates = [];
        if (getenv('BCRF_RL_DIR')) $candidates[] = rtrim((string)getenv('BCRF_RL_DIR'), '/');
        // includes/ -> site root -> public_html -> home folder (/home2/<user>)
        $candidates[] = dirname(__DIR__, 3) . '/bcrf-ratelimit';
        $candidates[] = rtrim(sys_get_temp_dir(), '/') . '/bcrf-ratelimit';

        foreach ($candidates as $c) {
            if (is_dir($c) || @mkdir($c, 0700, true)) {
                if (is_writable($c)) { return $dir = $c; }
            }
        }
        return $dir = ''; // nowhere writable: limiter is disabled (fails open, logs once)
    }

    /** Real visitor IP. Behind Cloudflare it is in CF-Connecting-IP. */
    function bcrf_rl_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $k) {
            $v = trim((string)($_SERVER[$k] ?? ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
        return '0.0.0.0';
    }

    /** Path of the counter file for one bucket + key. */
    function bcrf_rl_file(string $bucket, string $key): string
    {
        $d = bcrf_rl_dir();
        if ($d === '') return '';
        $bucket = preg_replace('/[^a-z0-9_]/', '', strtolower($bucket));
        return $d . '/' . $bucket . '-' . hash('sha256', $bucket . '|' . strtolower(trim($key))) . '.json';
    }

    /**
     * Open + lock a counter file, run $fn(array $timestamps): array, save result.
     * Returns the list of timestamps still inside the window.
     */
    function bcrf_rl_update(string $bucket, string $key, int $window, ?callable $fn): array
    {
        $file = bcrf_rl_file($bucket, $key);
        if ($file === '') {
            static $warned = false;
            if (!$warned) { error_log('[rate_limit] no writable folder - limiter disabled'); $warned = true; }
            return [];
        }

        $fh = @fopen($file, 'c+');
        if (!$fh) return [];
        try {
            flock($fh, LOCK_EX);
            $raw  = stream_get_contents($fh);
            $list = json_decode((string)$raw, true);
            if (!is_array($list)) $list = [];

            $cut  = time() - $window;
            $list = array_values(array_filter($list, fn($t) => is_int($t) && $t > $cut));

            if ($fn !== null) {
                $list = $fn($list);
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode(array_slice($list, -200))); // cap file size
                fflush($fh);
            }
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        bcrf_rl_gc();
        return $list;
    }

    /** How many events were recorded for this key in the last $window seconds. */
    function bcrf_rl_count(string $bucket, string $key, int $window): int
    {
        return count(bcrf_rl_update($bucket, $key, $window, null));
    }

    /** Record one event. Returns the new count inside the window. */
    function bcrf_rl_add(string $bucket, string $key, int $window): int
    {
        return count(bcrf_rl_update($bucket, $key, $window, function (array $l) { $l[] = time(); return $l; }));
    }

    /**
     * Record one event and report whether the limit is now exceeded.
     * Use for things that are counted on every attempt (contact form, signups).
     */
    function bcrf_rl_hit(string $bucket, string $key, int $max, int $window): bool
    {
        return bcrf_rl_add($bucket, $key, $window) > $max;
    }

    /** Forget a key (e.g. after a successful login). */
    function bcrf_rl_clear(string $bucket, string $key): void
    {
        $file = bcrf_rl_file($bucket, $key);
        if ($file !== '' && is_file($file)) @unlink($file);
    }

    /** Roughly 1 call in 50: delete counter files untouched for 2 days. */
    function bcrf_rl_gc(): void
    {
        if (random_int(1, 50) !== 1) return;
        $d = bcrf_rl_dir();
        if ($d === '') return;
        $old = time() - 172800;
        foreach ((array)glob($d . '/*.json') as $f) {
            if (is_file($f) && @filemtime($f) < $old) @unlink($f);
        }
    }
}

if (!function_exists('bcrf_login_locked')) {
    // ── Public (visitor) account logins ─────────────────────────────────────
    // Looser than staff: 10 failed tries per IP per 15 min, 20 per email per hour.

    /** True if this IP or email has too many recent failed logins. */
    function bcrf_login_locked(string $email): bool
    {
        $e = strtolower(trim($email));
        return bcrf_rl_count('login_ip', bcrf_rl_ip(), 900) >= 10
            || ($e !== '' && bcrf_rl_count('login_email', $e, 3600) >= 20);
    }

    function bcrf_login_failed(string $email): void
    {
        $e = strtolower(trim($email));
        bcrf_rl_add('login_ip', bcrf_rl_ip(), 900);
        if ($e !== '') bcrf_rl_add('login_email', $e, 3600);
    }

    function bcrf_login_success(string $email): void
    {
        bcrf_rl_clear('login_ip', bcrf_rl_ip());
        bcrf_rl_clear('login_email', strtolower(trim($email)));
    }
}
