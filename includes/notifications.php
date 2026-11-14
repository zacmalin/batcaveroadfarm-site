<?php
// includes/notifications.php — the admin bell (Farm OS Phase 3 "in-app notifications", kept simple; Nov 13, 2026; migration 0063).
// One list of things staff should see, shown on admin/notifications.php and as a count on the 🔔 in the sidebar and phone menu.
// Email notifications and the dashboard Action Center stay as they are; the bell just collects these in one place:
//   party_request     a new party request (event_inquiries, status new)            → managers
//   booking_request   a new group/school booking request (bookings, status pending)  → managers
//   online_payment    a customer paid a quote online (event_payments, Stripe)        → managers
//   deposit_overdue   a sent quote's deposit is past its due date and not covered    → managers
//   quote_no_reply    a quote went out BCRF_QUOTE_NUDGE_DAYS ago, nothing paid, the party/booking still waiting
//                     on an answer (Nov 14, 2026) → managers, plus a "Follow up" task for Managers due today.
//                     Re-sending the quote starts the clock again (the dedupe key carries sent_count).
//   reminder_sent     a deposit/balance reminder or pass renewal email went out      → managers (includes/reminders.php)
//   task_late         one of your tasks is late (tasks.late_notified_at)             → that person
// Rows are made by bcrf_notify() (directly, or by bcrf_notify_sweep(), which admin/cron/reminders.php runs every 15 minutes)
// and kept 60 days. dedupe_key makes each thing one notification however often the sweep runs.
// Who sees a row: user_id = you; or no user_id and role = your role; or neither set and you're an Owner or Manager.
// Nothing here is farm-specific: links are admin paths and wording comes from the records.
if (!function_exists('bcrf_notify')) {

define('BCRF_NOTIFY_MANAGERS', ['administrator', 'moderator']);
if (!defined('BCRF_QUOTE_NUDGE_DAYS')) define('BCRF_QUOTE_NUDGE_DAYS', 3);   // days a sent quote can sit unanswered before a nudge

function bcrf_notifications_ready(PDO $pdo): bool {
    static $ready = [];
    $k = spl_object_id($pdo);
    if (isset($ready[$k])) return $ready[$k];
    try { $pdo->query("SELECT 1 FROM notifications LIMIT 0"); $pdo->query("SELECT 1 FROM notification_reads LIMIT 0"); return $ready[$k] = true; }
    catch (Throwable $e) { return $ready[$k] = false; }
}

/**
 * Add a notification. $n: kind, title, dedupe (required); body, link, user_id, role (optional).
 * Returns the new id, or 0 when it already existed (same dedupe key) or the tables aren't there yet. Never throws.
 */
function bcrf_notify(PDO $pdo, array $n): int {
    if (!bcrf_notifications_ready($pdo)) return 0;
    $title = mb_substr(trim((string)($n['title'] ?? '')), 0, 200);
    $dedupe = mb_substr(trim((string)($n['dedupe'] ?? '')), 0, 100);
    if ($title === '' || $dedupe === '') return 0;
    try {
        $st = $pdo->prepare("INSERT IGNORE INTO notifications (user_id, role, kind, title, body, link, dedupe_key, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        $st->execute([isset($n['user_id']) ? (int)$n['user_id'] : null, isset($n['role']) ? mb_substr((string)$n['role'], 0, 40) : null,
                      mb_substr((string)($n['kind'] ?? 'note'), 0, 40), $title,
                      isset($n['body']) && $n['body'] !== '' ? mb_substr((string)$n['body'], 0, 500) : null,
                      isset($n['link']) && str_starts_with((string)$n['link'], '/') ? mb_substr((string)$n['link'], 0, 255) : null, $dedupe]);
        return $st->rowCount() === 1 ? (int)$pdo->lastInsertId() : 0;
    } catch (Throwable $e) {
        error_log('[notifications] ' . $e->getMessage());
        return 0;
    }
}

/** SQL condition (and its parameters) for "rows this person can see". */
function bcrf_notify_visible(int $uid, string $role): array {
    $mgr = in_array($role, BCRF_NOTIFY_MANAGERS, true) ? 1 : 0;
    return ["(n.user_id = ? OR (n.user_id IS NULL AND (n.role = ? OR (n.role IS NULL AND ? = 1))))", [$uid, $role, $mgr]];
}

/** How many unread notifications this person has (0 before migration 0063). */
function bcrf_notifications_unread(PDO $pdo, int $uid, string $role): int {
    if ($uid <= 0 || !bcrf_notifications_ready($pdo)) return 0;
    [$w, $a] = bcrf_notify_visible($uid, $role);
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
                             WHERE $w AND r.notification_id IS NULL AND n.created_at >= UTC_TIMESTAMP() - INTERVAL 60 DAY");
        $st->execute(array_merge([$uid], $a));
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Newest first, with is_read. */
function bcrf_notifications_list(PDO $pdo, int $uid, string $role, int $limit = 60): array {
    if ($uid <= 0 || !bcrf_notifications_ready($pdo)) return [];
    [$w, $a] = bcrf_notify_visible($uid, $role);
    $st = $pdo->prepare("SELECT n.*, (r.notification_id IS NOT NULL) AS is_read FROM notifications n
                         LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = ?
                         WHERE $w AND n.created_at >= UTC_TIMESTAMP() - INTERVAL 60 DAY
                         ORDER BY n.created_at DESC, n.id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute(array_merge([$uid], $a));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_read'] = (bool)$r['is_read']; }
    return $rows;
}

/** Mark one (by id) or all of this person's notifications read. Returns how many changed. */
function bcrf_notifications_mark_read(PDO $pdo, int $uid, string $role, ?int $id = null): int {
    if ($uid <= 0 || !bcrf_notifications_ready($pdo)) return 0;
    [$w, $a] = bcrf_notify_visible($uid, $role);
    $st = $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id, read_at)
                         SELECT n.id, ?, UTC_TIMESTAMP() FROM notifications n WHERE $w" . ($id ? " AND n.id = ?" : ""));
    $st->execute(array_merge([$uid], $a, $id ? [$id] : []));
    return $st->rowCount();
}

/** One notification this person may see, or null. */
function bcrf_notification_get(PDO $pdo, int $uid, string $role, int $id): ?array {
    if ($uid <= 0 || !bcrf_notifications_ready($pdo)) return null;
    [$w, $a] = bcrf_notify_visible($uid, $role);
    $st = $pdo->prepare("SELECT n.* FROM notifications n WHERE n.id = ? AND $w");
    $st->execute(array_merge([$id], $a));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Look through recent records and add a notification for anything new. Returns how many were added. Never throws. */
function bcrf_notify_sweep(PDO $pdo): int {
    if (!bcrf_notifications_ready($pdo)) return 0;
    $added = 0;
    $money = fn(int $c) => '$' . number_format($c / 100, 2);
    $day = function (?string $d): string { return $d ? date('D M j', strtotime($d)) : 'date to be agreed'; };
    $safe = function (callable $f) { try { $f(); } catch (Throwable $e) { error_log('[notifications] sweep: ' . $e->getMessage()); } };

    $safe(function () use ($pdo, &$added, $day) {   // new party requests
        foreach ($pdo->query("SELECT id, customer_name, preferred_date, date_flexible, adult_count, child_count FROM event_inquiries
                              WHERE status = 'new' AND created_at >= NOW() - INTERVAL 3 DAY ORDER BY id LIMIT 50") as $r) {
            $g = (int)$r['adult_count'] + (int)$r['child_count'];
            $added += bcrf_notify($pdo, ['kind' => 'party_request', 'dedupe' => 'party_request:' . $r['id'], 'link' => '/admin/party.php?id=' . (int)$r['id'],
                'title' => 'New party request: ' . $r['customer_name'], 'body' => ($r['date_flexible'] ? 'Date to be agreed' : $day($r['preferred_date'])) . ' · ' . $g . ' guest' . ($g === 1 ? '' : 's')]) ? 1 : 0;
        }
    });
    $safe(function () use ($pdo, &$added, $day) {   // new group/school booking requests
        foreach ($pdo->query("SELECT b.id, b.customer_name, b.booking_date, b.date_flexible, b.party_size, t.label FROM bookings b JOIN booking_types t ON t.id = b.type_id
                              WHERE b.status = 'pending' AND b.created_at >= NOW() - INTERVAL 3 DAY ORDER BY b.id LIMIT 50") as $r) {
            $added += bcrf_notify($pdo, ['kind' => 'booking_request', 'dedupe' => 'booking_request:' . $r['id'],
                'link' => '/admin/admin.php?view=bookings' . ($r['booking_date'] ? '&day=' . rawurlencode((string)$r['booking_date']) : ''),
                'title' => 'New booking request: ' . $r['customer_name'], 'body' => $r['label'] . ' · ' . ($r['date_flexible'] ? 'date to be agreed' : $day($r['booking_date'])) . ' · ' . (int)$r['party_size'] . ' guests']) ? 1 : 0;
        }
    });
    if (!function_exists('bcrf_quotes_ready')) require_once __DIR__ . '/event_quotes.php';
    if (bcrf_quotes_ready($pdo)) {
        $safe(function () use ($pdo, &$added, $money) {   // online payments on quotes
            foreach ($pdo->query("SELECT p.id, p.amount_cents, p.subject_type, p.subject_id FROM event_payments p
                                  WHERE p.method = 'stripe' AND p.kind = 'payment' AND p.voided_at IS NULL AND p.created_at >= UTC_TIMESTAMP() - INTERVAL 3 DAY
                                  ORDER BY p.id LIMIT 50") as $r) {
                $s = bcrf_quote_subject($pdo, (string)$r['subject_type'], (int)$r['subject_id']);
                $added += bcrf_notify($pdo, ['kind' => 'online_payment', 'dedupe' => 'online_payment:' . $r['id'],
                    'link' => '/admin/quotes.php?for=' . rawurlencode($r['subject_type'] . ':' . $r['subject_id']),
                    'title' => ($s['name'] ?? 'A customer') . ' paid ' . $money((int)$r['amount_cents']) . ' online', 'body' => $s ? $s['what'] . ' · ' . bcrf_quote_when($s) : null]) ? 1 : 0;
            }
        });
        $safe(function () use ($pdo, &$added, $money) {   // deposits past due
            $today = (new DateTimeImmutable('now', new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York')))->format('Y-m-d');
            $st = $pdo->prepare("SELECT * FROM event_quotes WHERE deposit_cents > 0 AND deposit_due IS NOT NULL AND deposit_due < ? AND deposit_due >= ? - INTERVAL 30 DAY AND sent_at IS NOT NULL LIMIT 100");
            $st->execute([$today, $today]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $q = bcrf_quote_decode($row);
                $stt = bcrf_quote_status($pdo, $q);
                $s = bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
                if ($stt['deposit_left'] <= 0 || !$s || !$s['live']) continue;
                $added += bcrf_notify($pdo, ['kind' => 'deposit_overdue', 'dedupe' => 'deposit_overdue:' . $q['id'],
                    'link' => '/admin/quotes.php?for=' . rawurlencode($q['subject_type'] . ':' . $q['subject_id']),
                    'title' => 'Deposit overdue: ' . $s['name'], 'body' => $money($stt['deposit_left']) . ' was due ' . date('M j', strtotime((string)$q['deposit_due'])) . ' · ' . $s['what'] . ' · ' . bcrf_quote_when($s)]) ? 1 : 0;
            }
        });
        $safe(function () use ($pdo, &$added, $money) {   // quotes with no reply (Nov 14, 2026)
            $st = $pdo->prepare("SELECT * FROM event_quotes WHERE sent_at IS NOT NULL AND sent_at <= UTC_TIMESTAMP() - INTERVAL ? DAY
                                 AND sent_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY ORDER BY id LIMIT 100");
            $st->execute([(int)BCRF_QUOTE_NUDGE_DAYS]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $q = bcrf_quote_decode($row);
                $s = bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
                // Still waiting on them: a party not yet confirmed/declined, or a booking still pending, and nothing paid.
                if (!$s || !in_array($s['status'], $s['is_party'] ? ['new', 'contacted', 'quoted'] : ['pending'], true)) continue;
                if (bcrf_quote_status($pdo, $q)['paid'] !== 0) continue;
                $sentDay = date('M j', strtotime((string)$q['sent_at'] . ' UTC'));
                $link = '/admin/quotes.php?for=' . rawurlencode($q['subject_type'] . ':' . $q['subject_id']);
                $nid = bcrf_notify($pdo, ['kind' => 'quote_no_reply', 'dedupe' => 'quote_no_reply:' . $q['id'] . ':' . $q['sent_count'], 'link' => $link,
                    'title' => 'No reply yet: ' . $s['name'], 'body' => 'Quote ' . $money($q['total_cents']) . ' sent ' . $sentDay . ' · ' . $s['what'] . ' · ' . bcrf_quote_when($s)]);
                if (!$nid) continue;
                $added++;
                try {
                    if (!function_exists('bcrf_task_create')) require_once __DIR__ . '/tasks.php';
                    $today = (new DateTimeImmutable('now', new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York')))->format('Y-m-d');
                    bcrf_task_create($pdo, ['title' => 'Follow up: ' . $s['name'] . ' hasn\'t answered the quote', 'priority' => 'normal', 'due_date' => $today, 'who' => 'role:moderator',
                        'description' => 'Quote sent ' . $sentDay . ' (' . $money($q['total_cents']) . ') for ' . $s['what'] . ', ' . bcrf_quote_when($s) . '. Call or email ' . ($s['phone'] ?: $s['email'] ?: 'them')
                            . ' to see if they still want it, then confirm or decline it on the quote page: ' . $link,
                        'related_type' => $q['subject_type'], 'related_id' => (string)$q['subject_id']]);
                } catch (Throwable $e) { error_log('[notifications] quote follow-up task: ' . $e->getMessage()); }
            }
        });
    }
    $safe(function () use ($pdo, &$added) {   // your task is late
        foreach ($pdo->query("SELECT id, title, assignee_user_id FROM tasks WHERE late_notified_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY
                              AND assignee_user_id IS NOT NULL AND status NOT IN ('done', 'skipped', 'cancelled') ORDER BY id LIMIT 100") as $r) {
            $added += bcrf_notify($pdo, ['kind' => 'task_late', 'dedupe' => 'task_late:' . $r['id'], 'user_id' => (int)$r['assignee_user_id'],
                'link' => '/admin/tasks.php?id=' . (int)$r['id'], 'title' => 'Late: ' . $r['title']]) ? 1 : 0;
        }
    });
    try { $pdo->exec("DELETE FROM notifications WHERE created_at < UTC_TIMESTAMP() - INTERVAL 60 DAY"); $pdo->exec("DELETE r FROM notification_reads r LEFT JOIN notifications n ON n.id = r.notification_id WHERE n.id IS NULL"); }
    catch (Throwable $e) { /* tidy-up only */ }
    return $added;
}

} // function_exists guard
