<?php
// includes/party_emails.php — Farm OS Phase 3: the day-before reminder and the thank-you with the review link, for
// parties (Nov 12, 2026; migration 0038). Bookings get these from includes/booking.php; parties are event_inquiries rows,
// which the booking crons never read, so a party got neither. The same two crons now run these too:
//   admin/cron/send_booking_reminders.php   reminder: confirmed parties starting in the next 24 hours
//   admin/cron/send_review_requests.php     thank-you: confirmed parties that started 18 hours to 4 days ago
// A party with no start time counts from the farm's opening time that day. Both follow the "Visit reminders and
// thank-yous" email setting (includes/email_prefs.php). Each party is claimed before sending (reminder_sent_at /
// thank_you_sent_at, UTC), so two overlapping cron runs can't send twice, and a bad address isn't retried every hour.
// Once a waiver is published (includes/waivers.php), the reminder links to it when the host hasn't signed one.
if (!function_exists('bcrf_party_emails_ready')) {

if (!function_exists('bcrf_holds_day_hours')) require_once __DIR__ . '/capacity_holds.php';
if (!function_exists('bcrf_party_package')) require_once __DIR__ . '/parties.php';
if (!function_exists('bcrf_quote_subject')) require_once __DIR__ . '/event_quotes.php';
if (!function_exists('bcrf_email_pref_allowed')) require_once __DIR__ . '/email_prefs.php';
if (!function_exists('_booking_mail')) require_once __DIR__ . '/booking.php';

function bcrf_party_emails_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT reminder_sent_at, thank_you_sent_at FROM event_inquiries LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/** When a party starts, farm-local: its start time, or the farm's opening time that day when none is set. Null = no date. */
function bcrf_party_start_at(PDO $pdo, array $inq): ?DateTimeImmutable {
    $date = (string)($inq['preferred_date'] ?? '');
    if ($date === '' || !empty($inq['date_flexible'])) return null;
    $time = (string)($inq['start_time'] ?? '');
    if ($time === '') $time = bcrf_holds_day_hours($pdo, $date)[0];
    try { return new DateTimeImmutable($date . ' ' . $time, bcrf_holds_tz()); } catch (Throwable $e) { return null; }
}

/** Confirmed parties due an email now. $kind: reminder | thank_you. */
function bcrf_party_emails_due(PDO $pdo, string $kind, ?DateTimeImmutable $now = null): array {
    if (!bcrf_party_emails_ready($pdo)) return [];
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(bcrf_holds_tz());
    $col = $kind === 'reminder' ? 'reminder_sent_at' : 'thank_you_sent_at';
    [$from, $to] = $kind === 'reminder' ? [$now, $now->modify('+24 hours')] : [$now->modify('-4 days'), $now->modify('-18 hours')];
    $st = $pdo->prepare("SELECT * FROM event_inquiries
                         WHERE status = 'confirmed' AND $col IS NULL AND email <> '' AND preferred_date BETWEEN ? AND ?
                         ORDER BY preferred_date, start_time, id LIMIT 200");
    $st->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $at = bcrf_party_start_at($pdo, $r);
        if (!$at) continue;
        if ($kind === 'reminder' ? ($at > $from && $at <= $to) : ($at >= $from && $at <= $to)) $out[] = $r;
    }
    return $out;
}

/** [subject, inner HTML] for the day-before reminder. */
function bcrf_party_reminder_email(PDO $pdo, array $inq): array {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
    $s = bcrf_quote_subject($pdo, 'event_inquiry', (int)$inq['id']);
    $first = trim(explode(' ', trim((string)$inq['customer_name']))[0] ?? '');
    $q = bcrf_quotes_ready($pdo) ? bcrf_quote_get($pdo, 'event_inquiry', (int)$inq['id']) : null;
    $pkg = bcrf_party_package($pdo, (int)($inq['package_id'] ?? 0));
    $date = date('l, F j', strtotime((string)$inq['preferred_date']));
    $row = fn($k, $v) => '<tr><td style="color:#c9b48f;padding:4px 14px 4px 0;vertical-align:top;">' . $k . '</td><td style="color:#f5ead6;padding:4px 0;">' . $v . '</td></tr>';
    $guests = (int)$inq['adult_count'] + (int)$inq['child_count'];
    $who = $guests . ' guest' . ($guests === 1 ? '' : 's');
    if ((int)$inq['child_count'] > 0) $who .= ' (' . (int)$inq['adult_count'] . ' adult' . ((int)$inq['adult_count'] === 1 ? '' : 's') . ', ' . (int)$inq['child_count'] . ' child' . ((int)$inq['child_count'] === 1 ? '' : 'ren') . ')';
    $table = '<table style="margin:14px 0;border-collapse:collapse;">'
           . $row('When', $h($date) . (!empty($inq['start_time']) ? ' at ' . $h(date('g:i A', strtotime((string)$inq['start_time']))) : ''))
           . $row('What', $h($s['what'] ?? 'Party') . ($pkg ? ' · ' . $h($pkg['name']) : ''))
           . $row('Guests', $h($who));
    if ($q) {
        $st = bcrf_quote_status($pdo, $q);
        $cost = $st['stage'] === 'paid' ? ' · paid in full'
              : ($st['balance'] > 0 ? ($st['paid'] > 0 ? ' · ' . bcrf_quote_money($st['balance']) . ' left, due when you arrive' : ', due when you arrive') : '');
        $table .= $row('Cost', $h(bcrf_quote_money($q['total_cents']) . $cost));
    }
    $table .= '</table>';
    $terms = array_slice(bcrf_quote_terms($q ?? ['deposit_cents' => 0], $s ?? ['is_party' => true]), 1);
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;">See you tomorrow! 🎈</h1>'
        . '<p style="color:#e8d5b0;">' . ($first !== '' ? 'Hi ' . $h($first) . ', a' : 'A') . ' friendly reminder of your party at the farm:</p>'
        . $table
        . (empty($inq['start_time']) ? '<p style="color:#e8d5b0;"><strong>We don\'t have a start time for you yet.</strong> Just reply to this email with the time you\'d like.</p>' : '')
        . '<ul style="color:#e8d5b0;padding-left:18px;">' . implode('', array_map(fn($t) => '<li style="margin:4px 0;">' . $h($t) . '</li>', $terms)) . '</ul>'
        . ($q ? '<p style="color:#e8d5b0;"><a style="color:#e8b84b;" href="' . $h(bcrf_quote_url($q)) . '">See your quote</a></p>' : '')
        . bcrf_party_waiver_line($pdo, $inq)
        . '<p style="color:#e8d5b0;">Running late or need to change something? Just reply to this email.</p>'
        . '<p style="color:#e8d5b0;">' . $h(bcrf_quote_signoff()) . '</p>'
        . bcrf_email_prefs_footer($pdo, (string)$inq['email'], 'visit reminders and thank-yous', 'you booked a party with us');
    return ['Reminder: your party tomorrow at Bat Cave Road Farm', $inner];
}

/** "Sign the waiver ahead of time" with a link, when waivers are published and this email hasn't signed one ('' otherwise). */
function bcrf_party_waiver_line(PDO $pdo, array $inq): string {
    try {
        if (!function_exists('bcrf_waivers_ready')) require_once __DIR__ . '/waivers.php';
        if (!bcrf_waivers_ready($pdo) || !bcrf_waiver_current($pdo) || bcrf_waiver_valid_for($pdo, (string)$inq['email'])) return '';
    } catch (Throwable $e) { return ''; }
    $base = (function_exists('bcrf_farm_url') ? bcrf_farm_url() : 'https://batcaveroadfarm.com') . '/waiver.php';
    $url = $base . '?' . http_build_query(['email' => $inq['email'], 'name' => $inq['customer_name']]);
    return '<p style="color:#e8d5b0;">Save time at the gate: <a style="color:#e8b84b;" href="' . htmlspecialchars($url, ENT_QUOTES) . '">sign the waiver</a> for you and the kids with you. '
         . 'Other parents can sign at <a style="color:#e8b84b;" href="' . htmlspecialchars($base, ENT_QUOTES) . '">' . htmlspecialchars(preg_replace('#^https?://#', '', $base), ENT_QUOTES) . '</a>.</p>';
}

/** [subject, inner HTML] for the thank-you with the review link. */
function bcrf_party_thank_you_email(PDO $pdo, array $inq): array {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
    $first = trim(explode(' ', trim((string)$inq['customer_name']))[0] ?? '');
    $site = function_exists('bcrf_farm_url') ? bcrf_farm_url() : 'https://batcaveroadfarm.com';
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;">Thanks for celebrating with us! 🐐</h1>'
        . '<p style="color:#e8d5b0;">' . ($first !== '' ? 'Hi ' . $h($first) . ', thank' : 'Thank') . ' you for having your party at the farm. We hope everyone had a great time with the animals.</p>'
        . '<p style="color:#e8d5b0;">If you have a minute, a Google review helps other families find us:</p>'
        . '<p style="margin:18px 0;"><a style="background:#e8b84b;color:#3b2010;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold;" href="' . $h($site . '/review/') . '">Leave a review</a></p>'
        . '<p style="color:#e8d5b0;">Anything we could do better? Just reply to this email.</p>'
        . '<p style="color:#e8d5b0;">' . $h(bcrf_quote_signoff()) . '</p>'
        . bcrf_email_prefs_footer($pdo, (string)$inq['email'], 'visit reminders and thank-yous', 'you booked a party with us');
    return ['Thanks for celebrating at Bat Cave Road Farm', $inner];
}

/**
 * Send the reminders ($kind = reminder) or thank-yous ($kind = thank_you) that are due. $send(to, subject, innerHtml): bool
 * defaults to the booking emails' sender. Returns [due, sent].
 */
function bcrf_party_emails_run(PDO $pdo, string $kind, ?DateTimeImmutable $now = null, ?callable $send = null): array {
    $send ??= fn($to, $subject, $inner) => _booking_mail($to, $subject, $inner);
    $col = $kind === 'reminder' ? 'reminder_sent_at' : 'thank_you_sent_at';
    $rows = bcrf_party_emails_due($pdo, $kind, $now);
    $claim = $pdo->prepare("UPDATE event_inquiries SET $col = UTC_TIMESTAMP() WHERE id = ? AND $col IS NULL");
    $sent = 0;
    foreach ($rows as $r) {
        $claim->execute([(int)$r['id']]);
        if ($claim->rowCount() !== 1) continue;   // another run has it
        if (!bcrf_email_pref_allowed($pdo, (string)$r['email'], 'visit_reminders')) continue;
        try {
            [$subject, $inner] = $kind === 'reminder' ? bcrf_party_reminder_email($pdo, $r) : bcrf_party_thank_you_email($pdo, $r);
            if ($send((string)$r['email'], $subject, $inner)) $sent++;
        } catch (Throwable $e) {
            error_log('[BCRF] party ' . $kind . ' email for party ' . (int)$r['id'] . ': ' . $e->getMessage());
        }
    }
    return [count($rows), $sent];
}

} // function_exists guard
