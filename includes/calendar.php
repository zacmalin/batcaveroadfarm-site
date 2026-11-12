<?php
// includes/calendar.php — Farm OS master calendar and phone calendar feeds (Phase 3, Nov 12, 2026; 12 › Master calendar).
// An aggregated view over the source records (D-15); nothing is copied or stored. Layers:
//   parties     private events (event_inquiries) on their date, at their start time when set; unconfirmed ones say so.
//               People working a party (party_staff) always see it, even without the Parties layer.
//   bookings    group bookings (not day tickets), pending or confirmed
//   events      published public events (one-off, multi-day, weekly and yearly repeats)
//   tasks       open tasks with a due date (everyone's for a manager; otherwise mine, my role's and anyone's)
//   orders      purchase orders expected to arrive (deliveries)
//   interviews  job interviews
//   closures    booking blackout days and weather days
// Who sees what: each layer needs the same permission as its own page (bcrf_cal_layers_for). Times are farm-local.
//   bcrf_cal_items($pdo, $from, $to, $layers, $viewer)   items between two dates (inclusive), sorted
//   bcrf_cal_feed_*                                      the private ICS feed (one per staff member, secret link)
if (!function_exists('bcrf_cal_items')) {

if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';
require_once __DIR__ . '/parties.php';

define('BCRF_CAL_LAYERS', [
    'parties'    => ['label' => 'Parties',        'perm' => ['view', 'event_inquiries']],
    'bookings'   => ['label' => 'Group bookings', 'perm' => ['view', 'bookings']],
    'events'     => ['label' => 'Public events',  'perm' => ['view', 'calendar']],
    'tasks'      => ['label' => 'Tasks',          'perm' => ['view', 'tasks']],
    'orders'     => ['label' => 'Deliveries',     'perm' => ['table', 'inventory']],
    'interviews' => ['label' => 'Interviews',     'perm' => ['view', 'jobs']],
    'closures'   => ['label' => 'Closed days',    'perm' => ['view', 'calendar']],
]);

function bcrf_cal_tz(): DateTimeZone { return new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York'); }
function bcrf_cal_today(): string { return (new DateTimeImmutable('now', bcrf_cal_tz()))->format('Y-m-d'); }

/** The layers a role may see (same permissions as each layer's own page). */
function bcrf_cal_layers_for(string $role): array {
    $out = [];
    foreach (BCRF_CAL_LAYERS as $k => $l) {
        if ($role === 'administrator') { $out[] = $k; continue; }
        if ($role === '' || $role === 'user') continue;
        [$type, $name] = $l['perm'];
        $ok = $type === 'view' ? (function_exists('hasViewAccess') && hasViewAccess($role, $name))
                               : (function_exists('hasTableAccess') && hasTableAccess($role, $name, 'view'));
        if ($ok) $out[] = $k;
    }
    return $out;
}

function bcrf_cal_valid_date(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

/** One calendar item. $start/$end: 'Y-m-d' (all day) or 'Y-m-d H:i:s' (farm time). */
function bcrf_cal_item(string $layer, string $uid, string $title, string $start, ?string $end, string $url, string $note = '', bool $tentative = false): array {
    return ['layer' => $layer, 'uid' => $uid, 'title' => $title, 'start' => $start, 'end' => $end, 'all_day' => strlen($start) === 10,
            'date' => substr($start, 0, 10), 'url' => $url, 'note' => $note, 'tentative' => $tentative];
}

/**
 * Items from $from to $to (inclusive, Y-m-d), for the given layers.
 * $viewer: ['user_id' => int, 'role' => string, 'manager' => bool] — decides whose tasks show.
 */
function bcrf_cal_items(PDO $pdo, string $from, string $to, array $layers, array $viewer): array {
    if (!bcrf_cal_valid_date($from) || !bcrf_cal_valid_date($to) || $to < $from) return [];
    $items = [];
    $on = array_flip($layers);
    $admin = '/admin/admin.php';

    // Parties: all of them with the Parties layer; otherwise the ones this person is working (party_staff, Nov 12).
    $mine = [];
    if (!isset($on['parties']) && !empty($viewer['user_id'])) {
        try { $st = $pdo->prepare("SELECT event_inquiry_id FROM party_staff WHERE user_id = ?"); $st->execute([(int)$viewer['user_id']]); $mine = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); }
        catch (Throwable $e) {}
    }
    if (isset($on['parties']) || $mine) {
        try {
            $st = $pdo->prepare("SELECT * FROM event_inquiries WHERE preferred_date BETWEEN ? AND ? AND status IN ('new', 'contacted', 'quoted', 'confirmed')"
                              . (isset($on['parties']) ? '' : ' AND id IN (' . implode(',', $mine) . ')') . " ORDER BY preferred_date, id");
            $st->execute([$from, $to]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $sum = function_exists('bcrf_party_summaries') ? bcrf_party_summaries($pdo, array_column($rows, 'id')) : [];
            $len = bcrf_cal_setting_int($pdo, 'party_length_minutes', 120);
            foreach ($rows as $r) {
                $kind = function_exists('bcrf_quote_subject') && defined('BCRF_QUOTE_PARTY_KINDS') ? (BCRF_QUOTE_PARTY_KINDS[$r['event_type']] ?? 'Party') : 'Party';
                $guests = (int)$r['adult_count'] + (int)$r['child_count'];
                $mins = function_exists('bcrf_party_length') ? bcrf_party_length($pdo, $r) : $len;
                $start = $r['preferred_date'] . (!empty($r['start_time']) ? ' ' . $r['start_time'] : '');
                $end = !empty($r['start_time']) ? (new DateTimeImmutable($start))->modify("+{$mins} minutes")->format('Y-m-d H:i:s') : null;
                $s = $sum[(int)$r['id']] ?? null;
                $note = $guests . ' guests · ' . $r['status'] . ($s && $s['package'] !== '' ? ' · ' . $s['package'] : '') . ($s && $s['staff'] ? ' · Staff: ' . implode(', ', $s['staff']) : '');
                $items[] = bcrf_cal_item('parties', 'party-' . $r['id'], $kind . ': ' . $r['customer_name'], $start, $end,
                    '/admin/party.php?id=' . (int)$r['id'], $note, $r['status'] !== 'confirmed');
            }
        } catch (Throwable $e) {}
    }

    if (isset($on['bookings'])) {
        try {
            $st = $pdo->prepare("SELECT b.*, t.label AS type_label FROM bookings b JOIN booking_types t ON t.id = b.type_id
                                 WHERE b.booking_date BETWEEN ? AND ? AND b.date_flexible = 0 AND b.status IN ('pending', 'confirmed') AND t.type_key <> 'day_admission'
                                 ORDER BY b.booking_date, b.start_time, b.id");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $start = $r['booking_date'] . (!empty($r['start_time']) ? ' ' . $r['start_time'] : '');
                $end = !empty($r['start_time']) && !empty($r['end_time']) ? $r['booking_date'] . ' ' . $r['end_time'] : null;
                $items[] = bcrf_cal_item('bookings', 'booking-' . $r['id'], $r['type_label'] . ': ' . $r['customer_name'], $start, $end,
                    $admin . '?view=bookings&day=' . rawurlencode($r['booking_date']), (int)$r['party_size'] . ' guests · ' . $r['status'], $r['status'] !== 'confirmed');
            }
        } catch (Throwable $e) {}
    }

    if (isset($on['events'])) {
        try {
            $st = $pdo->prepare("SELECT * FROM events WHERE status = 'published' AND DATE(start_datetime) <= ?
                                 AND ((recurrence IN ('none', 'range') AND DATE(COALESCE(end_datetime, start_datetime)) >= ?)
                                   OR (recurrence IN ('weekly', 'annual') AND (recur_until IS NULL OR recur_until >= ?)))");
            $st->execute([$to, $from, $from]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) foreach (bcrf_cal_event_days($ev, $from, $to) as $d) {
                $allDay = !empty($ev['all_day']);
                $sTime = substr((string)$ev['start_datetime'], 11, 8);
                $eTime = $ev['end_datetime'] ? substr((string)$ev['end_datetime'], 11, 8) : '';
                $start = $allDay ? $d : $d . ' ' . $sTime;
                $end = $allDay || $eTime === '' || $eTime <= $sTime ? null : $d . ' ' . $eTime;
                $items[] = bcrf_cal_item('events', 'event-' . $ev['id'] . '-' . $d, (string)$ev['title'], $start, $end,
                    $admin . '?view=events', (string)($ev['location_note'] ?? ''));
            }
        } catch (Throwable $e) {}
    }

    if (isset($on['tasks'])) {
        try {
            $where = "status IN ('open', 'in_progress') AND due_date BETWEEN ? AND ?";
            $args = [$from, $to];
            if (empty($viewer['manager'])) {
                $where .= " AND (assignee_user_id = ? OR (assignee_user_id IS NULL AND (assignee_role IS NULL OR assignee_role = ?)))";
                $args[] = (int)($viewer['user_id'] ?? 0); $args[] = (string)($viewer['role'] ?? '');
            }
            $st = $pdo->prepare("SELECT id, title, due_date, due_time, priority, assignee_user_id, assignee_role FROM tasks WHERE $where ORDER BY due_date, due_time, id LIMIT 500");
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $start = $t['due_date'] . (!empty($t['due_time']) ? ' ' . $t['due_time'] : '');
                $who = !empty($t['assignee_user_id']) ? bcrf_cal_person($pdo, (int)$t['assignee_user_id']) : (!empty($t['assignee_role']) ? (string)$t['assignee_role'] : 'Anyone');
                $items[] = bcrf_cal_item('tasks', 'task-' . $t['id'], (string)$t['title'], $start, null, '/admin/tasks.php?tab=my#task-' . $t['id'],
                    $who . ($t['priority'] === 'high' ? ' · high priority' : ''));
            }
        } catch (Throwable $e) {}
    }

    if (isset($on['orders'])) {
        try {
            $st = $pdo->prepare("SELECT po.id, po.po_ref, po.expected_date, v.name AS vendor FROM purchase_orders po LEFT JOIN vendors v ON v.id = po.vendor_id
                                 WHERE po.expected_date BETWEEN ? AND ? AND po.status IN ('ordered', 'partial') ORDER BY po.expected_date, po.id");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = bcrf_cal_item('orders', 'po-' . $r['id'], 'Delivery: ' . ($r['vendor'] ?: 'order ' . $r['po_ref']), (string)$r['expected_date'], null,
                    '/admin/purchase_orders.php?id=' . (int)$r['id'], $r['po_ref'] ? 'Order ' . $r['po_ref'] : '');
            }
        } catch (Throwable $e) {}
    }

    if (isset($on['interviews'])) {
        try {
            $st = $pdo->prepare("SELECT a.id, a.first_name, a.last_name, a.interview_at, a.interview_location, j.title AS job FROM applications a LEFT JOIN jobs j ON j.id = a.job_id
                                 WHERE a.interview_at >= ? AND a.interview_at < DATE_ADD(?, INTERVAL 1 DAY) AND a.archived_at IS NULL AND a.anonymized_at IS NULL ORDER BY a.interview_at");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $end = (new DateTimeImmutable((string)$r['interview_at']))->modify('+30 minutes')->format('Y-m-d H:i:s');
                $items[] = bcrf_cal_item('interviews', 'interview-' . $r['id'], 'Interview: ' . trim($r['first_name'] . ' ' . $r['last_name']), (string)$r['interview_at'], $end,
                    $admin . '?view=jobs', trim(($r['job'] ?? '') . ($r['interview_location'] ? ' · ' . $r['interview_location'] : ''), ' ·'));
            }
        } catch (Throwable $e) {}
    }

    if (isset($on['closures'])) {
        try {
            $st = $pdo->prepare("SELECT d_date, reason FROM booking_blackouts WHERE d_date BETWEEN ? AND ? ORDER BY d_date");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $items[] = bcrf_cal_item('closures', 'closed-' . $r['d_date'], 'Closed to bookings' . ($r['reason'] ? ': ' . $r['reason'] : ''), (string)$r['d_date'], null, $admin . '?view=bookings');
            }
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("SELECT business_date FROM weather_days WHERE business_date BETWEEN ? AND ?");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
                $items[] = bcrf_cal_item('closures', 'weather-' . $d, 'Called off for weather', (string)$d, null, '/admin/weather.php?date=' . rawurlencode((string)$d));
            }
        } catch (Throwable $e) {}
    }

    usort($items, fn($a, $b) => [$a['date'], $a['all_day'] ? 0 : 1, $a['start'], $a['title']] <=> [$b['date'], $b['all_day'] ? 0 : 1, $b['start'], $b['title']]);
    return $items;
}

/** The days a public event falls on between $from and $to. */
function bcrf_cal_event_days(array $ev, string $from, string $to): array {
    $first = substr((string)$ev['start_datetime'], 0, 10);
    if (!bcrf_cal_valid_date($first)) return [];
    $rec = (string)($ev['recurrence'] ?? 'none');
    $until = $rec === 'none' ? $first : ($rec === 'range' ? substr((string)($ev['end_datetime'] ?: $ev['start_datetime']), 0, 10) : ((string)($ev['recur_until'] ?? '') ?: $to));
    $lo = max($from, $first); $hi = min($to, $until);
    if ($hi < $lo) return [];
    $dows = array_map('intval', array_filter(array_map('trim', explode(',', (string)($ev['recur_days'] ?? ''))), 'strlen'));
    if ($rec === 'weekly' && !$dows) $dows = [(int)(new DateTimeImmutable($first))->format('w')];
    $out = [];
    for ($d = new DateTimeImmutable($lo), $end = new DateTimeImmutable($hi); $d <= $end && count($out) < 400; $d = $d->modify('+1 day')) {
        $ymd = $d->format('Y-m-d');
        if ($rec === 'weekly' && !in_array((int)$d->format('w'), $dows, true)) continue;
        if ($rec === 'annual' && $d->format('m-d') !== substr($first, 5, 5)) continue;
        $out[] = $ymd;
    }
    return $out;
}

function bcrf_cal_setting_int(PDO $pdo, string $key, int $default): int {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v !== false && is_numeric($v) && (int)$v > 0 ? (int)$v : $default;
    } catch (Throwable $e) { return $default; }
}

function bcrf_cal_person(PDO $pdo, int $id): string {
    static $cache = [];
    if (!isset($cache[$id])) {
        try { $st = $pdo->prepare("SELECT first_name FROM users WHERE id = ?"); $st->execute([$id]); $cache[$id] = (string)($st->fetchColumn() ?: 'Someone'); }
        catch (Throwable $e) { $cache[$id] = 'Someone'; }
    }
    return $cache[$id];
}

/** Opening hours for a date ('10:00 AM–5:00 PM'), or '' when the farm is closed that day. */
function bcrf_cal_hours(PDO $pdo, string $date): string {
    if (!function_exists('bcrf_tasks_week')) return '';
    $w = bcrf_tasks_week($pdo)[(int)(new DateTimeImmutable($date))->format('w')] ?? null;
    if (!$w) return '';
    $f = fn($t) => (new DateTimeImmutable('2000-01-01 ' . $t))->format('g:i A');
    return $f($w[0]) . '–' . $f($w[1]);
}

// ── Phone calendar feed (ICS) ───────────────────────────────────────────────────────────────────

function bcrf_cal_feed_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM calendar_feeds LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}

function bcrf_cal_feed_get(PDO $pdo, int $userId): ?array {
    $st = $pdo->prepare("SELECT * FROM calendar_feeds WHERE user_id = ?");
    $st->execute([$userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Make (or replace) a user's feed. Returns the new secret; the old link stops working. */
function bcrf_cal_feed_reset(PDO $pdo, int $userId, ?bool $withEvents = null): string {
    $token = bin2hex(random_bytes(24));
    $old = bcrf_cal_feed_get($pdo, $userId);
    $with = $withEvents ?? ($old ? (bool)$old['with_events'] : true);
    $pdo->prepare("INSERT INTO calendar_feeds (user_id, token_hash, with_events, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), with_events = VALUES(with_events), created_at = VALUES(created_at), last_used_at = NULL")
        ->execute([$userId, hash('sha256', $token), $with ? 1 : 0]);
    return $token;
}

function bcrf_cal_feed_set_events(PDO $pdo, int $userId, bool $on): void {
    $pdo->prepare("UPDATE calendar_feeds SET with_events = ? WHERE user_id = ?")->execute([$on ? 1 : 0, $userId]);
}

function bcrf_cal_feed_delete(PDO $pdo, int $userId): void {
    $pdo->prepare("DELETE FROM calendar_feeds WHERE user_id = ?")->execute([$userId]);
}

/** The feed's address. On the admin subdomain once the farm uses it. */
function bcrf_cal_feed_url(string $token): string {
    $base = bcrf_farm_url();
    if (is_file(dirname(__DIR__) . '/admin/use_subdomain.txt')) {
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        if ($host !== '') return 'https://admin.' . preg_replace('/^www\./', '', $host) . '/calendar_feed.php?t=' . $token;
    }
    return $base . '/admin/calendar_feed.php?t=' . $token;
}

/** The staff member a secret belongs to (active staff only), with their feed row. */
function bcrf_cal_feed_owner(PDO $pdo, string $token): ?array {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token));
    if (strlen($token) !== 48) return null;
    $st = $pdo->prepare("SELECT f.*, u.role, u.first_name, u.account_status FROM calendar_feeds f JOIN users u ON u.id = f.user_id WHERE f.token_hash = ?");
    $st->execute([hash('sha256', $token)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || ($r['account_status'] ?? 'active') !== 'active') return null;
    if (!in_array((string)$r['role'], ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'], true)) return null;
    return $r;
}

/** The ICS text for a feed owner: their tasks and the parties they work, plus all parties and group bookings when they chose them, and closed days. */
function bcrf_cal_feed_ics(PDO $pdo, array $owner, ?string $today = null): string {
    $today = $today ?? bcrf_cal_today();
    $from = (new DateTimeImmutable($today))->modify('-14 days')->format('Y-m-d');
    $to = (new DateTimeImmutable($today))->modify('+120 days')->format('Y-m-d');
    $allowed = bcrf_cal_layers_for((string)$owner['role']);
    $want = ['tasks', 'closures'];
    if (!empty($owner['with_events'])) $want = array_merge($want, ['parties', 'bookings']);
    $layers = array_values(array_intersect($want, $allowed));
    // In the feed, tasks are the person's own (and their role's), even for a manager.
    $items = bcrf_cal_items($pdo, $from, $to, $layers, ['user_id' => (int)$owner['user_id'], 'role' => (string)$owner['role'], 'manager' => false]);
    $tz = bcrf_cal_tz();
    $utc = new DateTimeZone('UTC');
    $host = parse_url(bcrf_farm_url(), PHP_URL_HOST) ?: 'farm';
    $esc = fn($s) => str_replace(["\\", "\r", "\n", ',', ';'], ['\\\\', '', '\\n', '\\,', '\\;'], (string)$s);
    $base = rtrim(bcrf_farm_url(), '/');
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Farm OS//Calendar//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
              'X-WR-CALNAME:' . $esc(bcrf_farm_name() . ' (' . $owner['first_name'] . ')'), 'X-WR-TIMEZONE:' . $tz->getName(),
              'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];
    $stamp = gmdate('Ymd\THis\Z');
    foreach ($items as $it) {
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $it['uid'] . '@' . $host;
        $lines[] = 'DTSTAMP:' . $stamp;
        if ($it['all_day']) {
            $d = new DateTimeImmutable($it['start']);
            $lines[] = 'DTSTART;VALUE=DATE:' . $d->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . $d->modify('+1 day')->format('Ymd');
        } else {
            $s = new DateTimeImmutable($it['start'], $tz);
            $e = $it['end'] ? new DateTimeImmutable($it['end'], $tz) : $s->modify('+30 minutes');
            $lines[] = 'DTSTART:' . $s->setTimezone($utc)->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $e->setTimezone($utc)->format('Ymd\THis\Z');
        }
        $lines[] = 'SUMMARY:' . $esc(($it['tentative'] ? '(Not confirmed) ' : '') . $it['title']);
        if ($it['note'] !== '') $lines[] = 'DESCRIPTION:' . $esc($it['note']);
        if ($it['tentative']) $lines[] = 'STATUS:TENTATIVE';
        $lines[] = 'URL:' . $base . $it['url'];
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    // Fold lines at 75 octets (RFC 5545).
    $out = '';
    foreach ($lines as $l) {
        while (strlen($l) > 75) {
            $cut = 75;
            while ($cut > 1 && (ord($l[$cut]) & 0xC0) === 0x80) $cut--;   // don't split a UTF-8 character
            $out .= substr($l, 0, $cut) . "\r\n";
            $l = ' ' . substr($l, $cut);
        }
        $out .= $l . "\r\n";
    }
    return $out;
}

}
