<?php
/**
 * includes/booking.php — Booking engine for batcaveroadfarm.com.
 *
 * Admin-confirmed bookings; no public live-availability, no online payment.
 * Capacity is enforced at CONFIRM time: a concurrent (overlap-peak) cap on the
 * grounds for normal bookings, and whole-grounds exclusivity for private events.
 *
 * Requires $pdo (PDO) and config.php helpers. Pulls contact details from the
 * Site Info accessor and sends mail via the branded email template.
 *
 * Times are America/New_York wall-clock (one farm, one timezone).
 */

require_once __DIR__ . '/site_info.php';
if (is_file(__DIR__ . '/../admin/includes/email_template.php')) {
    require_once __DIR__ . '/../admin/includes/email_template.php';
}

if (!function_exists('booking_grounds_cap')) {

/** Max people concurrently on the grounds (overlap peak for non-exclusive bookings). */
function booking_grounds_cap(): int { return 25; }

/**
 * Calculate the total charge for a booking.
 * Returns 0.00 for free / quote-only types (unit_price = 0 or requires_payment = 0).
 * $type is a booking_types row; $partySize only matters for per_person pricing.
 */
function booking_calculate_total(array $type, int $partySize): float {
    if (empty($type['requires_payment']) || (float)($type['unit_price'] ?? 0) <= 0) return 0.00;
    $price = (float)$type['unit_price'];
    return ($type['pricing_model'] ?? 'flat') === 'per_person'
        ? round($price * max(1, $partySize), 2)
        : round($price, 2);
}

/** Format a total as a dollar string, e.g. '$24.00'. Returns 'Free' for 0. */
function booking_fmt_total(float $total): string {
    return $total > 0 ? '$' . number_format($total, 2) : 'Free';
}

function booking_tables_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM bookings LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}

/** Active booking types, ordered. */
function booking_types(PDO $pdo): array {
    try {
        return $pdo->query("SELECT * FROM booking_types WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

function booking_type(PDO $pdo, string $key): ?array {
    try {
        $st = $pdo->prepare("SELECT * FROM booking_types WHERE type_key = ? AND is_active = 1");
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/** 64-char unguessable token for the cancel/manage link. */
function booking_make_token(): string { return bin2hex(random_bytes(32)); }

/** start + duration → 'HH:MM:SS', clamped to end-of-day. */
function booking_end_time(string $start, int $durationMin): string {
    $ts = strtotime($start);
    if ($ts === false) return $start;
    $end = $ts + $durationMin * 60;
    $dayEnd = strtotime(date('Y-m-d', $ts) . ' 23:59:00');
    if ($end > $dayEnd) $end = $dayEnd;
    return date('H:i:s', $end);
}

/** Half-open interval overlap. */
function booking_overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool {
    return ($aStart < $bEnd) && ($aEnd > $bStart);
}

/**
 * Can this candidate be CONFIRMED? Call inside a transaction (it locks the
 * day's confirmed rows FOR UPDATE). $cand = [date, start, end, party_size,
 * is_exclusive, exclude_id?]. Returns ['ok'=>bool, 'reason'=>string].
 */
function booking_capacity_ok(PDO $pdo, array $cand): array {
    $date     = $cand['date'];
    $start    = $cand['start'];
    $end      = $cand['end'];
    $size     = max(1, (int)($cand['party_size'] ?? 1));
    $excl     = !empty($cand['is_exclusive']);
    $exclude  = (int)($cand['exclude_id'] ?? 0);
    $cap      = booking_grounds_cap();

    // 1) Blackout date
    try {
        $st = $pdo->prepare("SELECT 1 FROM booking_blackouts WHERE d_date = ?");
        $st->execute([$date]);
        if ($st->fetchColumn()) return ['ok' => false, 'reason' => 'That date is unavailable.'];
    } catch (Throwable $e) { /* blackouts table optional */ }

    // 2) Within the farm's open hours for that weekday (if Site Info is present)
    if (function_exists('site_hours')) {
        $dow   = (int)date('w', strtotime($date)); // 0=Sun..6=Sat
        $week  = site_hours('general');
        $day   = $week[$dow] ?? ['closed' => true, 'blocks' => []];
        $fits  = false;
        foreach ($day['blocks'] as $b) {
            if ($start >= $b['open'] && $end <= $b['close']) { $fits = true; break; }
        }
        if ($day['closed'] || !$fits) {
            return ['ok' => false, 'reason' => 'That time is outside our open hours for that day.'];
        }
    }

    // 3) Confirmed bookings that day (locked)
    try {
        $sql = "SELECT id, start_time, end_time, party_size, is_exclusive
                FROM bookings WHERE booking_date = ? AND status = 'confirmed'";
        $args = [$date];
        if ($exclude > 0) { $sql .= " AND id <> ?"; $args[] = $exclude; }
        $sql .= " FOR UPDATE";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $existing = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'Could not check availability — please try again.'];
    }

    // 4) Exclusive candidate: no overlap with anything
    if ($excl) {
        foreach ($existing as $e) {
            if (booking_overlaps($start, $end, $e['start_time'], $e['end_time'])) {
                return ['ok' => false, 'reason' => 'That window conflicts with an existing booking.'];
            }
        }
        return ['ok' => true, 'reason' => ''];
    }

    // 5) Non-exclusive: blocked by any overlapping exclusive event, then peak ≤ cap
    foreach ($existing as $e) {
        if ((int)$e['is_exclusive'] === 1 && booking_overlaps($start, $end, $e['start_time'], $e['end_time'])) {
            return ['ok' => false, 'reason' => 'That time is reserved for a private event.'];
        }
    }
    // overlap-peak sweep: at each interval start within the candidate window,
    // sum party sizes of all non-exclusive intervals covering that instant.
    $intervals = [['s' => $start, 'e' => $end, 'n' => $size]];
    foreach ($existing as $e) {
        if ((int)$e['is_exclusive'] === 1) continue;
        if (booking_overlaps($start, $end, $e['start_time'], $e['end_time'])) {
            $intervals[] = ['s' => $e['start_time'], 'e' => $e['end_time'], 'n' => (int)$e['party_size']];
        }
    }
    $peak = 0;
    foreach ($intervals as $pt) {
        $t = $pt['s'];
        $sum = 0;
        foreach ($intervals as $iv) {
            if ($iv['s'] <= $t && $t < $iv['e']) $sum += $iv['n'];
        }
        if ($sum > $peak) $peak = $sum;
    }
    if ($peak > $cap) {
        return ['ok' => false, 'reason' => 'We are at capacity around that time. Please pick another slot.'];
    }
    return ['ok' => true, 'reason' => ''];
}

/* ---------- formatting ---------- */
function booking_fmt_time(string $t): string { $ts = strtotime($t); return $ts ? date('g:i A', $ts) : $t; }
function booking_fmt_date(string $d): string { $ts = strtotime($d); return $ts ? date('l, F j, Y', $ts) : $d; }

/* ---------- email ---------- */
function _booking_from(): array {
    $email = function_exists('site') ? (site('email.booking') ?: 'support@batcaveroadfarm.com') : 'support@batcaveroadfarm.com';
    $name  = function_exists('site_setting') ? site_setting('business_name', 'Bat Cave Road Farm') : 'Bat Cave Road Farm';
    return [$name, $email];
}

/** Send one branded HTML+text email via mail(); returns bool. */
function _booking_mail(string $to, string $subject, string $innerHtml): bool {
    [$fromName, $fromEmail] = _booking_from();
    $html = (function_exists('bcf_email_header') ? bcf_email_header() : '<div style="font-family:Georgia,serif;color:#3b2010;">')
          . $innerHtml
          . (function_exists('bcf_email_footer') ? bcf_email_footer() : '</div>');
    $text = trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $innerHtml)), ENT_QUOTES))); 

    $boundary = 'bcrf_' . bin2hex(random_bytes(8));
    $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n--{$boundary}--";

    $headers  = 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";

    return @mail($to, $subject, $body, $headers, '-f' . $fromEmail);
}

/** Shared HTML block summarizing a booking. */
function _booking_summary_html(array $b, ?array $type = null): string {
    $label = $type['label'] ?? ($b['type_label'] ?? 'Booking');
    $rows = [
        'What'  => htmlspecialchars($label),
        'When'  => htmlspecialchars(booking_fmt_date($b['booking_date']) . ' · ' . booking_fmt_time($b['start_time'])),
        'Party' => (int)$b['party_size'] . ' ' . ((int)$b['party_size'] === 1 ? 'person' : 'people'),
        'Name'  => htmlspecialchars($b['customer_name']),
    ];
    $h = '<table style="border-collapse:collapse;margin:8px 0;">';
    foreach ($rows as $k => $v) {
        $h .= '<tr><td style="padding:3px 12px 3px 0;color:#8b5c2a;font-weight:bold;">' . $k . '</td><td style="padding:3px 0;color:#3b2010;">' . $v . '</td></tr>';
    }
    $h .= '</table>';
    return $h;
}

function _booking_cancel_url(array $b): string {
    return 'https://batcaveroadfarm.com/cancel-booking.php?id=' . (int)$b['id'] . '&t=' . rawurlencode($b['token']);
}

/** Acknowledge a new request (status pending). */
function booking_send_ack(array $b, ?array $type = null): bool {
    if (empty($b['email'])) return false;
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;">We got your request 🐐</h1>'
        . '<p style="color:#e8d5b0;">Thanks, ' . htmlspecialchars($b['customer_name']) . "! We've received your booking request and will confirm by email shortly. Here's what you sent:</p>"
        . _booking_summary_html($b, $type)
        . '<p style="color:#e8d5b0;">Nothing is reserved until we confirm. Questions? Just reply to this email.</p>';
    return _booking_mail($b['email'], 'Your booking request — Bat Cave Road Farm', $inner);
}

/** Confirm a booking (status confirmed). */
function booking_send_confirmation(array $b, ?array $type = null): bool {
    if (empty($b['email'])) return false;
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;">You\'re confirmed! 🎉</h1>'
        . '<p style="color:#e8d5b0;">See you soon, ' . htmlspecialchars($b['customer_name']) . '. Your booking is confirmed:</p>'
        . _booking_summary_html($b, $type)
        . '<p style="color:#e8d5b0;">Need to cancel? <a style="color:#e8b84b;" href="' . _booking_cancel_url($b) . '">Cancel this booking</a>.</p>';
    return _booking_mail($b['email'], 'Booking confirmed — Bat Cave Road Farm', $inner);
}

/** 24h-out reminder. */
function booking_send_reminder(array $b, ?array $type = null): bool {
    if (empty($b['email'])) return false;
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;">See you tomorrow! 🌾</h1>'
        . '<p style="color:#e8d5b0;">A friendly reminder of your upcoming visit:</p>'
        . _booking_summary_html($b, $type)
        . '<p style="color:#e8d5b0;">Can\'t make it? <a style="color:#e8b84b;" href="' . _booking_cancel_url($b) . '">Cancel here</a> so we can free the spot.</p>';
    return _booking_mail($b['email'], 'Reminder: your visit tomorrow — Bat Cave Road Farm', $inner);
}

/** Notify the farm of a new request. */
function booking_send_admin_alert(array $b, ?array $type = null): bool {
    [, $to] = _booking_from();
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;">New booking request</h1>'
        . _booking_summary_html($b, $type)
        . '<p style="color:#e8d5b0;">Phone: ' . htmlspecialchars($b['phone'] ?: '—') . '<br>Email: ' . htmlspecialchars($b['email'] ?: '—') . '</p>'
        . (!empty($b['notes']) ? '<p style="color:#e8d5b0;"><em>' . nl2br(htmlspecialchars($b['notes'])) . '</em></p>' : '')
        . '<p style="color:#e8d5b0;">Review &amp; confirm in the admin → Bookings.</p>';
    return _booking_mail($to, 'New booking request — ' . ($b['customer_name'] ?? ''), $inner);
}

} // function_exists guard
