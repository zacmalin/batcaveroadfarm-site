<?php
// includes/migrations.php — database migrations (Farm OS Phase 0, Nov 5, 2026).
//
// Every change to the database structure goes through a numbered file in admin/migrations/:
//     0001_baseline.php, 0002_audit_events.php, ...
// Each file returns either
//     a string of SQL (one or more statements separated by ;), or
//     a function (PDO $pdo): void   for changes that need PHP logic.
// Files are PHP (not .sql) because the server connector only writes text files of known types,
// and a PHP file that only `return`s prints nothing if someone opens it in a browser.
//
// The schema_migrations table records what ran, when (UTC), by whom, and a checksum of the file.
// 0001_baseline is the live structure captured on Nov 5, 2026. The live database records it as a
// "baseline" (already there, not run); a fresh install for another farm runs it for real.
//
// MySQL commits each structure change immediately, so a migration can't be rolled back halfway.
// Keep each migration small, make statements safe to repeat (IF NOT EXISTS) where MySQL allows,
// and the runner always checks for a recent backup first.

if (!function_exists('bcrf_migrations_dir')) {

function bcrf_migrations_dir(): string {
    return dirname(__DIR__) . '/admin/migrations';
}

function bcrf_migrations_ensure_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version     VARCHAR(20)  NOT NULL PRIMARY KEY,
        name        VARCHAR(190) NOT NULL,
        checksum    CHAR(64)     NOT NULL,
        baseline    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = structure already existed; recorded, not run',
        applied_at  DATETIME     NOT NULL COMMENT 'UTC',
        applied_by  INT          NULL,
        duration_ms INT          NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Migration files on disk, sorted: [version => ['version','name','file','checksum']]. */
function bcrf_migrations_files(): array {
    $out = [];
    foreach (glob(bcrf_migrations_dir() . '/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $f) {
        if (!preg_match('/^(\d{4})_([a-z0-9_]+)\.php$/', basename($f), $m)) continue;
        $out[$m[1]] = ['version' => $m[1], 'name' => $m[2], 'file' => $f, 'checksum' => hash_file('sha256', $f)];
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** Applied rows: [version => row]. */
function bcrf_migrations_applied(PDO $pdo): array {
    bcrf_migrations_ensure_table($pdo);
    $out = [];
    foreach ($pdo->query("SELECT * FROM schema_migrations ORDER BY version") as $r) $out[$r['version']] = $r;
    return $out;
}

/** Combined status, one row per version: state = applied | baseline | pending | changed | missing_file. */
function bcrf_migrations_status(PDO $pdo): array {
    $files = bcrf_migrations_files();
    $done  = bcrf_migrations_applied($pdo);
    $rows  = [];
    foreach ($files as $v => $f) {
        $a = $done[$v] ?? null;
        $state = !$a ? 'pending' : ((int)$a['baseline'] === 1 ? 'baseline' : 'applied');
        if ($a && $a['checksum'] !== $f['checksum']) $state = 'changed';
        $rows[$v] = $f + ['state' => $state, 'applied' => $a];
    }
    foreach ($done as $v => $a) {
        if (!isset($files[$v])) $rows[$v] = ['version' => $v, 'name' => $a['name'], 'file' => null, 'checksum' => $a['checksum'], 'state' => 'missing_file', 'applied' => $a];
    }
    ksort($rows, SORT_STRING);
    return $rows;
}

/** Split SQL text into statements on ; outside quotes, backticks and comments. No DELIMITER support. */
function bcrf_sql_split(string $sql): array {
    $out = []; $buf = ''; $len = strlen($sql); $q = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $buf .= $c;
            if ($c === '\\' && $q !== '`' && $i + 1 < $len) { $buf .= $sql[++$i]; continue; }
            if ($c === $q) {
                if ($i + 1 < $len && $sql[$i + 1] === $q) { $buf .= $sql[++$i]; continue; }   // doubled quote
                $q = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $buf .= $c; continue; }
        if ($c === '-' && substr($sql, $i, 3) === '-- ' || $c === '#') {          // line comment
            $nl = strpos($sql, "\n", $i); $i = $nl === false ? $len : $nl; $buf .= "\n"; continue;
        }
        if ($c === '/' && substr($sql, $i, 2) === '/*') {                           // block comment
            $end = strpos($sql, '*/', $i + 2); $i = $end === false ? $len : $end + 1; continue;
        }
        if ($c === ';') { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

/** Run one migration file and record it. Returns [ok(bool), message]. Stops at the first failing statement. */
function bcrf_migration_apply(PDO $pdo, array $m, ?int $userId): array {
    $t0 = microtime(true);
    $body = (static function (string $__f) { return require $__f; })($m['file']);
    $n = 0;
    try {
        if (is_callable($body)) {
            $body($pdo);
            $n = 1;
        } elseif (is_string($body)) {
            foreach (bcrf_sql_split($body) as $stmt) { $n++; $pdo->exec($stmt); }
        } else {
            return [false, $m['version'] . ': the file must return SQL text or a function.'];
        }
    } catch (Throwable $e) {
        return [false, $m['version'] . '_' . $m['name'] . ' stopped at statement ' . $n . ': ' . $e->getMessage()];
    }
    $st = $pdo->prepare("INSERT INTO schema_migrations (version, name, checksum, baseline, applied_at, applied_by, duration_ms)
                         VALUES (?, ?, ?, 0, UTC_TIMESTAMP(), ?, ?)");
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $st->execute([$m['version'], $m['name'], $m['checksum'], $userId, $ms]);
    return [true, $m['version'] . '_' . $m['name'] . ' applied (' . $n . ' step' . ($n === 1 ? '' : 's') . ', ' . $ms . ' ms)'];
}

/** Run every pending migration in order. Refuses if any applied file has changed. Returns [ok, log lines]. */
function bcrf_migrations_run_pending(PDO $pdo, ?int $userId): array {
    $log = [];
    $status = bcrf_migrations_status($pdo);
    foreach ($status as $r) {
        if ($r['state'] === 'changed') return [false, ['Refused: ' . $r['version'] . '_' . $r['name'] . ' was edited after it ran. Put a new migration in a new file instead.']];
    }
    foreach ($status as $r) {
        if ($r['state'] !== 'pending') continue;
        [$ok, $msg] = bcrf_migration_apply($pdo, $r, $userId);
        $log[] = $msg;
        if (!$ok) return [false, $log];
    }
    if (!$log) $log[] = 'Nothing to run: the database is up to date.';
    return [true, $log];
}

/** Record 0001_baseline as already present on an existing database (live farm). */
function bcrf_migrations_adopt_baseline(PDO $pdo, ?int $userId): array {
    $files = bcrf_migrations_files();
    if (!isset($files['0001'])) return [false, 'No 0001 baseline file yet. Capture the schema first.'];
    if (bcrf_migrations_applied($pdo)) return [false, 'Migrations are already being tracked here.'];
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'schema_migrations'")->fetchColumn();
    if ($tables === 0) return [false, 'This database is empty, so 0001 should be run, not adopted.'];
    $f = $files['0001'];
    $st = $pdo->prepare("INSERT INTO schema_migrations (version, name, checksum, baseline, applied_at, applied_by, duration_ms)
                         VALUES (?, ?, ?, 1, UTC_TIMESTAMP(), ?, 0)");
    $st->execute([$f['version'], $f['name'], $f['checksum'], $userId]);
    return [true, 'Recorded 0001_' . $f['name'] . ' as the starting point (' . $tables . ' tables already here; nothing was run).'];
}

/** Structure-only snapshot of the current database, ready to become a migration. Returns [sql, counts]. */
function bcrf_schema_snapshot(PDO $pdo): array {
    $tables = []; $views = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {   // by position: the site's default fetch mode is by name
        $name = (string)$r[0];
        if ($name === 'schema_migrations') continue;
        if (strtoupper((string)$r[1]) === 'VIEW') $views[] = $name; else $tables[] = $name;
    }
    sort($tables, SORT_STRING); sort($views, SORT_STRING);
    $q = fn(string $n) => '`' . str_replace('`', '``', $n) . '`';
    $sql = "-- Bat Cave Road Farm / Farm OS: database structure (no data)\n"
         . '-- Captured ' . gmdate('Y-m-d H:i') . " UTC from the live database\n"
         . '-- ' . count($tables) . ' tables, ' . count($views) . " views\n\n"
         . "SET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $t) {
        $create = (string)$pdo->query('SHOW CREATE TABLE ' . $q($t))->fetch(PDO::FETCH_NUM)[1];
        $create = preg_replace('/\sAUTO_INCREMENT=\d+/', '', $create);
        $create = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $create);
        $sql .= $create . ";\n\n";
    }
    foreach ($views as $v) {
        $create = (string)$pdo->query('SHOW CREATE VIEW ' . $q($v))->fetch(PDO::FETCH_NUM)[1];
        $create = preg_replace('/\s*DEFINER=`[^`]*`@`[^`]*`/', '', $create);
        $create = preg_replace('/\s*SQL SECURITY DEFINER/', '', $create);
        $create = preg_replace('/^CREATE\s+/', 'CREATE OR REPLACE ', $create);
        $sql .= $create . ";\n\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $triggers = 0; $routines = 0;
    try { $triggers = count($pdo->query('SHOW TRIGGERS')->fetchAll()); } catch (Throwable $e) {}
    try { $routines = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()")->fetchColumn(); } catch (Throwable $e) {}
    return [$sql, ['tables' => count($tables), 'views' => count($views), 'triggers' => $triggers, 'routines' => $routines]];
}

/** Write the snapshot where the developer tools can read it; the folder is closed to the web. */
function bcrf_schema_snapshot_save(PDO $pdo): array {
    [$sql, $counts] = bcrf_schema_snapshot($pdo);
    $dir = bcrf_migrations_dir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    $file = $dir . '/schema_snapshot.txt';
    if (@file_put_contents($file, $sql) === false) throw new RuntimeException('Could not write ' . $file);
    return $counts + ['bytes' => strlen($sql), 'file' => 'admin/migrations/schema_snapshot.txt'];
}

/** Turn the saved snapshot into admin/migrations/0001_baseline.php (once). Returns the new file's SHA-256. */
function bcrf_baseline_from_snapshot(): string {
    $dir = bcrf_migrations_dir();
    $snap = $dir . '/schema_snapshot.txt';
    $out  = $dir . '/0001_baseline.php';
    if (is_file($out)) throw new RuntimeException('0001_baseline.php already exists. It must never be replaced.');
    if (!is_file($snap)) throw new RuntimeException('Capture the structure first.');
    $sql = (string)file_get_contents($snap);
    if (preg_match('/^SQL;?$/m', $sql)) throw new RuntimeException('The snapshot contains a line that would end the file early.');
    $php = "<?php\n"
         . "// admin/migrations/0001_baseline.php — Farm OS Phase 0 (Nov 5, 2026)\n"
         . "// The database structure of the live Bat Cave Road Farm site as captured on Nov 5, 2026 (78 tables, no data),\n"
         . "// via admin → Settings → Database → Capture structure.\n"
         . "// On the live database this is recorded as the starting point (\"baseline\") and is never run.\n"
         . "// On a new, empty database it creates every table. Never edit this file: put changes in 0002 and later.\n"
         . "return <<<'SQL'\n" . $sql . "SQL;\n";
    if (@file_put_contents($out, $php) === false) throw new RuntimeException('Could not write ' . $out);
    return hash('sha256', $php);
}

/** Newest local database backup time (Unix), or 0. */
function bcrf_migrations_last_backup(): int {
    $dir = getenv('BCRF_BACKUP_DIR') ?: (dirname(__DIR__, 3) . '/bcrf-backups');
    $f = glob($dir . '/bcrf-db-*.sql.gz') ?: [];
    rsort($f);
    return $f ? (int)filemtime($f[0]) : 0;
}

}
