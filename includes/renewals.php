<?php
// includes/renewals.php — Renewals: things the farm has to renew, or that run out, on a date (Nov 15, 2026; migration 0069).
//   A renewal  title, category, the next date (renews_on), how often it comes back (repeat_months, 0 = once), how many days
//              ahead to warn (remind_days), who handles it (an Owner/Manager group or one person), cost, provider, account or
//              policy number (never a password), a link to renew, notes. "Renews by itself" turns the reminder into a check.
//   Renewed    moves renews_on on by repeat_months (past today if it was late), records last_renewed_on and closes its task.
//              A one-time item is marked finished instead.
//   Delete     hides the row (deleted_at); Restore brings it back. Nothing is erased.
//   Reminders  bcrf_renewals_sweep() (from the bell sweep, every 15 minutes): when a renewal is within remind_days, or late,
//              it adds a bell notification, emails whoever handles it once, and puts a "Renew: ..." task on their list due
//              on the renewal date. All three are made once per renewal date.
//   Calendar   bcrf_renewals_occurrences() feeds the "Renewals & due dates" layer (includes/calendar.php).
// Who: the 'renewals' view (Owners always; Managers by default). Nothing here is farm-specific.
// Screen: admin/renewals.php. Tests: tests/renewals_test.php.
if (!function_exists('bcrf_is_owner')) require_once __DIR__ . '/roles.php';   // bcrf_is_owner() (Nov 18, 2026)
if (!function_exists('bcrf_renewals_ready')) {

class BcrfRenewalError extends RuntimeException {}

define('BCRF_RENEWAL_CATEGORIES', ['website' => 'Website & domain', 'insurance' => 'Insurance', 'license' => 'License or permit',
                                   'subscription' => 'Subscription', 'account' => 'Account or login', 'vehicle' => 'Vehicle & equipment',
                                   'membership' => 'Membership', 'other' => 'Other']);
define('BCRF_RENEWAL_REPEATS', [0 => 'Only once', 1 => 'Every month', 2 => 'Every 2 months', 3 => 'Every 3 months', 6 => 'Every 6 months',
                                12 => 'Every year', 24 => 'Every 2 years', 36 => 'Every 3 years', 60 => 'Every 5 years']);
define('BCRF_RENEWAL_WHO', ['administrator' => 'Owners', 'moderator' => 'Managers']);

function bcrf_renewals_ready(PDO $pdo): bool {
    static $ok = [];
    $k = spl_object_id($pdo);
    if (isset($ok[$k])) return $ok[$k];
    try { $pdo->query("SELECT 1 FROM renewals LIMIT 0"); return $ok[$k] = true; }
    catch (Throwable $e) { return $ok[$k] = false; }
}

if (!function_exists('bcrf_tz')) require_once __DIR__ . '/farm_helpers.php';   // one farm time zone / today / money (Nov 18, 2026)
function bcrf_renewals_tz(): DateTimeZone { return bcrf_tz(); }

/** Today on the farm, Y-m-d. Tests can pin it with $GLOBALS['bcrf_renewals_today']. */
function bcrf_renewals_today(): string {
    if (!empty($GLOBALS['bcrf_renewals_today'])) return (string)$GLOBALS['bcrf_renewals_today'];
    return (new DateTimeImmutable('now', bcrf_renewals_tz()))->format('Y-m-d');
}

/** Can this role see and change renewals? (Owners always; others need the 'renewals' view.) */
function bcrf_renewals_can(?string $role = null): bool {
    $role = $role ?? (string)($_SESSION['user_role'] ?? '');
    if (bcrf_is_owner($role)) return true;
    if ($role === '' || $role === 'user') return false;
    return function_exists('hasViewAccess') && hasViewAccess($role, 'renewals');
}

/** $date plus $months, keeping the day of the month where it can (Jan 31 + 1 month = Feb 28/29). */
function bcrf_renewal_add_months(string $date, int $months): string {
    $d = new DateTimeImmutable($date);
    $day = (int)$d->format('j');
    $first = $d->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
    return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, (int)$first->format('t')))->format('Y-m-d');
}

/** The first date after $after (or the next one after $date when $after is null) for something due on $date that comes back every $months. Keeps the day of the month. */
function bcrf_renewal_next(string $date, int $months, ?string $after = null): ?string {
    if ($months <= 0) return null;
    $after = $after ?? $date;
    for ($k = 1; $k < 1200; $k++) {
        $d = bcrf_renewal_add_months($date, $months * $k);
        if ($d > $after) return $d;
    }
    return null;
}

function bcrf_renewal_get(PDO $pdo, int $id): ?array {
    if ($id <= 0 || !bcrf_renewals_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT r.*, v.name AS vendor_name, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS assignee_name
                         FROM renewals r LEFT JOIN vendors v ON v.id = r.vendor_id LEFT JOIN users u ON u.id = r.assignee_user_id WHERE r.id = ?");
    try { $st->execute([$id]); } catch (Throwable $e) {   // no vendors table yet
        $st = $pdo->prepare("SELECT r.*, NULL AS vendor_name, NULL AS assignee_name FROM renewals r WHERE r.id = ?"); $st->execute([$id]);
    }
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * The list. $show: upcoming (default; not finished, not deleted, soonest first) | finished | deleted | all. $q searches
 * title, provider, account and notes.
 */
function bcrf_renewal_list(PDO $pdo, string $show = 'upcoming', string $q = ''): array {
    if (!bcrf_renewals_ready($pdo)) return [];
    $w = []; $a = [];
    if ($show === 'deleted') $w[] = 'r.deleted_at IS NOT NULL';
    elseif ($show === 'finished') { $w[] = 'r.deleted_at IS NULL'; $w[] = 'r.finished_at IS NOT NULL'; }
    elseif ($show === 'all') $w[] = 'r.deleted_at IS NULL';
    else { $w[] = 'r.deleted_at IS NULL'; $w[] = 'r.finished_at IS NULL'; }
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $w[] = '(r.title LIKE ? OR r.provider LIKE ? OR r.account_ref LIKE ? OR r.notes LIKE ?)';
        array_push($a, $like, $like, $like, $like);
    }
    $order = $show === 'upcoming' ? 'r.renews_on, r.title' : ($show === 'deleted' ? 'r.deleted_at DESC' : 'r.renews_on DESC, r.title');
    $st = $pdo->prepare("SELECT r.* FROM renewals r WHERE " . implode(' AND ', $w) . " ORDER BY $order LIMIT 500");
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Days from today until $date (negative = late). */
function bcrf_renewal_days_left(string $date, ?string $today = null): int {
    $today = $today ?? bcrf_renewals_today();
    $d = (new DateTimeImmutable($today))->diff(new DateTimeImmutable($date));
    return $d->invert ? -(int)$d->days : (int)$d->days;
}

/** "In 12 days", "Today", "3 days late" */
function bcrf_renewal_when(string $date, ?string $today = null): string {
    $n = bcrf_renewal_days_left($date, $today);
    if ($n === 0) return 'Today';
    if ($n === 1) return 'Tomorrow';
    if ($n > 1) return $n < 60 ? "In $n days" : 'In ' . round($n / 30.4) . ' months';
    return (-$n) . ' day' . ($n === -1 ? '' : 's') . ' late';
}

/** Is it inside its warning window (or late)? */
function bcrf_renewal_due_soon(array $r, ?string $today = null): bool {
    return bcrf_renewal_days_left((string)$r['renews_on'], $today) <= (int)$r['remind_days'];
}

/** "$1,234.50" from cents ('' for null). */
function bcrf_renewal_money($cents): string {
    return $cents === null || $cents === '' ? '' : bcrf_money((int)$cents);
}

/** "Every year" / "Only once" */
function bcrf_renewal_repeat_label(int $m): string {
    return BCRF_RENEWAL_REPEATS[$m] ?? ('Every ' . $m . ' months');
}

/** Who handles it, in words. */
function bcrf_renewal_who(array $r): string {
    if (!empty($r['assignee_user_id'])) return trim((string)($r['assignee_name'] ?? '')) ?: 'Person #' . (int)$r['assignee_user_id'];
    return BCRF_RENEWAL_WHO[(string)($r['assignee_role'] ?? '')] ?? 'Owners';
}

/** Form value for who: "role:administrator" | "user:12" */
function bcrf_renewal_who_value(array $r): string {
    if (!empty($r['assignee_user_id'])) return 'user:' . (int)$r['assignee_user_id'];
    return 'role:' . ((string)($r['assignee_role'] ?? '') ?: 'administrator');
}

/** Owners and Managers who could handle a renewal: [[id, name, role]]. */
function bcrf_renewal_people(PDO $pdo): array {
    try {
        return $pdo->query("SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) AS name, role FROM users
                            WHERE role IN ('administrator', 'moderator') AND COALESCE(account_status, 'active') = 'active' ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Validate form fields. Returns the columns to save. Throws BcrfRenewalError with a plain message. */
function bcrf_renewal_clean(PDO $pdo, array $in): array {
    $str = fn(string $k, int $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
    $title = $str('title', 150);
    if ($title === '') throw new BcrfRenewalError('Give it a name, like "Domain: farm website" or "Liability insurance".');
    $cat = (string)($in['category'] ?? 'other');
    if (!isset(BCRF_RENEWAL_CATEGORIES[$cat])) $cat = 'other';
    $date = trim((string)($in['renews_on'] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new BcrfRenewalError('Pick the date it renews or runs out.');
    if ($m[1] < 2000 || $m[1] > 2100) throw new BcrfRenewalError('That date looks wrong. Check the year.');
    $rep = (int)($in['repeat_months'] ?? 12);
    if (!isset(BCRF_RENEWAL_REPEATS[$rep])) throw new BcrfRenewalError('Pick how often it comes back.');
    $remind = (int)($in['remind_days'] ?? 30);
    if ($remind < 0 || $remind > 365) throw new BcrfRenewalError('Remind me between 0 and 365 days before.');
    $cost = trim(str_replace([',', '$', ' '], '', (string)($in['cost'] ?? '')));
    $cents = null;
    if ($cost !== '') {
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $cost)) throw new BcrfRenewalError('Cost looks like 129.99 (or leave it empty).');
        $cents = (int)round(((float)$cost) * 100);
    }
    $link = $str('link_url', 255);
    if ($link !== '') {
        if (!preg_match('#^https?://#i', $link)) $link = 'https://' . $link;
        if (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://[^\s/]+\.[^\s/]+#i', $link)) throw new BcrfRenewalError('Check the renewal link (like registrar.com/renew).');
    }
    $who = (string)($in['who'] ?? 'role:administrator');
    $uidWho = null; $roleWho = 'administrator';
    if (str_starts_with($who, 'user:')) {
        $uidWho = (int)substr($who, 5);
        $ok = false;
        foreach (bcrf_renewal_people($pdo) as $p) if ((int)$p['id'] === $uidWho) $ok = true;
        if (!$ok) throw new BcrfRenewalError('Pick an Owner or Manager from the list.');
        $roleWho = null;
    } elseif (str_starts_with($who, 'role:') && isset(BCRF_RENEWAL_WHO[substr($who, 5)])) {
        $roleWho = substr($who, 5);
    }
    $vendor = (int)($in['vendor_id'] ?? 0) ?: null;
    if ($vendor) {
        try { $st = $pdo->prepare("SELECT COUNT(*) FROM vendors WHERE id = ?"); $st->execute([$vendor]); if (!(int)$st->fetchColumn()) $vendor = null; }
        catch (Throwable $e) { $vendor = null; }
    }
    return [
        'title' => $title, 'category' => $cat, 'renews_on' => $date, 'repeat_months' => $rep, 'remind_days' => $remind,
        'auto_renews' => !empty($in['auto_renews']) ? 1 : 0, 'cost_cents' => $cents,
        'provider' => $str('provider', 120) ?: null, 'account_ref' => $str('account_ref', 120) ?: null, 'link_url' => $link ?: null,
        'vendor_id' => $vendor, 'assignee_user_id' => $uidWho, 'assignee_role' => $roleWho, 'notes' => $str('notes', 5000) ?: null,
    ];
}

function bcrf_renewal_audit(PDO $pdo, string $action, int $id, string $label, ?array $before = null, ?array $after = null): void {
    if (!function_exists('bcrf_audit_event') || !function_exists('bcrf_audit_actor')) return;
    [$t, $aid, $name, $ip] = bcrf_audit_actor();
    $strip = fn($r) => $r === null ? null : array_diff_key($r, array_flip(['vendor_name', 'assignee_name', 'task_id', 'created_at', 'created_by', 'updated_at', 'updated_by']));
    bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => $action,
        'object_type' => 'renewals', 'object_id' => (string)$id, 'object_label' => mb_substr($label, 0, 250), 'before' => $strip($before), 'after' => $strip($after)]);
}

/** Add ($id null) or change a renewal. Returns its id. A new date closes the old date's open task. */
function bcrf_renewal_save(PDO $pdo, ?int $id, array $in, ?int $uid): int {
    $v = bcrf_renewal_clean($pdo, $in);
    $old = $id ? bcrf_renewal_get($pdo, $id) : null;
    if ($id && (!$old || $old['deleted_at'])) throw new BcrfRenewalError('That renewal is gone. It may have been deleted.');
    $cols = array_keys($v);
    if ($old) {
        $pdo->prepare('UPDATE renewals SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?')
            ->execute([...array_values($v), $uid, $id]);
        if ($old['renews_on'] !== $v['renews_on']) bcrf_renewal_close_task($pdo, $old, 'cancelled');
        bcrf_renewal_audit($pdo, 'update', $id, $v['title'], $old, $v);
    } else {
        $pdo->prepare('INSERT INTO renewals (' . implode(', ', $cols) . ', created_at, created_by) VALUES (' . str_repeat('?, ', count($cols)) . 'UTC_TIMESTAMP(), ?)')
            ->execute([...array_values($v), $uid]);
        $id = (int)$pdo->lastInsertId();
        bcrf_renewal_audit($pdo, 'create', $id, $v['title'], null, $v);
    }
    return $id;
}

/** Close the renewal's open task (done when renewed, cancelled when the date moved or it was deleted). */
function bcrf_renewal_close_task(PDO $pdo, array $r, string $status = 'done'): void {
    if (empty($r['task_id'])) return;
    try {
        $pdo->prepare("UPDATE tasks SET status = ?, completed_at = IF(? = 'done', UTC_TIMESTAMP(), completed_at), updated_at = UTC_TIMESTAMP()
                       WHERE id = ? AND status IN ('open', 'in_progress')")->execute([$status, $status, (int)$r['task_id']]);
    } catch (Throwable $e) {
        try { $pdo->prepare("UPDATE tasks SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('open', 'in_progress')")->execute([$status, (int)$r['task_id']]); }
        catch (Throwable $e2) { /* no tasks table */ }
    }
    $pdo->prepare("UPDATE renewals SET task_id = NULL WHERE id = ?")->execute([(int)$r['id']]);
}

/**
 * Renewed: a repeating item moves on to its next date (past today when it was late); a one-time item is finished.
 * $on: the day it was renewed (default today). Returns the new date, or null when finished.
 */
function bcrf_renewal_mark_renewed(PDO $pdo, int $id, ?int $uid, ?string $on = null): ?string {
    $r = bcrf_renewal_get($pdo, $id);
    if (!$r || $r['deleted_at']) throw new BcrfRenewalError('That renewal is gone. It may have been deleted.');
    if ($r['finished_at']) throw new BcrfRenewalError('That one is already finished.');
    $on = $on ?? bcrf_renewals_today();
    $months = (int)$r['repeat_months'];
    bcrf_renewal_close_task($pdo, $r, 'done');
    if ($months <= 0) {
        $pdo->prepare("UPDATE renewals SET last_renewed_on = ?, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute([$on, $uid, $id]);
        bcrf_renewal_audit($pdo, 'update', $id, $r['title'] . ' (done)', ['renews_on' => $r['renews_on']], ['last_renewed_on' => $on, 'finished' => 'yes']);
        return null;
    }
    // Count on from the date it was due (not from today), so it keeps its day: Apr 29 stays Apr 29.
    $next = bcrf_renewal_next((string)$r['renews_on'], $months, max((string)$r['renews_on'], $on));
    $pdo->prepare("UPDATE renewals SET renews_on = ?, last_renewed_on = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute([$next, $on, $uid, $id]);
    bcrf_renewal_audit($pdo, 'update', $id, $r['title'] . ' (renewed)', ['renews_on' => $r['renews_on']], ['renews_on' => $next, 'last_renewed_on' => $on]);
    return $next;
}

/** Bring a finished one-time item back (e.g. marked by mistake). */
function bcrf_renewal_reopen(PDO $pdo, int $id, ?int $uid): void {
    $r = bcrf_renewal_get($pdo, $id);
    if (!$r || $r['deleted_at']) throw new BcrfRenewalError('That renewal is gone. It may have been deleted.');
    $pdo->prepare("UPDATE renewals SET finished_at = NULL, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute([$uid, $id]);
    bcrf_renewal_audit($pdo, 'update', $id, $r['title'] . ' (back on the list)');
}

/** Delete (hide) or restore. Deleting closes its open task. */
function bcrf_renewal_delete(PDO $pdo, int $id, bool $delete, ?int $uid): void {
    $r = bcrf_renewal_get($pdo, $id);
    if (!$r) throw new BcrfRenewalError('That renewal no longer exists.');
    if ($delete) {
        if ($r['deleted_at']) return;
        bcrf_renewal_close_task($pdo, $r, 'cancelled');
        $pdo->prepare("UPDATE renewals SET deleted_at = UTC_TIMESTAMP(), deleted_by = ? WHERE id = ?")->execute([$uid, $id]);
        bcrf_renewal_audit($pdo, 'delete', $id, $r['title']);
    } else {
        if (!$r['deleted_at']) return;
        $pdo->prepare("UPDATE renewals SET deleted_at = NULL, deleted_by = NULL, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute([$uid, $id]);
        bcrf_renewal_audit($pdo, 'update', $id, $r['title'] . ' (restored)');
    }
}

/**
 * Every date a renewal falls on between $from and $to (inclusive), for the calendar: its next date and, for a repeating
 * one, the dates after it. [[renewal row, date], ...] sorted by date. Deleted and finished ones are left out.
 */
function bcrf_renewals_occurrences(PDO $pdo, string $from, string $to): array {
    if (!bcrf_renewals_ready($pdo) || $to < $from) return [];
    $st = $pdo->prepare("SELECT * FROM renewals WHERE deleted_at IS NULL AND finished_at IS NULL AND renews_on <= ? ORDER BY renews_on, title LIMIT 500");
    $st->execute([$to]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = (string)$r['renews_on']; $m = (int)$r['repeat_months'];
        for ($i = 0; $d <= $to && $i < 400; $i++) {
            if ($d >= $from) $out[] = [$r, $d];
            if ($m <= 0) break;
            $d = bcrf_renewal_add_months((string)$r['renews_on'], $m * ($i + 1));   // from the first date, so the day of the month holds
        }
    }
    usort($out, fn($a, $b) => [$a[1], $a[0]['title']] <=> [$b[1], $b[0]['title']]);
    return $out;
}

// ── Reminders: bell, email and a task ────────────────────────────────────────────────────────────

/** Email addresses of whoever handles it (the person, or every active member of the group). */
function bcrf_renewal_recipients(PDO $pdo, array $r): array {
    try {
        if (!empty($r['assignee_user_id'])) {
            $st = $pdo->prepare("SELECT email FROM users WHERE id = ? AND COALESCE(account_status, 'active') = 'active' AND email <> ''");
            $st->execute([(int)$r['assignee_user_id']]);
        } else {
            $st = $pdo->prepare("SELECT email FROM users WHERE role = ? AND COALESCE(account_status, 'active') = 'active' AND email <> ''");
            $st->execute([(string)($r['assignee_role'] ?: 'administrator')]);
        }
        return array_values(array_unique(array_filter($st->fetchAll(PDO::FETCH_COLUMN), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    } catch (Throwable $e) { return []; }
}

/** Send the reminder email. Returns how many went (or were queued). */
function bcrf_renewal_email(PDO $pdo, array $r, bool $late, ?string $today = null): int {
    $to = bcrf_renewal_recipients($pdo, $r);
    if (!$to) return 0;
    if (!function_exists('bcf_send_mail')) { $f = dirname(__DIR__) . '/admin/includes/email_template.php'; if (is_file($f)) require_once $f; }
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $date = date('l, F j, Y', strtotime((string)$r['renews_on']));
    $verb = !empty($r['auto_renews']) ? 'renews by itself' : 'needs renewing';
    $subject = ($late ? 'Late: ' : 'Coming up: ') . $r['title'] . ($late ? ' was due ' : ' ') . date('M j', strtotime((string)$r['renews_on']));
    $base = function_exists('bcrf_farm_url') ? rtrim(bcrf_farm_url(), '/') : '';
    $url = $base . '/admin/renewals.php?id=' . (int)$r['id'];
    $rows = [];
    foreach (['Provider' => $r['provider'] ?? '', 'Account or policy' => $r['account_ref'] ?? '', 'Cost' => bcrf_renewal_money($r['cost_cents'] ?? null), 'Repeats' => bcrf_renewal_repeat_label((int)$r['repeat_months'])] as $k => $v) {
        if ((string)$v !== '') $rows[] = '<tr><td style="padding:2px 12px 2px 0;color:#57534e">' . $e($k) . '</td><td style="padding:2px 0">' . $e($v) . '</td></tr>';
    }
    $html = '<p style="margin:0 0 12px"><b>' . $e($r['title']) . '</b> ' . $e($verb) . ($late ? ' and was due on ' : ' on ') . '<b>' . $e($date) . '</b>.</p>'
          . ($rows ? '<table style="border-collapse:collapse;margin:0 0 12px;font-size:14px">' . implode('', $rows) . '</table>' : '')
          . (!empty($r['auto_renews']) ? '<p style="margin:0 0 12px">It should renew on its own. Check that it did and that the card on file still works.</p>' : '')
          . (!empty($r['link_url']) ? '<p style="margin:0 0 12px"><a href="' . $e($r['link_url']) . '">Renew it here</a></p>' : '')
          . '<p style="margin:0">When it is done, tap <b>Renewed</b> on <a href="' . $e($url) . '">its page in Farm OS</a> so the next date is set. A task for it is on your list.</p>';
    $sent = 0;
    foreach ($to as $addr) {
        $ok = false;
        try { $ok = function_exists('bcf_send_mail') && bcf_send_mail($addr, $subject, $html, ['subtitle' => 'Renewal', 'unsubscribe' => false]); }
        catch (Throwable $ex) { error_log('[renewals] mail: ' . $ex->getMessage()); }
        if (!$ok && function_exists('bcrf_queue_push')) $ok = bcrf_queue_push('email.send', ['to' => $addr, 'subject' => $subject, 'html' => $html, 'opts' => ['subtitle' => 'Renewal', 'unsubscribe' => false]]) > 0;
        if ($ok) $sent++;
    }
    return $sent;
}

/** Put a "Renew: ..." task on the handler's list, due on the renewal date. Returns the task id (or null without Tasks). */
function bcrf_renewal_make_task(PDO $pdo, array $r): ?int {
    if (!function_exists('bcrf_task_create') && is_file(__DIR__ . '/tasks.php')) require_once __DIR__ . '/tasks.php';
    if (!function_exists('bcrf_task_create') || !bcrf_tasks_ready($pdo)) return null;
    $who = !empty($r['assignee_user_id']) ? 'user:' . (int)$r['assignee_user_id'] : 'role:' . ((string)($r['assignee_role'] ?? '') ?: 'administrator');
    $desc = trim(implode("\n", array_filter([
        !empty($r['auto_renews']) ? 'It renews by itself: check that it did and that the card on file works.' : '',
        !empty($r['provider']) ? 'Provider: ' . $r['provider'] : '',
        !empty($r['account_ref']) ? 'Account or policy: ' . $r['account_ref'] : '',
        !empty($r['link_url']) ? 'Renew at: ' . $r['link_url'] : '',
        'When it is done, tap Renewed on /admin/renewals.php?id=' . (int)$r['id'] . ' so the next date is set.',
    ])));
    try {
        $tid = bcrf_task_create($pdo, ['title' => (!empty($r['auto_renews']) ? 'Check it renewed: ' : 'Renew: ') . $r['title'],
            'description' => $desc, 'priority' => 'high', 'due_date' => (string)$r['renews_on'], 'who' => $who,
            'related_type' => 'renewal', 'related_id' => (string)(int)$r['id']]);
    } catch (Throwable $e) {
        error_log('[renewals] task: ' . $e->getMessage());
        return null;
    }
    $pdo->prepare("UPDATE renewals SET task_id = ? WHERE id = ?")->execute([$tid, (int)$r['id']]);
    return $tid;
}

/**
 * Every 15 minutes (from bcrf_notify_sweep): renewals inside their warning window, or late, get a bell notification and
 * an email (once per renewal date, and once more when it goes late) and a task. Returns notifications added.
 */
function bcrf_renewals_sweep(PDO $pdo, ?string $today = null): int {
    if (!bcrf_renewals_ready($pdo) || !function_exists('bcrf_notify')) return 0;
    $today = $today ?? bcrf_renewals_today();
    $added = 0;
    $st = $pdo->prepare("SELECT r.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS assignee_name FROM renewals r LEFT JOIN users u ON u.id = r.assignee_user_id
                         WHERE r.deleted_at IS NULL AND r.finished_at IS NULL AND r.renews_on <= DATE_ADD(?, INTERVAL r.remind_days DAY) ORDER BY r.renews_on LIMIT 200");
    $st->execute([$today]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $late = (string)$r['renews_on'] < $today;
        $n = bcrf_notify($pdo, [
            'kind' => 'renewal_due', 'dedupe' => ($late ? 'renewal_late:' : 'renewal_due:') . $r['id'] . ':' . $r['renews_on'],
            'link' => '/admin/renewals.php?id=' . (int)$r['id'],
            'user_id' => !empty($r['assignee_user_id']) ? (int)$r['assignee_user_id'] : null,
            'role' => empty($r['assignee_user_id']) && ($r['assignee_role'] ?? '') === 'moderator' ? 'moderator' : null,
            'title' => $r['title'] . ($late ? ' is past its renewal date' : (!empty($r['auto_renews']) ? ' renews soon (check it did)' : ' needs renewing soon')),
            'body' => ($late ? 'Was due ' : 'Due ') . date('D M j, Y', strtotime((string)$r['renews_on'])) . (!empty($r['provider']) ? ' · ' . $r['provider'] : '') . '. Tap Renewed when it is done.',
        ]);
        if ($n) { $added++; bcrf_renewal_email($pdo, $r, $late, $today); }
        // One open task per renewal date.
        $open = false;
        if (!empty($r['task_id'])) {
            try { $t = $pdo->prepare("SELECT status, due_date FROM tasks WHERE id = ?"); $t->execute([(int)$r['task_id']]); $t = $t->fetch(PDO::FETCH_ASSOC);
                  $open = $t && $t['due_date'] === $r['renews_on'] && in_array($t['status'], ['open', 'in_progress'], true); }
            catch (Throwable $e) { $open = true; }
        }
        if (!$open) {
            $exists = false;
            try { $t = $pdo->prepare("SELECT id FROM tasks WHERE related_type = 'renewal' AND related_id = ? AND due_date = ? LIMIT 1"); $t->execute([(string)(int)$r['id'], $r['renews_on']]); $exists = (bool)$t->fetchColumn(); }
            catch (Throwable $e) { $exists = true; }
            if (!$exists) bcrf_renewal_make_task($pdo, $r);
        }
    }
    return $added;
}

}
