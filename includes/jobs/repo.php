<?php
// includes/jobs/repo.php
// Shared read/write helpers for jobs, categories, questions and hiring cycles.
// Used by the admin Hiring screens now, and by the public /jobs/ pages later.
// All writes use prepared statements. Business times are Eastern, made in PHP.

if (!function_exists('jobs_now')) {

    // ── Constants / labels ──────────────────────────────────────────────────

    /** NC minimum wage (follows federal). Work-trade value and hourly pay can't go below this. */
    if (!defined('JOBS_MIN_WAGE')) define('JOBS_MIN_WAGE', 7.25);

    function jobs_engagement_labels(): array
    {
        return ['paid' => 'Paid', 'work_trade' => 'Work-trade', 'internship' => 'Paid internship'];
    }

    function jobs_schedule_labels(): array
    {
        return ['full_time' => 'Full time', 'part_time' => 'Part time', 'seasonal' => 'Seasonal', 'temporary' => 'Temporary'];
    }

    function jobs_pay_unit_labels(): array
    {
        return ['hour' => 'per hour', 'week' => 'per week', 'month' => 'per month', 'stipend' => 'stipend (total)'];
    }

    function jobs_min_age_labels(): array
    {
        return [18 => '18 and older', 16 => '16 and older', 14 => '14 and older'];
    }

    function jobs_question_type_labels(): array
    {
        return [
            'yes_no'        => 'Yes / No',
            'short_text'    => 'Short answer',
            'long_text'     => 'Long answer',
            'single_choice' => 'Pick one',
            'multi_choice'  => 'Pick any',
            'number'        => 'Number',
            'date'          => 'Date',
        ];
    }

    function jobs_choice_types(): array { return ['single_choice', 'multi_choice']; }

    // ── Time ────────────────────────────────────────────────────────────────

    /** Current Eastern date-time as 'Y-m-d H:i:s' (server clock is Mountain; never use NOW()). */
    function jobs_now(): string
    {
        return (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');
    }

    function jobs_today(): string
    {
        return substr(jobs_now(), 0, 10);
    }

    // ── Slugs ───────────────────────────────────────────────────────────────

    function jobs_slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim((string)$s, '-');
    }

    /** Slug unique within $table (jobs or job_categories), ignoring row $exceptId. */
    function jobs_unique_slug(PDO $pdo, string $table, string $base, int $exceptId = 0): string
    {
        if (!in_array($table, ['jobs', 'job_categories'], true)) throw new InvalidArgumentException('bad table');
        $base = jobs_slugify($base) ?: 'job';
        $base = trim(substr($base, 0, 80), '-');
        // Words used by the public /jobs/ URLs can't be job slugs.
        if ($table === 'jobs' && in_array($base, jobs_reserved_slugs(), true)) $base .= '-job';
        $slug = $base;
        $n = 2;
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE slug = ? AND id <> ?");
        while (true) {
            $st->execute([$slug, $exceptId]);
            if ((int)$st->fetchColumn() === 0) return $slug;
            $slug = $base . '-' . $n++;
        }
    }

    function jobs_reserved_slugs(): array { return ['thanks', 'alerts', 'apply', 'submit', 'index', 'file']; }

    // ── Categories ──────────────────────────────────────────────────────────

    function jobs_categories(PDO $pdo, bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM job_categories" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order, name";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function jobs_category(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM job_categories WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Jobs ────────────────────────────────────────────────────────────────

    function jobs_find(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT j.*, c.name AS category_name, c.allows_minors
                               FROM jobs j JOIN job_categories c ON c.id = j.category_id WHERE j.id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Every job with its open cycle (if any) and application counts for that cycle.
     * open_cycle_id is NULL when hiring is closed.
     */
    function jobs_list_with_cycles(PDO $pdo): array
    {
        $sql = "SELECT j.*, c.name AS category_name, c.sort_order AS cat_sort,
                       oc.id AS open_cycle_id, oc.openings, oc.hires_count, oc.closes_at, oc.opened_at,
                       (SELECT COUNT(*) FROM applications a WHERE a.job_cycle_id = oc.id AND a.is_spam = 0) AS cycle_apps,
                       (SELECT COUNT(*) FROM applications a WHERE a.job_cycle_id = oc.id AND a.is_spam = 0 AND a.status = 'new') AS cycle_new,
                       (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS total_apps
                  FROM jobs j
                  JOIN job_categories c ON c.id = j.category_id
             LEFT JOIN job_cycles oc ON oc.job_id = j.id AND oc.closed_at IS NULL
              ORDER BY j.is_active DESC, c.sort_order, c.name, j.title";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Validate + normalise job form input. Returns [array $data, array $errors].
     * $data keys match jobs columns (except slug/is_active).
     */
    function jobs_validate_job(PDO $pdo, array $in): array
    {
        $err = [];
        $t = fn($k, $max = 0) => ($v = trim((string)($in[$k] ?? ''))) === '' ? null : ($max ? mb_substr($v, 0, $max) : $v);
        $money = function ($k) use ($in) {
            $v = trim(str_replace(['$', ','], '', (string)($in[$k] ?? '')));
            return $v === '' ? null : (is_numeric($v) ? round((float)$v, 2) : false);
        };

        $d = [];
        $d['title'] = $t('title', 150);
        if ($d['title'] === null) $err[] = 'Job title is required.';

        $d['category_id'] = (int)($in['category_id'] ?? 0);
        $cat = $d['category_id'] ? jobs_category($pdo, $d['category_id']) : null;
        if (!$cat) $err[] = 'Pick a category.';

        $d['engagement_type'] = array_key_exists($in['engagement_type'] ?? '', jobs_engagement_labels()) ? $in['engagement_type'] : 'paid';
        $d['schedule_type']   = array_key_exists($in['schedule_type'] ?? '', jobs_schedule_labels()) ? $in['schedule_type'] : 'part_time';

        foreach (['summary' => 300, 'schedule_notes' => 300] as $k => $max) $d[$k] = $t($k, $max);
        foreach (['description', 'responsibilities', 'requirements', 'physical_requirements', 'work_trade_terms'] as $k) $d[$k] = $t($k);

        $d['pay_min'] = $money('pay_min');
        $d['pay_max'] = $money('pay_max');
        $d['work_trade_hourly_value'] = $money('work_trade_hourly_value');
        foreach (['pay_min' => 'Lowest pay', 'pay_max' => 'Highest pay', 'work_trade_hourly_value' => 'Work-trade value'] as $k => $lbl) {
            if ($d[$k] === false) { $err[] = "{$lbl} must be a number."; $d[$k] = null; }
        }
        $d['pay_unit'] = array_key_exists($in['pay_unit'] ?? '', jobs_pay_unit_labels()) ? $in['pay_unit'] : 'hour';

        if ($d['engagement_type'] === 'work_trade') {
            // Work-trade: no cash pay range; must state terms and an hourly value >= minimum wage.
            $d['pay_min'] = $d['pay_max'] = null;
            if ($d['work_trade_terms'] === null) $err[] = 'Describe what the work-trade includes (for example, produce share, housing).';
            if ($d['work_trade_hourly_value'] === null || $d['work_trade_hourly_value'] < JOBS_MIN_WAGE) {
                $err[] = 'Work-trade value per hour must be at least $' . number_format(JOBS_MIN_WAGE, 2) . ' (minimum wage).';
            }
        } else {
            $d['work_trade_terms'] = null;
            $d['work_trade_hourly_value'] = null;
            if ($d['pay_min'] === null) $err[] = 'Enter the pay (lowest, or the only rate).';
            if ($d['pay_min'] !== null && $d['pay_max'] !== null && $d['pay_max'] < $d['pay_min']) $err[] = 'Highest pay is lower than lowest pay.';
            if ($d['pay_unit'] === 'hour' && $d['pay_min'] !== null && $d['pay_min'] < JOBS_MIN_WAGE) {
                $err[] = 'Hourly pay must be at least $' . number_format(JOBS_MIN_WAGE, 2) . ' (minimum wage).';
            }
        }

        $age = (int)($in['min_age'] ?? 18);
        $d['min_age'] = in_array($age, [14, 16, 18], true) ? $age : 18;
        if ($d['min_age'] < 18 && $cat && !(int)$cat['allows_minors']) {
            $err[] = "{$cat['name']} jobs are 18 and older. Only categories marked \"allows under 18\" (Thrift Shop, Farmstand) can hire minors.";
        }

        $d['default_openings'] = max(1, min(99, (int)($in['default_openings'] ?? 1)));
        return [$d, $err];
    }

    function jobs_save_job(PDO $pdo, int $id, array $d, string $slugInput): int
    {
        $slugBase = $slugInput !== '' ? $slugInput : $d['title'];
        $d['slug'] = jobs_unique_slug($pdo, 'jobs', $slugBase, $id);
        $cols = array_keys($d);
        if ($id > 0) {
            $set = implode(', ', array_map(fn($c) => "`{$c}` = ?", $cols));
            $pdo->prepare("UPDATE jobs SET {$set} WHERE id = ?")->execute([...array_values($d), $id]);
            return $id;
        }
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $pdo->prepare("INSERT INTO jobs (`" . implode('`, `', $cols) . "`) VALUES ({$ph})")->execute(array_values($d));
        return (int)$pdo->lastInsertId();
    }

    // ── Cycles ──────────────────────────────────────────────────────────────

    function jobs_open_cycle_row(PDO $pdo, int $jobId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM job_cycles WHERE job_id = ? AND closed_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute([$jobId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function jobs_cycles(PDO $pdo, int $jobId): array
    {
        $st = $pdo->prepare("SELECT cy.*, (SELECT COUNT(*) FROM applications a WHERE a.job_cycle_id = cy.id AND a.is_spam = 0) AS apps
                               FROM job_cycles cy WHERE cy.job_id = ? ORDER BY cy.id DESC");
        $st->execute([$jobId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Start a new hiring round. Returns the new cycle id. Throws if one is already open. */
    function jobs_open_cycle(PDO $pdo, int $jobId, int $openings, ?string $closesAt, bool $autoClose): int
    {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("SELECT id FROM jobs WHERE id = ? FOR UPDATE");
            $st->execute([$jobId]);
            if (!$st->fetchColumn()) throw new RuntimeException('Job not found.');
            if (jobs_open_cycle_row($pdo, $jobId)) throw new RuntimeException('Hiring is already open for this job.');
            $pdo->prepare("INSERT INTO job_cycles (job_id, opened_at, closes_at, openings, auto_close_when_filled) VALUES (?, ?, ?, ?, ?)")
                ->execute([$jobId, jobs_now(), $closesAt, max(1, $openings), $autoClose ? 1 : 0]);
            $cid = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE jobs SET is_active = 1 WHERE id = ?")->execute([$jobId]);
            $pdo->commit();
            return $cid;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Close the open round (if any) and mark the job inactive. Returns closed cycle id or 0. */
    function jobs_close_cycle(PDO $pdo, int $jobId): int
    {
        $cy = jobs_open_cycle_row($pdo, $jobId);
        if ($cy) $pdo->prepare("UPDATE job_cycles SET closed_at = ? WHERE id = ? AND closed_at IS NULL")->execute([jobs_now(), $cy['id']]);
        $pdo->prepare("UPDATE jobs SET is_active = 0 WHERE id = ?")->execute([$jobId]);
        return $cy ? (int)$cy['id'] : 0;
    }

    /** Update openings / closing date / auto-close on the open round. */
    function jobs_update_cycle(PDO $pdo, int $cycleId, int $openings, ?string $closesAt, bool $autoClose): void
    {
        $pdo->prepare("UPDATE job_cycles SET openings = ?, closes_at = ?, auto_close_when_filled = ? WHERE id = ? AND closed_at IS NULL")
            ->execute([max(1, $openings), $closesAt, $autoClose ? 1 : 0, $cycleId]);
    }

    /** 'YYYY-MM-DD' or null; returns false if given but invalid / in the past. */
    function jobs_parse_closes(?string $v)
    {
        $v = trim((string)$v);
        if ($v === '') return null;
        $dt = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$dt || $dt->format('Y-m-d') !== $v) return false;
        if ($v < jobs_today()) return false;
        return $v;
    }

    /** True when a job is taking applications right now (open round, closing date not passed, not filled). */
    function jobs_is_accepting(array $row): bool
    {
        // $row = a jobs_list_with_cycles() row (needs open_cycle_id, closes_at).
        if (empty($row['open_cycle_id'])) return false;
        if (!empty($row['closes_at']) && $row['closes_at'] < jobs_today()) return false;
        return true;
    }

    // ── Questions ───────────────────────────────────────────────────────────

    function jobs_questions(PDO $pdo, bool $includeArchived = true): array
    {
        $sql = "SELECT q.*, (SELECT COUNT(*) FROM job_questions jq WHERE jq.question_id = q.id) AS used_on,
                       (SELECT COUNT(*) FROM question_options o WHERE o.question_id = q.id) AS option_count
                  FROM questions q" . ($includeArchived ? "" : " WHERE q.is_archived = 0") . "
              ORDER BY q.is_archived, q.label";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function jobs_question(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
        $st->execute([$id]);
        $q = $st->fetch(PDO::FETCH_ASSOC);
        if (!$q) return null;
        $q['options'] = jobs_question_options($pdo, $id);
        return $q;
    }

    function jobs_question_options(PDO $pdo, int $qid): array
    {
        $st = $pdo->prepare("SELECT * FROM question_options WHERE question_id = ? ORDER BY sort_order, id");
        $st->execute([$qid]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Questions attached to a job, in order, with bank details and options. */
    function jobs_job_questions(PDO $pdo, int $jobId): array
    {
        $st = $pdo->prepare("SELECT jq.*, q.label, q.help_text, q.type, q.is_archived
                               FROM job_questions jq JOIN questions q ON q.id = jq.question_id
                              WHERE jq.job_id = ? ORDER BY jq.sort_order, jq.id");
        $st->execute([$jobId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['options'] = in_array($r['type'], jobs_choice_types(), true) ? jobs_question_options($pdo, (int)$r['question_id']) : [];
        }
        return $rows;
    }

    /** Renumber sort_order 10, 20, 30 … for a job's questions in current order. */
    function jobs_renumber_job_questions(PDO $pdo, int $jobId): void
    {
        $st = $pdo->prepare("SELECT id FROM job_questions WHERE job_id = ? ORDER BY sort_order, id");
        $st->execute([$jobId]);
        $up = $pdo->prepare("UPDATE job_questions SET sort_order = ? WHERE id = ?");
        $n = 10;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $up->execute([$n, $id]); $n += 10; }
    }

    /** Starter questions offered when the bank is empty. [label, help, type, options[[label, knockout]], ] */
    function jobs_starter_questions(): array
    {
        return [
            ['Can you lift and carry 50 pounds?', 'Feed bags, hay bales and produce crates.', 'yes_no', []],
            ['Do you have reliable transportation to the farm?', 'We are on Bat Cave Road in Old Fort.', 'yes_no', []],
            ['Are you comfortable working outdoors in heat, cold and rain?', null, 'yes_no', []],
            ['Are you comfortable working around farm animals?', 'Goats, chickens, pigs and more.', 'yes_no', []],
            ['Can you work the schedule listed in this posting?', 'If not, tell us what works in your cover note.', 'yes_no', []],
            ['Tell us about any farm, animal care, retail or customer service experience.', null, 'long_text', []],
            ['How many hours a week would you like to work?', null, 'single_choice', [['Under 10', 0], ['10 to 20', 0], ['20 to 30', 0], ['30 or more', 0]]],
            ['How did you hear about this job?', null, 'single_choice', [['Our website', 0], ['Facebook or Instagram', 0], ['A friend or family member', 0], ['Sign at the farm', 0], ['Other', 0]]],
        ];
    }

    // ── Public site (pack P3) ───────────────────────────────────────────────

    /**
     * Jobs taking applications right now, for the public listing / sitemap / nav.
     * Rules: job active, category active, an open round, closing date not passed,
     * and not already filled when the round auto-closes on fill.
     */
    function jobs_public_open(PDO $pdo): array
    {
        $sql = "SELECT j.*, c.name AS category_name, c.slug AS category_slug, c.allows_minors, c.sort_order AS cat_sort,
                       oc.id AS open_cycle_id, oc.openings, oc.hires_count, oc.closes_at, oc.opened_at, oc.auto_close_when_filled
                  FROM jobs j
                  JOIN job_categories c ON c.id = j.category_id AND c.is_active = 1
                  JOIN job_cycles oc ON oc.job_id = j.id AND oc.closed_at IS NULL
                 WHERE j.is_active = 1
                   AND (oc.closes_at IS NULL OR oc.closes_at >= ?)
                   AND NOT (oc.auto_close_when_filled = 1 AND oc.hires_count >= oc.openings)
              ORDER BY c.sort_order, c.name, j.title";
        $st = $pdo->prepare($sql);
        $st->execute([jobs_today()]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** One job by slug with its open round (open_cycle_id NULL if none). */
    function jobs_find_by_slug(PDO $pdo, string $slug): ?array
    {
        $st = $pdo->prepare("SELECT j.*, c.name AS category_name, c.slug AS category_slug, c.allows_minors, c.is_active AS category_active,
                                    oc.id AS open_cycle_id, oc.openings, oc.hires_count, oc.closes_at, oc.opened_at, oc.auto_close_when_filled
                               FROM jobs j
                               JOIN job_categories c ON c.id = j.category_id
                          LEFT JOIN job_cycles oc ON oc.job_id = j.id AND oc.closed_at IS NULL
                              WHERE j.slug = ? LIMIT 1");
        $st->execute([$slug]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Public version of jobs_is_accepting(), for a jobs_find_by_slug() row. */
    function jobs_public_accepting(array $j): bool
    {
        if (!(int)$j['is_active'] || !(int)$j['category_active'] || empty($j['open_cycle_id'])) return false;
        if (!empty($j['closes_at']) && $j['closes_at'] < jobs_today()) return false;
        if ((int)$j['auto_close_when_filled'] && (int)$j['hires_count'] >= (int)$j['openings']) return false;
        return true;
    }

    /** "$15.00–$18.00 per hour", "Work-trade (valued at $12.00/hr)" etc. */
    function jobs_pay_summary(array $j): string
    {
        $m = fn($v) => '$' . number_format((float)$v, 2);
        if ($j['engagement_type'] === 'work_trade') {
            return 'Work-trade' . ($j['work_trade_hourly_value'] !== null ? ' (valued at ' . $m($j['work_trade_hourly_value']) . '/hr)' : '');
        }
        if ($j['pay_min'] === null) return '';
        $unit = jobs_pay_unit_labels()[$j['pay_unit'] ?? 'hour'] ?? '';
        $range = $m($j['pay_min']);
        if ($j['pay_max'] !== null && (float)$j['pay_max'] > (float)$j['pay_min']) $range .= '–' . $m($j['pay_max']);
        return trim($range . ' ' . $unit);
    }

    /** Plain text → safe HTML: lines starting with -, * or • become a list; other lines become paragraphs. */
    function jobs_text_html(?string $txt): string
    {
        $txt = trim((string)$txt);
        if ($txt === '') return '';
        $out = ''; $list = [];
        $flush = function () use (&$out, &$list) {
            if ($list) { $out .= '<ul>' . implode('', array_map(fn($l) => '<li>' . htmlspecialchars($l) . '</li>', $list)) . '</ul>'; $list = []; }
        };
        foreach (preg_split('/\R/', $txt) as $line) {
            $line = trim($line);
            if ($line === '') { $flush(); continue; }
            if (preg_match('/^(?:[-*•]|\d+[.)])\s+(.+)$/u', $line, $m)) { $list[] = $m[1]; continue; }
            $flush();
            $out .= '<p>' . htmlspecialchars($line) . '</p>';
        }
        $flush();
        return $out;
    }

    /** Age brackets an applicant may pick for a job with $minAge. */
    function jobs_age_brackets(int $minAge): array
    {
        $all = ['18_plus' => '18 or older', '16_17' => '16 or 17', '14_15' => '14 or 15'];
        if ($minAge >= 18) return ['18_plus' => $all['18_plus']];
        if ($minAge >= 16) return ['18_plus' => $all['18_plus'], '16_17' => $all['16_17']];
        return $all;
    }

    function jobs_availability_labels(): array
    {
        return ['weekdays' => 'Weekdays', 'weekends' => 'Weekends', 'mornings' => 'Mornings', 'afternoons' => 'Afternoons', 'evenings' => 'Evenings'];
    }

    /** True when there's at least one public opening (for the menu). Never throws. */
    function jobs_any_open(PDO $pdo): bool
    {
        try { return (bool)jobs_public_open($pdo); } catch (Throwable $e) { return false; }
    }
}
