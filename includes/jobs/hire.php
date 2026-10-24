<?php
// includes/jobs/hire.php
// Hire flow + staff records (Jobs pack P6).
//  - jobs_hire_defaults / jobs_hire_validate / jobs_hire_save: the confirm screen
//    turns an application into a staff account + user_employment stint, in one
//    transaction, then emails the "hired" welcome with a set-password link.
//  - Set-password links reuse password_resets (purpose 'invite', 7 days, hash only).
//  - Staff records: list, one person, resend link, youth certificate, end
//    employment, login on/off. users.* mirrors the current stint; history lives
//    in user_employment.
// Used by admin/handlers/jobs_hire.php, admin/views/jobs/hire.php and staff.php.

require_once __DIR__ . '/pipeline.php';

if (!function_exists('jobs_hire_save')) {

    if (!defined('JOBS_INVITE_DAYS')) define('JOBS_INVITE_DAYS', 7);
    if (!defined('JOBS_SITE_URL'))    define('JOBS_SITE_URL', 'https://batcaveroadfarm.com');

    /** Roles that can sign in to the staff console (same list as check_admin()). */
    function jobs_staff_roles(): array
    {
        return [
            'Staff'         => 'Staff (Time Clock only)',
            'Shopkeep'      => 'Shopkeep',
            'Media'         => 'Media',
            'moderator'     => 'Moderator',
            'administrator' => 'Administrator (full access)',
        ];
    }

    function jobs_find_user_by_email(PDO $pdo, string $email): ?array
    {
        if ($email === '') return null;
        $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) ORDER BY id LIMIT 1');
        $st->execute([trim($email)]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function jobs_user_find(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Open (current) stints for a user, newest first. */
    function jobs_open_stints(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare('SELECT * FROM user_employment WHERE user_id = ? AND end_date IS NULL ORDER BY start_date DESC, id DESC');
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function jobs_stints(PDO $pdo, int $userId): array
    {
        $st = $pdo->prepare('SELECT e.*, j.title AS job_title FROM user_employment e LEFT JOIN jobs j ON j.id = e.job_id
                              WHERE e.user_id = ? ORDER BY (e.end_date IS NULL) DESC, e.start_date DESC, e.id DESC');
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    function jobs_stint_find(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT * FROM user_employment WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Pre-filled values for the confirm screen. */
    function jobs_hire_defaults(PDO $pdo, array $app): array
    {
        $job   = jobs_find($pdo, (int)$app['job_id']) ?: [];
        $today = jobs_today();
        $start = (string)($app['earliest_start'] ?? '');
        if ($start === '' || $start < $today) $start = $today;
        $user  = jobs_find_user_by_email($pdo, (string)$app['email']);
        $role  = $user && isset(jobs_staff_roles()[$user['role']]) ? $user['role'] : 'Staff';
        $eng   = (string)($job['engagement_type'] ?? 'paid');
        return [
            'first_name'       => (string)$app['first_name'],
            'last_name'        => (string)$app['last_name'],
            'email'            => (string)$app['email'],
            'phone'            => (string)($app['phone'] ?? ''),
            'staff_position'   => (string)($job['title'] ?? $app['job_title'] ?? ''),
            'engagement_type'  => $eng,
            'start_date'       => $start,
            'pay_rate'         => $eng !== 'work_trade' && isset($job['pay_min']) && $job['pay_min'] !== null ? number_format((float)$job['pay_min'], 2, '.', '') : '',
            'pay_unit'         => (string)($job['pay_unit'] ?? 'hour'),
            'work_trade_terms' => (string)($job['work_trade_terms'] ?? ''),
            'role'             => $role,
            'dob'              => '',
            'youth_cert_number'=> '',
            'youth_cert_received' => false,
            'send_email'       => true,
            '_user'            => $user,
            '_job'             => $job,
        ];
    }

    /** Check posted confirm-screen values. Returns [clean array, errors[]]. */
    function jobs_hire_validate(array $in, bool $minor): array
    {
        $s   = fn($k, $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
        $err = [];
        $d = [
            'first_name'       => $s('first_name', 100),
            'last_name'        => $s('last_name', 100),
            'email'            => strtolower($s('email', 190)),
            'phone'            => $s('phone', 30),
            'staff_position'   => $s('staff_position', 150),
            'engagement_type'  => (string)($in['engagement_type'] ?? ''),
            'start_date'       => (string)($in['start_date'] ?? ''),
            'pay_rate'         => null,
            'pay_unit'         => (string)($in['pay_unit'] ?? 'hour'),
            'work_trade_terms' => $s('work_trade_terms', 4000),
            'role'             => (string)($in['role'] ?? 'Staff'),
            'dob'              => null,
            'youth_cert_number'=> $s('youth_cert_number', 50),
            'youth_cert_received' => !empty($in['youth_cert_received']),
            'send_email'       => !empty($in['send_email']),
        ];
        if ($d['first_name'] === '')  $err[] = 'First name is required.';
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Enter a valid email (it becomes their login).';
        if ($d['staff_position'] === '') $err[] = 'Position is required.';
        if (!isset(jobs_engagement_labels()[$d['engagement_type']])) $err[] = 'Pick paid, work trade or internship.';
        if (!isset(jobs_pay_unit_labels()[$d['pay_unit']])) $d['pay_unit'] = 'hour';
        if (!isset(jobs_staff_roles()[$d['role']])) $err[] = 'Pick what they can open in the admin.';

        $dt = DateTime::createFromFormat('!Y-m-d', $d['start_date']);
        if (!$dt || $dt->format('Y-m-d') !== $d['start_date']) $err[] = 'Pick a start date.';
        elseif (abs((new DateTime(jobs_today()))->diff($dt)->days) > 400) $err[] = 'The start date should be within about a year of today.';

        $rate = trim((string)($in['pay_rate'] ?? ''));
        if ($rate !== '') {
            $rate = str_replace(['$', ','], '', $rate);
            if (!is_numeric($rate) || (float)$rate < 0 || (float)$rate > 99999) $err[] = 'Pay should be a number, like 12.50.';
            else $d['pay_rate'] = round((float)$rate, 2);
        }
        if ($d['engagement_type'] === 'paid') {
            if ($d['pay_rate'] === null) $err[] = 'Add their pay.';
            elseif ($d['pay_unit'] === 'hour' && $d['pay_rate'] < JOBS_MIN_WAGE) $err[] = 'Hourly pay must be at least $' . number_format(JOBS_MIN_WAGE, 2) . ' (minimum wage).';
        }
        if ($d['engagement_type'] === 'work_trade' && $d['work_trade_terms'] === '') $err[] = 'Write down the work-trade terms (what they get for their hours).';

        if ($minor) {
            $dob = trim((string)($in['dob'] ?? ''));
            if ($dob !== '') {
                $b = DateTime::createFromFormat('!Y-m-d', $dob);
                if (!$b || $b->format('Y-m-d') !== $dob) $err[] = 'Date of birth isn\'t a real date.';
                else {
                    $age = $b->diff(new DateTime(jobs_today()))->y;
                    if ($age < 14) $err[] = 'Under 14 can\'t be hired (NC youth rules).';
                    elseif ($age > 17) $err[] = 'That date of birth makes them 18 or older; leave it blank if unsure.';
                    else $d['dob'] = $dob;
                }
            }
        } else {
            $d['youth_cert_number'] = ''; $d['youth_cert_received'] = false;
        }
        return [$d, $err];
    }

    /** New one-time set-password link for a user (older unused invite links stop working). Returns the raw token. */
    function jobs_invite_issue(PDO $pdo, int $userId): string
    {
        $pdo->prepare("UPDATE password_resets SET used_at = ? WHERE user_id = ? AND purpose = 'invite' AND used_at IS NULL")
            ->execute([jobs_now(), $userId]);
        $raw = bin2hex(random_bytes(32));
        // Expiry uses the database clock, the same clock welcome.php checks against.
        $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, purpose, expires_at) VALUES (?, ?, 'invite', DATE_ADD(NOW(), INTERVAL " . (int)JOBS_INVITE_DAYS . " DAY))")
            ->execute([$userId, hash('sha256', $raw)]);
        return $raw;
    }

    function jobs_invite_link(string $raw): string
    {
        return JOBS_SITE_URL . '/account/welcome.php?token=' . $raw;
    }

    /** Latest invite row for a user (or null): created_at, expires_at, used_at. */
    function jobs_invite_last(PDO $pdo, int $userId): ?array
    {
        $st = $pdo->prepare("SELECT created_at, expires_at, used_at, expires_at > NOW() AS is_live FROM password_resets WHERE user_id = ? AND purpose = 'invite' ORDER BY id DESC LIMIT 1");
        $st->execute([$userId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function jobs_fmt_date(?string $d): string
    {
        return $d ? date('l, F j, Y', strtotime($d)) : '';
    }

    /**
     * Send the "hired" welcome (with a fresh set-password link) for a stint.
     * Guardian is CC'd for under-18s (same rule as every applicant email).
     * Returns [bool sent, string message].
     */
    function jobs_send_welcome(PDO $pdo, array $user, array $stint): array
    {
        if (!jobs_template($pdo, 'hired')) return [false, 'The "Hired" email template is turned off, so no welcome email went out.'];
        $raw = jobs_invite_issue($pdo, (int)$user['id']);
        $app = null;
        if (!empty($stint['application_id'])) $app = jobs_app_find($pdo, (int)$stint['application_id']);
        if (!$app) {
            $app = ['id' => null, 'first_name' => $user['first_name'], 'last_name' => $user['last_name'],
                    'email' => $user['email'], 'age_bracket' => (int)$stint['is_minor'] ? '16_17' : '18_plus', 'guardian_email' => ''];
        }
        $app['email'] = $user['email'];                      // the login address, even if it changed on the hire screen
        $app['first_name'] = $user['first_name'] ?: $app['first_name'];
        $job = ['title' => $stint['staff_position'], 'category_name' => $app['category_name'] ?? ''];
        $r = jobs_render_template($pdo, 'hired', array_merge(jobs_merge_vars($app, $job), [
            'start_date'        => jobs_fmt_date($stint['start_date']),
            'set_password_link' => jobs_invite_link($raw),
        ]));
        if (!$r) return [false, 'The "Hired" email template is turned off, so no welcome email went out.'];
        $ok = jobs_mail_send($pdo, (string)$user['email'], $r[0], $r[1], [
            'cc' => jobs_cc_for($app), 'application_id' => $app['id'] ? (int)$app['id'] : null, 'template_key' => 'hired',
        ]);
        return [$ok, $ok ? 'Welcome email sent with a set-password link (works for ' . JOBS_INVITE_DAYS . ' days).'
                         : 'The welcome email did NOT send. Use "Send a new set-password link" on their staff page.'];
    }

    /**
     * Save the hire. One transaction: user (create or upgrade), stint, application
     * status + history, round hire count (auto-close when full). Then the email.
     * Returns [bool ok, string message, int userId].
     */
    function jobs_hire_save(PDO $pdo, int $appId, array $d): array
    {
        [$uid, $uname] = jobs_actor();
        $now = jobs_now();
        $closedRound = false;

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $st->execute([$appId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row)                    throw new RuntimeException('That application was not found.');
            if ($row['status'] === 'hired') throw new RuntimeException('Already hired. Nothing was changed.');
            if ((int)$row['is_spam'])     throw new RuntimeException('This application is marked as spam. Tap "Not spam" first.');
            $app   = jobs_app_find($pdo, $appId);
            $minor = $row['age_bracket'] !== '18_plus';

            // ── 1. User: upgrade the match, or create an invited account ──
            $user  = jobs_find_user_by_email($pdo, $d['email']);
            $isNew = !$user;
            $payHourly = $d['engagement_type'] === 'paid' && $d['pay_unit'] === 'hour' ? $d['pay_rate'] : null;
            if ($isNew) {
                $pdo->prepare("INSERT INTO users (first_name, last_name, email, phone_number, password, role, account_status,
                                                  staff_position, engagement_type, source_application_id, salary, last_salary_change, date_hired)
                               VALUES (?, ?, ?, ?, NULL, ?, 'invited', ?, ?, ?, ?, ?, ?)")
                    ->execute([$d['first_name'], $d['last_name'], $d['email'], $d['phone'] ?: null, $d['role'],
                               $d['staff_position'], $d['engagement_type'], $appId, $payHourly, $payHourly !== null ? $d['start_date'] : null, $d['start_date']]);
                $userId = (int)$pdo->lastInsertId();
            } else {
                $userId = (int)$user['id'];
                // Never lower an administrator from this screen; otherwise use the role picked.
                $role   = $user['role'] === 'administrator' ? 'administrator' : $d['role'];
                $status = $user['account_status'] === 'disabled' ? (empty($user['password']) ? 'invited' : 'active') : $user['account_status'];
                // last_salary_change only moves when the rate actually changes (assigned before salary on purpose).
                // Not paid hourly (work trade, stipend…): no hourly rate on file for this job.
                $salSet = $payHourly !== null ? ', last_salary_change = IF(salary <=> ?, last_salary_change, ?), salary = ?' : ', salary = NULL';
                $par    = [$d['first_name'] ?: $user['first_name'], $d['last_name'] ?: $user['last_name'], $d['phone'] ?: $user['phone_number'],
                           $role, $status, $d['staff_position'], $d['engagement_type'], $appId, $d['start_date']];
                if ($payHourly !== null) array_push($par, $payHourly, $d['start_date'], $payHourly);
                $par[] = $userId;
                // Rehire: date_terminated / termination_reason clear; the old values stay on the earlier stint.
                $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, phone_number = ?, role = ?, account_status = ?,
                                      staff_position = ?, engagement_type = ?, source_application_id = ?, date_hired = ?,
                                      date_terminated = NULL, termination_reason = NULL{$salSet}
                                WHERE id = ?")->execute($par);
            }

            // ── 2. Stint ──
            $pdo->prepare("INSERT INTO user_employment (user_id, job_id, application_id, staff_position, engagement_type, pay_rate, pay_unit,
                                                        work_trade_terms, start_date, is_minor, dob, youth_cert_number, youth_cert_received_at, created_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$userId, (int)$row['job_id'], $appId, $d['staff_position'], $d['engagement_type'], $d['pay_rate'], $d['pay_unit'],
                           $d['work_trade_terms'] !== '' ? $d['work_trade_terms'] : null, $d['start_date'], $minor ? 1 : 0, $minor ? $d['dob'] : null,
                           $minor && $d['youth_cert_number'] !== '' ? $d['youth_cert_number'] : null,
                           $minor && $d['youth_cert_received'] ? $now : null, mb_substr($uname, 0, 100)]);
            $stintId = (int)$pdo->lastInsertId();

            // ── 3. Application ──
            $pdo->prepare("UPDATE applications SET status = 'hired', user_id = ?, decided_at = ? WHERE id = ?")->execute([$userId, $now, $appId]);
            jobs_add_history($pdo, $appId, $row['status'], 'hired',
                'Hired as ' . $d['staff_position'] . ', starting ' . date('M j, Y', strtotime($d['start_date'])), $uid, $uname);
            // Anything still held for them (e.g. an old "not selected") must not go out now.
            $pdo->prepare("UPDATE email_queue SET status = 'cancelled', approved_by = ? WHERE application_id = ? AND status IN ('pending','failed')")
                ->execute([mb_substr($uname, 0, 100), $appId]);

            // ── 4. Round count (+ auto-close when every opening is filled) ──
            $cy = $pdo->prepare('SELECT * FROM job_cycles WHERE id = ? FOR UPDATE');
            $cy->execute([(int)$row['job_cycle_id']]);
            if ($c = $cy->fetch(PDO::FETCH_ASSOC)) {
                $hires = (int)$c['hires_count'] + 1;
                $pdo->prepare('UPDATE job_cycles SET hires_count = ? WHERE id = ?')->execute([$hires, (int)$c['id']]);
                if ((int)$c['auto_close_when_filled'] && $c['closed_at'] === null && $hires >= (int)$c['openings']) {
                    $pdo->prepare('UPDATE job_cycles SET closed_at = ? WHERE id = ? AND closed_at IS NULL')->execute([$now, (int)$c['id']]);
                    $pdo->prepare('UPDATE jobs SET is_active = 0 WHERE id = ?')->execute([(int)$row['job_id']]);
                    $closedRound = true;
                }
            }
            $pdo->commit();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return [false, $e->getMessage(), 0];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $name = trim($d['first_name'] . ' ' . $d['last_name']);
        if (function_exists('erp_audit')) {
            erp_audit($pdo, $isNew ? 'create' : 'update', 'users', $userId, ($isNew ? 'Staff account created by hire: ' : 'Hired existing account: ') . $name);
            erp_audit($pdo, 'create', 'user_employment', $stintId, $name . ' — ' . $d['staff_position'] . ' from ' . $d['start_date']);
            erp_audit($pdo, 'update', 'applications', $appId, $name . ': ' . (jobs_status_labels()[$row['status']] ?? $row['status']) . ' → Hired');
        }

        $msg = $name . ' is hired' . ($isNew ? ' and has a new staff account.' : '. Their existing account was updated.');
        if ($d['send_email']) {
            [, $m] = jobs_send_welcome($pdo, jobs_user_find($pdo, $userId), jobs_stint_find($pdo, $stintId));
            $msg .= ' ' . $m;
        } else {
            $msg .= ' No email sent. Send the set-password link from their staff page when ready.';
        }
        if ($minor && !$d['youth_cert_received']) $msg .= ' Youth employment certificate still needed.';
        if ($closedRound) $msg .= ' Every opening is filled, so this hiring round closed. Use "Wrap up" on the job to send the rest a "not selected" email.';
        return [true, $msg, $userId];
    }

    // ── Staff records ───────────────────────────────────────────────────────

    /**
     * People with at least one stint. $show: current | past | all. Each row has the
     * user, their current (or latest) stint, open-stint count and invite state.
     */
    function jobs_staff_list(PDO $pdo, string $show = 'current', string $q = ''): array
    {
        $where = [];
        $par   = [];
        if ($show === 'current') $where[] = 'EXISTS (SELECT 1 FROM user_employment o WHERE o.user_id = u.id AND o.end_date IS NULL)';
        if ($show === 'past')    $where[] = 'NOT EXISTS (SELECT 1 FROM user_employment o WHERE o.user_id = u.id AND o.end_date IS NULL)';
        if ($q !== '') {
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            array_push($par, $like, $like, $like, $like);
        }
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone_number, u.role, u.account_status, u.password IS NOT NULL AS has_password,
                       e.id AS stint_id, e.staff_position, e.engagement_type, e.start_date, e.end_date, e.is_minor, e.youth_cert_received_at,
                       (SELECT COUNT(*) FROM user_employment o WHERE o.user_id = u.id AND o.end_date IS NULL) AS open_stints,
                       (SELECT COUNT(*) FROM user_employment o WHERE o.user_id = u.id AND o.end_date IS NULL AND o.is_minor = 1 AND o.youth_cert_received_at IS NULL) AS certs_needed
                  FROM users u
                  JOIN user_employment e ON e.id = (SELECT x.id FROM user_employment x WHERE x.user_id = u.id
                                                     ORDER BY (x.end_date IS NULL) DESC, x.start_date DESC, x.id DESC LIMIT 1)"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY (e.end_date IS NULL) DESC, u.first_name, u.last_name';
        $st = $pdo->prepare($sql);
        $st->execute($par);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Youth certificates still missing on current stints (sidebar badge). */
    function jobs_certs_needed_count(PDO $pdo): int
    {
        return (int)$pdo->query('SELECT COUNT(*) FROM user_employment WHERE end_date IS NULL AND is_minor = 1 AND youth_cert_received_at IS NULL')->fetchColumn();
    }

    /** Copy the current open stint onto users.* (or the ended values if none are open). */
    function jobs_staff_mirror(PDO $pdo, int $userId): void
    {
        $open = jobs_open_stints($pdo, $userId);
        if ($open) {
            $c = $open[0];
            $pdo->prepare('UPDATE users SET staff_position = ?, engagement_type = ?, date_hired = ?, date_terminated = NULL, termination_reason = NULL WHERE id = ?')
                ->execute([$c['staff_position'], $c['engagement_type'], $c['start_date'], $userId]);
            return;
        }
        $st = $pdo->prepare('SELECT * FROM user_employment WHERE user_id = ? ORDER BY end_date DESC, id DESC LIMIT 1');
        $st->execute([$userId]);
        if ($l = $st->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare('UPDATE users SET date_terminated = ?, termination_reason = ? WHERE id = ?')
                ->execute([$l['end_date'], $l['termination_reason'], $userId]);
        }
    }

    /** End one stint. $loginOff turns their login off (only when no other job is still open). Returns [ok, msg]. */
    function jobs_employment_end(PDO $pdo, int $stintId, string $endDate, string $reason, bool $loginOff): array
    {
        $s = jobs_stint_find($pdo, $stintId);
        if (!$s)              return [false, 'That job record was not found.'];
        if ($s['end_date'])   return [false, 'That job already ended on ' . date('M j, Y', strtotime($s['end_date'])) . '.'];
        $dt = DateTime::createFromFormat('!Y-m-d', $endDate);
        if (!$dt || $dt->format('Y-m-d') !== $endDate) return [false, 'Pick the last day.'];
        if ($endDate < $s['start_date']) return [false, 'The last day can\'t be before they started (' . date('M j, Y', strtotime($s['start_date'])) . ').'];
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') return [false, 'Add a short reason (for example "Season ended").'];
        $user = jobs_user_find($pdo, (int)$s['user_id']);
        if (!$user) return [false, 'Their account was not found.'];
        [$uid] = jobs_actor();

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE user_employment SET end_date = ?, termination_reason = ? WHERE id = ? AND end_date IS NULL')
                ->execute([$endDate, $reason, $stintId]);
            jobs_staff_mirror($pdo, (int)$user['id']);
            $stillOpen = count(jobs_open_stints($pdo, (int)$user['id']));
            $off = $loginOff && !$stillOpen && (int)$user['id'] !== (int)$uid;
            if ($off) {
                $pdo->prepare("UPDATE users SET account_status = 'disabled' WHERE id = ?")->execute([(int)$user['id']]);
                $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([jobs_now(), (int)$user['id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $name = trim($user['first_name'] . ' ' . $user['last_name']);
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'user_employment', $stintId, $name . ' — ' . $s['staff_position'] . ' ended ' . $endDate . ': ' . $reason);
        $msg = $name . '\'s job as ' . $s['staff_position'] . ' ended ' . date('M j, Y', strtotime($endDate)) . '.';
        if ($stillOpen)   $msg .= ' They still have another current job, so their login stays on.';
        elseif ($off)     $msg .= ' Their login is turned off.';
        elseif ($loginOff && (int)$user['id'] === (int)$uid) $msg .= ' You can\'t turn off your own login, so it stays on.';
        else              $msg .= ' Their login is still on.';
        return [true, $msg];
    }

    /** Turn a staff login on or off. Returns [ok, msg]. */
    function jobs_staff_set_login(PDO $pdo, int $userId, bool $on): array
    {
        $user = jobs_user_find($pdo, $userId);
        if (!$user) return [false, 'That person was not found.'];
        [$uid] = jobs_actor();
        if (!$on && $userId === (int)$uid) return [false, 'You can\'t turn off your own login.'];
        $name = trim($user['first_name'] . ' ' . $user['last_name']);
        if ($on) {
            $status = empty($user['password']) ? 'invited' : 'active';
            $pdo->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$status, $userId]);
        } else {
            $pdo->prepare("UPDATE users SET account_status = 'disabled' WHERE id = ?")->execute([$userId]);
            $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')->execute([jobs_now(), $userId]);
        }
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'users', $userId, $name . ': login turned ' . ($on ? 'on' : 'off'));
        return [true, $on ? $name . '\'s login is on.' . (empty($user['password']) ? ' They still need to set a password; send them a link.' : '')
                          : $name . '\'s login is off. They\'re signed out the next time they tap anything.'];
    }

    /** Record the NC youth employment certificate on a stint. Returns [ok, msg]. */
    function jobs_youth_cert_save(PDO $pdo, int $stintId, string $number, bool $received): array
    {
        $s = jobs_stint_find($pdo, $stintId);
        if (!$s || !(int)$s['is_minor']) return [false, 'That job record isn\'t for an under-18 hire.'];
        $number = mb_substr(trim($number), 0, 50);
        $at = $received ? ($s['youth_cert_received_at'] ?: jobs_now()) : null;
        $pdo->prepare('UPDATE user_employment SET youth_cert_number = ?, youth_cert_received_at = ? WHERE id = ?')
            ->execute([$number !== '' ? $number : null, $at, $stintId]);
        if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'user_employment', $stintId, 'Youth certificate ' . ($received ? 'received' : 'not received yet') . ($number !== '' ? ' (#' . $number . ')' : ''));
        return [true, $received ? 'Youth employment certificate marked as received.' : 'Saved. Still waiting on the certificate.'];
    }
}
