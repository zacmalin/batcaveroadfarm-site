<?php
// includes/weather_days.php — Farm OS Phase 3 slice 5 (Nov 12, 2026; 07 › Bookings: "Weather cancellations by the farm
// notify all of that day's bookings and events in one action"; staff then choose refund, credit or reschedule).
//
//   bcrf_weather_subjects($pdo, $date)   that day's live group bookings (pending/confirmed, not day tickets) and parties
//                                        (new/contacted/quoted/confirmed): who would be told
//   bcrf_weather_call(...)               record the day (weather_days), email everyone not told yet, and optionally close
//                                        the day to new bookings (booking_blackouts). Owner or Manager; staff press it.
//   bcrf_weather_set_outcome(...)        what was done for each one: rescheduled, refunded, credit, cancelled (or open)
// Nothing here changes a booking's or party's status or date, or moves money: staff do that with the usual tools
// (Bookings, Private Events, Quotes & payments). Emailed bookings are marked reminded so the cheerful day-before
// reminder (admin/cron/send_booking_reminders.php) doesn't follow the bad news.
if (!function_exists('bcrf_weather_ready')) {

if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';
if (!function_exists('bcrf_quotes_ready')) require_once __DIR__ . '/event_quotes.php';

class BcrfWeatherError extends RuntimeException {}

define('BCRF_WEATHER_OUTCOMES', ['open' => 'Not sorted yet', 'rescheduled' => 'Rescheduled', 'refunded' => 'Refunded',
                                 'credit' => 'Credit for later', 'cancelled' => 'Cancelled, nothing owed']);

function bcrf_weather_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM weather_days LIMIT 0"); $pdo->query("SELECT 1 FROM weather_day_items LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function bcrf_weather_default_message(PDO $pdo): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'weather_message'");
        $st->execute();
        $v = trim((string)$st->fetchColumn());
        if ($v !== '') return $v;
    } catch (Throwable $e) {}
    return "We're sorry, but the weather means we can't safely host you on this day. Reply to this email and we'll move your visit or refund what you've paid.";
}

/** That day's bookings and parties to tell, in start order. Each: type, id, name, email, what, status, guests, when. */
function bcrf_weather_subjects(PDO $pdo, string $date): array {
    $out = [];
    $st = $pdo->prepare("SELECT b.id FROM bookings b JOIN booking_types t ON t.id = b.type_id
                         WHERE b.booking_date = ? AND b.date_flexible = 0 AND b.status IN ('pending', 'confirmed') AND t.type_key <> 'day_admission'
                         ORDER BY b.start_time, b.id");
    $st->execute([$date]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) if ($s = bcrf_quote_subject($pdo, 'booking', (int)$id)) $out[] = $s;
    $st = $pdo->prepare("SELECT id FROM event_inquiries WHERE preferred_date = ? AND status IN ('new', 'contacted', 'quoted', 'confirmed') ORDER BY id");
    $st->execute([$date]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) if ($s = bcrf_quote_subject($pdo, 'event_inquiry', (int)$id)) $out[] = $s;
    usort($out, fn($a, $b) => [$a['start'] ?: '99', $a['name']] <=> [$b['start'] ?: '99', $b['name']]);
    return array_map(fn($s) => ['type' => $s['type'], 'id' => $s['id'], 'name' => $s['name'], 'email' => $s['email'], 'what' => $s['what'],
                                'status' => $s['status'], 'guests' => $s['guests'], 'when' => bcrf_quote_when($s)], $out);
}

/** The weather day for a date, with its items (and each item's subject), or null. */
function bcrf_weather_day(PDO $pdo, string $date): ?array {
    $st = $pdo->prepare("SELECT * FROM weather_days WHERE business_date = ?");
    $st->execute([$date]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;
    $st = $pdo->prepare("SELECT * FROM weather_day_items WHERE weather_day_id = ? ORDER BY id");
    $st->execute([$d['id']]);
    $d['items'] = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $s = bcrf_quote_subject($pdo, $it['subject_type'], (int)$it['subject_id']);
        $it['name'] = $s['name'] ?? '(removed)';
        $it['what'] = $s['what'] ?? '';
        $it['status'] = $s['status'] ?? '';
        $it['now_date'] = $s['date'] ?? '';
        $d['items'][] = $it;
    }
    return $d;
}

/** Dates already called off (recent first), for the page's list. */
function bcrf_weather_recent(PDO $pdo, int $limit = 10): array {
    return $pdo->query("SELECT d.business_date, d.closed_day, COUNT(i.id) AS told, SUM(i.outcome = 'open') AS open_items
                        FROM weather_days d LEFT JOIN weather_day_items i ON i.weather_day_id = d.id
                        GROUP BY d.id ORDER BY d.business_date DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Call the day off: record it, email everyone on it who hasn't been told, close the day if asked.
 * Returns ['told' => n emailed, 'no_email' => [names], 'failed' => [names], 'closed' => bool].
 * $send: the mailer (tests stand in for it); defaults to bcf_send_mail.
 */
function bcrf_weather_call(PDO $pdo, string $date, string $message, bool $closeDay, ?int $uid, ?callable $send = null): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
        throw new BcrfWeatherError('Pick the day to call off.');
    }
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    if ($date < (new DateTimeImmutable('now', $tz))->format('Y-m-d')) throw new BcrfWeatherError('That day has already passed.');
    $message = trim(str_replace("\r", '', strip_tags($message)));
    if ($message === '') throw new BcrfWeatherError('Write the message customers will get.');
    if (mb_strlen($message) > 3000) throw new BcrfWeatherError('Keep the message under 3,000 characters.');

    $pdo->prepare("INSERT INTO weather_days (business_date, message, closed_day, created_at, created_by) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)
                   ON DUPLICATE KEY UPDATE message = VALUES(message), closed_day = GREATEST(closed_day, VALUES(closed_day))")
        ->execute([$date, $message, $closeDay ? 1 : 0, $uid]);
    $st = $pdo->prepare("SELECT id FROM weather_days WHERE business_date = ?");
    $st->execute([$date]);
    $dayId = (int)$st->fetchColumn();

    $closed = false;
    if ($closeDay) {
        try {
            $st = $pdo->prepare("INSERT IGNORE INTO booking_blackouts (d_date, reason) VALUES (?, 'Weather')");
            $st->execute([$date]);
            $closed = true;
        } catch (Throwable $e) { error_log('[weather] blackout: ' . $e->getMessage()); }
    }

    $send = $send ?? function (string $to, string $subject, string $html): bool {
        if (!function_exists('bcf_send_mail')) require_once dirname(__DIR__) . '/admin/includes/email_template.php';
        return bcf_send_mail($to, $subject, $html, ['subtitle' => 'Weather update', 'reply_to' => bcrf_farm_email('support')]);
    };
    $told = 0; $noEmail = []; $failed = [];
    $have = $pdo->prepare("SELECT 1 FROM weather_day_items WHERE weather_day_id = ? AND subject_type = ? AND subject_id = ? AND sent_at IS NOT NULL");
    $save = $pdo->prepare("INSERT INTO weather_day_items (weather_day_id, subject_type, subject_id, email, sent_at, send_error) VALUES (?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE email = VALUES(email), sent_at = VALUES(sent_at), send_error = VALUES(send_error)");
    foreach (bcrf_weather_subjects($pdo, $date) as $s) {
        $have->execute([$dayId, $s['type'], $s['id']]);
        if ($have->fetchColumn()) continue;   // already told
        $to = filter_var(trim($s['email']), FILTER_VALIDATE_EMAIL);
        if (!$to) { $save->execute([$dayId, $s['type'], $s['id'], '', null, 'No email address: call them']); $noEmail[] = $s['name']; continue; }
        $ok = false; $err = null;
        try { $ok = (bool)$send($to, bcrf_weather_subject_line($s), bcrf_weather_email_html($pdo, $s, $message)); }
        catch (Throwable $e) { $err = mb_substr($e->getMessage(), 0, 255); }
        if ($ok) {
            $save->execute([$dayId, $s['type'], $s['id'], $to, gmdate('Y-m-d H:i:s'), null]);
            $told++;
            if ($s['type'] === 'booking') {
                $pdo->prepare("UPDATE bookings SET reminder_sent_at = COALESCE(reminder_sent_at, NOW()) WHERE id = ?")->execute([$s['id']]);
            }
            if (function_exists('erp_audit')) {
                try { erp_audit($pdo, 'update', $s['type'] === 'booking' ? 'bookings' : 'event_inquiries', $s['id'], $s['name'] . ' (' . $date . ') - weather: told by email (' . $to . ')'); }
                catch (Throwable $e) {}
            }
        } else {
            $save->execute([$dayId, $s['type'], $s['id'], $to, null, $err ?? 'The email did not send']);
            $failed[] = $s['name'];
        }
    }
    if (function_exists('bcrf_event')) bcrf_event('weather.day_called', 'weather_days', $dayId, ['date' => $date, 'told' => $told, 'closed' => $closed]);
    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        try {
            [$t, $u, $name, $ip] = bcrf_audit_actor();
            bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $u, 'actor_label' => $name, 'ip' => $ip, 'action' => 'update', 'object_type' => 'weather_days',
                'object_id' => $dayId, 'object_label' => 'Weather day ' . $date . ': ' . $told . ' emailed' . ($closed ? ', day closed to bookings' : '')]);
        } catch (Throwable $e) {}
    }
    return ['told' => $told, 'no_email' => $noEmail, 'failed' => $failed, 'closed' => $closed];
}

function bcrf_weather_subject_line(array $s): string {
    return 'Weather update for your ' . strtolower($s['what']) . ' — ' . bcrf_farm_name();
}

function bcrf_weather_email_html(PDO $pdo, array $s, string $message): string {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $p = 'margin:0 0 12px;color:#e8d5b0';
    $first = trim(explode(' ', trim($s['name']))[0] ?? '') ?: 'there';
    $q = bcrf_quotes_ready($pdo) ? bcrf_quote_get($pdo, $s['type'], $s['id']) : null;
    $html = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.3rem;margin:0 0 14px">Weather update</h1>'
        . '<p style="' . $p . '">Hi ' . $h($first) . ',</p>'
        . '<p style="' . $p . '">This is about your ' . $h(strtolower($s['what'])) . ' on <strong style="color:#f5ead6">' . $h($s['when']) . '</strong>.</p>'
        . '<p style="' . $p . '">' . nl2br($h($message)) . '</p>';
    if ($q) {
        $st = bcrf_quote_status($pdo, $q);
        if ($st['paid'] > 0) $html .= '<p style="' . $p . '">You\'ve paid ' . $h(bcrf_quote_money($st['paid'])) . ' so far. <a style="color:#e8b84b" href="' . $h(bcrf_quote_url($q)) . '">See your quote and payments</a>.</p>';
    }
    return $html . '<p style="' . $p . '">' . $h(bcrf_quote_signoff()) . '</p>';
}

function bcrf_weather_set_outcome(PDO $pdo, int $itemId, string $outcome, string $note, ?int $uid): void {
    if (!isset(BCRF_WEATHER_OUTCOMES[$outcome])) throw new BcrfWeatherError('Pick what happened.');
    $note = mb_substr(trim(strip_tags($note)), 0, 255);
    $st = $pdo->prepare("SELECT i.*, d.business_date FROM weather_day_items i JOIN weather_days d ON d.id = i.weather_day_id WHERE i.id = ?");
    $st->execute([$itemId]);
    $it = $st->fetch(PDO::FETCH_ASSOC);
    if (!$it) throw new BcrfWeatherError('That one was not found.');
    $pdo->prepare("UPDATE weather_day_items SET outcome = ?, outcome_note = ?, outcome_at = UTC_TIMESTAMP(), outcome_by = ? WHERE id = ?")
        ->execute([$outcome, $note !== '' ? $note : null, $uid, $itemId]);
    if (function_exists('erp_audit')) {
        try { erp_audit($pdo, 'update', $it['subject_type'] === 'booking' ? 'bookings' : 'event_inquiries', (int)$it['subject_id'],
                        'Weather day ' . $it['business_date'] . ': ' . BCRF_WEATHER_OUTCOMES[$outcome] . ($note !== '' ? ' (' . $note . ')' : '')); }
        catch (Throwable $e) {}
    }
}

}
