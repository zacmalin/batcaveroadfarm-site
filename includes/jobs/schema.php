<?php
// includes/jobs/schema.php
// Database setup for Jobs & Applicant Tracking. Called by admin/jobs_install.php.
//
// Safe to run more than once: every step checks first and skips work that's
// already done. Nothing is dropped or deleted. Returns a list of report lines
// so the setup page can show exactly what happened.

if (!function_exists('jobs_install')) {

    /** Status keys used everywhere (8 statuses, decided 2026-10-23). */
    function jobs_status_keys(): array
    {
        return ['new', 'reviewing', 'interview', 'offer', 'hired', 'not_selected', 'withdrawn', 'talent_pool'];
    }

    /**
     * Run the setup. $dry = true only inspects and reports.
     * @return array{ok:bool, lines:array<int,array{0:string,1:string}>}
     *         each line = [level ('ok'|'skip'|'warn'|'error'|'info'), message]
     */
    function jobs_install(PDO $pdo, bool $dry = false): array
    {
        $lines = [];
        $ok    = true;
        $say   = function (string $lvl, string $msg) use (&$lines) { $lines[] = [$lvl, $msg]; };
        $exec  = function (string $sql) use ($pdo, $dry) { if (!$dry) $pdo->exec($sql); };

        $tableExists = function (string $t) use ($pdo): bool {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $st->execute([$t]);
            return (int)$st->fetchColumn() > 0;
        };
        $columns = function (string $t) use ($pdo): array {
            $out = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC) as $c) $out[$c['Field']] = $c;
            return $out;
        };

        $T = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $TS = "created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";

        // ── Guard: email tables with these names must not already exist with a different layout ──
        foreach (['email_templates' => 'tpl_key', 'email_queue' => 'rendered_subject', 'email_log' => 'template_key'] as $t => $mustHave) {
            if ($tableExists($t) && !isset($columns($t)[$mustHave])) {
                $say('error', "A table named {$t} already exists and isn't the Jobs version. Setup stopped so nothing gets mixed up. Send me its layout (phpMyAdmin > {$t} > Structure).");
                return ['ok' => false, 'lines' => $lines];
            }
        }

        // ── 1. New tables (16) ──────────────────────────────────────────────────
        $tables = [
            'job_categories' => "CREATE TABLE job_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(90) NOT NULL,
                allows_minors TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Thrift Shop + Farmstand only (non-farm work, NC youth certificate needed)',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$TS},
                UNIQUE KEY uq_job_categories_slug (slug)
            ){$T}",

            'jobs' => "CREATE TABLE jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT NOT NULL,
                title VARCHAR(150) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                engagement_type ENUM('paid','work_trade','internship') NOT NULL DEFAULT 'paid' COMMENT 'For-profit farm: no unpaid volunteer roles',
                schedule_type ENUM('full_time','part_time','seasonal','temporary') NOT NULL DEFAULT 'part_time',
                summary VARCHAR(300) NULL,
                description TEXT NULL,
                responsibilities TEXT NULL,
                requirements TEXT NULL,
                physical_requirements TEXT NULL,
                schedule_notes VARCHAR(300) NULL,
                pay_min DECIMAL(8,2) NULL,
                pay_max DECIMAL(8,2) NULL,
                pay_unit ENUM('hour','week','month','stipend') NULL DEFAULT 'hour',
                work_trade_terms TEXT NULL,
                work_trade_hourly_value DECIMAL(8,2) NULL COMMENT 'Value of work-trade per hour worked (must be at least minimum wage)',
                min_age TINYINT UNSIGNED NOT NULL DEFAULT 18 COMMENT '14, 16 or 18',
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                default_openings SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                {$TS},
                UNIQUE KEY uq_jobs_slug (slug),
                KEY idx_jobs_category (category_id),
                CONSTRAINT fk_jobs_category FOREIGN KEY (category_id) REFERENCES job_categories(id)
            ){$T}",

            'job_cycles' => "CREATE TABLE job_cycles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                opened_at DATETIME NOT NULL COMMENT 'Eastern time',
                closes_at DATE NULL COMMENT 'Last day to apply (Eastern); JobPosting validThrough',
                closed_at DATETIME NULL,
                openings SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                auto_close_when_filled TINYINT(1) NOT NULL DEFAULT 1,
                hires_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                alerts_sent_at DATETIME NULL,
                talent_pool_notified_at DATETIME NULL,
                {$TS},
                KEY idx_cycles_job (job_id, closed_at),
                CONSTRAINT fk_cycles_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
            ){$T}",

            'questions' => "CREATE TABLE questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(300) NOT NULL,
                help_text VARCHAR(500) NULL,
                type ENUM('yes_no','short_text','long_text','single_choice','multi_choice','number','date') NOT NULL DEFAULT 'short_text',
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                {$TS}
            ){$T}",

            'question_options' => "CREATE TABLE question_options (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT NOT NULL,
                label VARCHAR(200) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_knockout TINYINT(1) NOT NULL DEFAULT 0,
                {$TS},
                KEY idx_qopt_question (question_id),
                CONSTRAINT fk_qopt_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
            ){$T}",

            'job_questions' => "CREATE TABLE job_questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                question_id INT NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                knockout_answer ENUM('none','yes','no') NOT NULL DEFAULT 'none' COMMENT 'yes/no questions: this answer auto-rejects',
                {$TS},
                UNIQUE KEY uq_job_question (job_id, question_id),
                CONSTRAINT fk_jq_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                CONSTRAINT fk_jq_question FOREIGN KEY (question_id) REFERENCES questions(id)
            ){$T}",

            'applications' => "CREATE TABLE applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_cycle_id INT NOT NULL,
                job_id INT NOT NULL,
                user_id INT NULL COMMENT 'Set on hire',
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(190) NOT NULL,
                phone VARCHAR(30) NULL,
                age_bracket ENUM('14_15','16_17','18_plus') NOT NULL,
                guardian_name VARCHAR(150) NULL,
                guardian_phone VARCHAR(30) NULL,
                guardian_email VARCHAR(190) NULL,
                guardian_consent_at DATETIME NULL,
                availability JSON NULL,
                earliest_start DATE NULL,
                cover_note TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                is_spam TINYINT(1) NOT NULL DEFAULT 0,
                is_returning TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Applicant ticked: applied or worked here before',
                prior_match TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Email matched an earlier application or user (admin-only)',
                knockout_flag TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(50) NULL,
                ip_hash CHAR(64) NULL,
                interview_at DATETIME NULL,
                interview_location VARCHAR(255) NULL,
                submitted_at DATETIME NOT NULL COMMENT 'Eastern time',
                decided_at DATETIME NULL COMMENT 'Entered a closed status; retention clock starts here',
                archived_at DATETIME NULL,
                anonymized_at DATETIME NULL,
                {$TS},
                KEY idx_app_status (status, is_spam),
                KEY idx_app_cycle (job_cycle_id),
                KEY idx_app_job (job_id),
                KEY idx_app_email (email),
                CONSTRAINT fk_app_cycle FOREIGN KEY (job_cycle_id) REFERENCES job_cycles(id),
                CONSTRAINT fk_app_job FOREIGN KEY (job_id) REFERENCES jobs(id)
            ){$T}",

            'application_answers' => "CREATE TABLE application_answers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                application_id INT NOT NULL,
                question_id INT NULL,
                question_label_snapshot VARCHAR(300) NOT NULL,
                answer_text TEXT NULL,
                answer_option_ids JSON NULL,
                is_knockout_hit TINYINT(1) NOT NULL DEFAULT 0,
                {$TS},
                KEY idx_ans_app (application_id),
                CONSTRAINT fk_ans_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
            ){$T}",

            'application_files' => "CREATE TABLE application_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                application_id INT NOT NULL,
                stored_name CHAR(32) NOT NULL COMMENT 'random hex; file lives outside public_html',
                original_name VARCHAR(200) NOT NULL,
                mime VARCHAR(60) NOT NULL DEFAULT 'application/pdf',
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                {$TS},
                UNIQUE KEY uq_file_stored (stored_name),
                KEY idx_file_app (application_id),
                CONSTRAINT fk_file_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
            ){$T}",

            'application_status_history' => "CREATE TABLE application_status_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                application_id INT NOT NULL,
                from_status VARCHAR(20) NULL,
                to_status VARCHAR(20) NOT NULL,
                changed_by_user_id INT NULL,
                changed_by_name VARCHAR(100) NOT NULL DEFAULT '',
                changed_at DATETIME NOT NULL COMMENT 'Eastern time',
                note VARCHAR(500) NULL,
                {$TS},
                KEY idx_hist_app (application_id),
                CONSTRAINT fk_hist_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
            ){$T}",

            'application_notes' => "CREATE TABLE application_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                application_id INT NOT NULL,
                user_id INT NULL,
                user_name VARCHAR(100) NOT NULL DEFAULT '',
                body TEXT NOT NULL,
                {$TS},
                KEY idx_note_app (application_id),
                CONSTRAINT fk_note_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
            ){$T}",

            'email_templates' => "CREATE TABLE email_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tpl_key VARCHAR(40) NOT NULL,
                name VARCHAR(100) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                body_html MEDIUMTEXT NOT NULL,
                body_text MEDIUMTEXT NULL COMMENT 'Optional; plain text is made from the HTML when empty',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                {$TS},
                UNIQUE KEY uq_tpl_key (tpl_key)
            ){$T}",

            'email_queue' => "CREATE TABLE email_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                application_id INT NULL,
                template_key VARCHAR(40) NOT NULL,
                to_email VARCHAR(190) NOT NULL,
                cc_email VARCHAR(190) NULL,
                rendered_subject VARCHAR(200) NOT NULL,
                rendered_body MEDIUMTEXT NOT NULL,
                status ENUM('pending','sent','cancelled','failed') NOT NULL DEFAULT 'pending',
                queued_by VARCHAR(100) NOT NULL DEFAULT '',
                approved_by VARCHAR(100) NULL,
                sent_at DATETIME NULL,
                error VARCHAR(500) NULL,
                {$TS},
                KEY idx_queue_status (status),
                KEY idx_queue_app (application_id),
                CONSTRAINT fk_queue_app FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
            ){$T}",

            'email_log' => "CREATE TABLE email_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                application_id INT NULL,
                to_email VARCHAR(190) NOT NULL,
                cc_email VARCHAR(190) NULL,
                template_key VARCHAR(40) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                status ENUM('sent','failed') NOT NULL,
                error VARCHAR(500) NULL,
                sent_at DATETIME NOT NULL COMMENT 'Eastern time',
                {$TS},
                KEY idx_elog_app (application_id),
                KEY idx_elog_sent (sent_at)
            ){$T}",

            'job_alert_subscribers' => "CREATE TABLE job_alert_subscribers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                category_ids JSON NULL COMMENT 'empty = all categories',
                engagement_types JSON NULL COMMENT 'empty = all types',
                confirmed_at DATETIME NULL,
                unsubscribed_at DATETIME NULL,
                ip_hash CHAR(64) NULL,
                last_sent_at DATETIME NULL,
                {$TS},
                UNIQUE KEY uq_alert_email (email)
            ){$T}",

            'user_employment' => "CREATE TABLE user_employment (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                job_id INT NULL,
                application_id INT NULL,
                staff_position VARCHAR(150) NOT NULL,
                engagement_type ENUM('paid','work_trade','internship') NOT NULL DEFAULT 'paid',
                pay_rate DECIMAL(8,2) NULL,
                pay_unit ENUM('hour','week','month','stipend') NULL DEFAULT 'hour',
                work_trade_terms TEXT NULL,
                start_date DATE NOT NULL,
                end_date DATE NULL,
                termination_reason VARCHAR(500) NULL,
                is_minor TINYINT(1) NOT NULL DEFAULT 0,
                dob DATE NULL COMMENT 'Minors only (youth certificate needs age check)',
                youth_cert_number VARCHAR(50) NULL,
                youth_cert_received_at DATETIME NULL,
                created_by VARCHAR(100) NOT NULL DEFAULT '',
                {$TS},
                KEY idx_emp_user (user_id, end_date)
            ){$T}",
        ];

        foreach ($tables as $name => $sql) {
            if ($tableExists($name)) { $say('skip', "Table {$name} already exists."); continue; }
            try { $exec($sql); $say('ok', ($dry ? 'Would create' : 'Created') . " table {$name}."); }
            catch (Throwable $e) { $ok = false; $say('error', "Couldn't create {$name}: " . $e->getMessage()); }
        }
        if (!$ok) return ['ok' => false, 'lines' => $lines];

        // ── 2. users table changes ──────────────────────────────────────────────
        try {
            $uc = $columns('users');

            // role: add 'Staff' if role is an ENUM list (a plain text column needs nothing).
            $roleType = strtolower((string)($uc['role']['Type'] ?? ''));
            if (str_starts_with($roleType, 'enum(')) {
                if (str_contains($roleType, "'staff'")) {
                    $say('skip', "users.role already allows 'Staff'.");
                } else {
                    preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", (string)$uc['role']['Type'], $m);
                    $vals = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
                    $vals[] = 'Staff';
                    $list = implode(',', array_map(fn($v) => $pdo->quote($v), $vals));
                    $null = ($uc['role']['Null'] === 'YES') ? 'NULL' : 'NOT NULL';
                    $def  = $uc['role']['Default'] !== null ? ' DEFAULT ' . $pdo->quote((string)$uc['role']['Default']) : '';
                    $exec("ALTER TABLE users MODIFY role ENUM({$list}) {$null}{$def}");
                    $say('ok', ($dry ? 'Would add' : 'Added') . " 'Staff' to users.role (it's a fixed list).");
                }
            } else {
                $say('skip', "users.role is plain text ({$roleType}), so 'Staff' works without a change.");
            }

            // password: allow empty (NULL) so invited users can exist before choosing one.
            if (($uc['password']['Null'] ?? 'YES') === 'NO') {
                $exec("ALTER TABLE users MODIFY password " . $uc['password']['Type'] . " NULL DEFAULT NULL");
                $say('ok', ($dry ? 'Would allow' : 'Allowed') . ' users.password to be empty for invited staff.');
            } else {
                $say('skip', 'users.password already allows empty.');
            }

            $add = [
                'staff_position'        => "VARCHAR(150) NULL DEFAULT NULL",
                'engagement_type'       => "ENUM('paid','work_trade','internship') NULL DEFAULT NULL",
                'account_status'        => "ENUM('invited','active','disabled') NOT NULL DEFAULT 'active'",
                'source_application_id' => "INT NULL DEFAULT NULL",
            ];
            foreach ($add as $col => $def) {
                if (isset($uc[$col])) { $say('skip', "users.{$col} already exists."); continue; }
                $exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
                $say('ok', ($dry ? 'Would add' : 'Added') . " users.{$col}.");
            }
            foreach (['salary', 'date_hired', 'date_terminated', 'termination_reason'] as $col) {
                if (!isset($uc[$col])) $say('warn', "users.{$col} is missing. It's normally added when you open the admin; open the admin once, then run this again.");
            }

            // email: unique, so the hire step can safely match people by email.
            $idx = $pdo->query("SHOW INDEX FROM users WHERE Column_name = 'email' AND Non_unique = 0")->fetchAll();
            if ($idx) {
                $say('skip', 'users.email is already unique.');
            } else {
                $dups = $pdo->query("SELECT LOWER(TRIM(email)) e, COUNT(*) n FROM users GROUP BY LOWER(TRIM(email)) HAVING n > 1")->fetchAll(PDO::FETCH_ASSOC);
                if ($dups) {
                    $say('warn', 'users.email is NOT unique and ' . count($dups) . ' address(es) appear more than once: '
                        . implode(', ', array_map(fn($d) => $d['e'] . ' (' . $d['n'] . ')', array_slice($dups, 0, 10)))
                        . '. Merge or fix those accounts, then run this again. (Jobs still works; hiring picks the oldest account.)');
                } else {
                    $exec("ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)");
                    $say('ok', ($dry ? 'Would make' : 'Made') . ' users.email unique (no duplicates found).');
                }
            }
        } catch (Throwable $e) {
            $ok = false; $say('error', 'users table change failed: ' . $e->getMessage());
        }

        // ── 3. password_resets: reuse it for "set your password" invite links ───
        try {
            if (!$tableExists('password_resets')) {
                $exec("CREATE TABLE password_resets (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    purpose VARCHAR(20) NOT NULL DEFAULT 'reset',
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_pr_token (token_hash), KEY idx_pr_user (user_id)
                ){$T}");
                $say('ok', ($dry ? 'Would create' : 'Created') . ' table password_resets.');
            } elseif (!isset($columns('password_resets')['purpose'])) {
                $exec("ALTER TABLE password_resets ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT 'reset'");
                $say('ok', ($dry ? 'Would add' : 'Added') . " password_resets.purpose ('reset' or 'invite').");
            } else {
                $say('skip', 'password_resets.purpose already exists.');
            }
        } catch (Throwable $e) {
            $ok = false; $say('error', 'password_resets change failed: ' . $e->getMessage());
        }

        if ($dry) { $say('info', 'Preview only. Nothing was changed.'); return ['ok' => $ok, 'lines' => $lines]; }

        // ── 4. Seed categories ──────────────────────────────────────────────────
        try {
            $ins = $pdo->prepare("INSERT IGNORE INTO job_categories (name, slug, allows_minors, sort_order) VALUES (?, ?, ?, ?)");
            $n = 0;
            foreach ([['Farm', 'farm', 0, 10], ['Petting Zoo', 'petting-zoo', 0, 20], ['Farmstand', 'farmstand', 1, 30], ['Thrift Shop', 'thrift-shop', 1, 40]] as $c) {
                $ins->execute($c); $n += $ins->rowCount();
            }
            $say($n ? 'ok' : 'skip', $n ? "Added {$n} job categories (Farmstand + Thrift Shop allow minors)." : 'Job categories already there.');
        } catch (Throwable $e) { $ok = false; $say('error', 'Category seed failed: ' . $e->getMessage()); }

        // ── 5. Seed email templates (never overwrites edited wording) ──────────
        try {
            require_once __DIR__ . '/default_templates.php';
            $ins = $pdo->prepare("INSERT IGNORE INTO email_templates (tpl_key, name, subject, body_html) VALUES (?, ?, ?, ?)");
            $n = 0;
            foreach (jobs_default_templates() as $key => $t) { $ins->execute([$key, $t['name'], $t['subject'], $t['body']]); $n += $ins->rowCount(); }
            $say($n ? 'ok' : 'skip', $n ? "Added {$n} default email templates." : 'Email templates already there (your edits are kept).');
        } catch (Throwable $e) { $ok = false; $say('error', 'Template seed failed: ' . $e->getMessage()); }

        // ── 6. Access: Jobs screens start OFF for every non-admin role ─────────
        // (A missing permission row means "allowed" in this admin, so write explicit rows.)
        try {
            if ($tableExists('view_permissions')) {
                $ins = $pdo->prepare("INSERT IGNORE INTO view_permissions (role, view_name, can_access) VALUES (?, 'jobs', 0)");
                $n = 0;
                foreach (['moderator', 'Shopkeep', 'Media', 'user', 'Staff'] as $r) { $ins->execute([$r]); $n += $ins->rowCount(); }
                $say($n ? 'ok' : 'skip', $n ? 'Jobs screen set to OFF for moderator, Shopkeep, Media and Staff (turn on in Permissions Matrix).' : 'Jobs screen permissions already set.');
            }
        } catch (Throwable $e) { $say('warn', 'Could not pre-set Jobs permissions: ' . $e->getMessage()); }

        $say($ok ? 'info' : 'error', $ok ? 'Done. Jobs database is ready.' : 'Finished with errors (see above).');
        return ['ok' => $ok, 'lines' => $lines];
    }
}
