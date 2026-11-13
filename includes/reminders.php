<?php
// includes/reminders.php — reminder emails parked in Phase 1/3, finished before go-live (Nov 13, 2026; migration 0063).
// Earlier phases hard-code the few automations they need (roadmap 16, Phase 7 moves them into editable rules later).
//   Deposit reminder   a sent quote (group/school booking, or a party that was given a deposit) whose deposit isn't covered:
//                      one email 3 days before deposit_due, or on the day if it was set later. Parties have no deposit by default.
//   Balance reminder   a quote whose balance is due before the day (balance_when = before), deposit covered, money left:
//                      one email 5 days before the visit, or as soon as that window opens.
//   Pass renewal       an active, paid annual pass ending within 14 days: one "your pass ends on …" email with the passes page.
// All three are about the customer's own booking or pass (transactional), so they don't need an opt-in; each still carries the
// preferences link. Each row is claimed before sending (…_reminded_at, UTC), so overlapping runs can't send twice and a bad
// address isn't retried. Managers see each send on the bell (includes/notifications.php).
// BCRF_REMINDER_EMAILS_LIVE: the owner OK'd the wording Nov 13, 2026, so it is on. Set it to false to hold every reminder
// (nothing is claimed or sent); bcrf_reminder_preview() shows what would go out. Run by admin/cron/reminders.php between 9 AM and 6 PM farm time.
if (!function_exists('bcrf_reminders_ready')) {

if (!defined('BCRF_REMINDER_EMAILS_LIVE')) define('BCRF_REMINDER_EMAILS_LIVE', true);

if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';
if (!function_exists('bcrf_quote_subject')) require_once __DIR__ . '/event_quotes.php';
if (!function_exists('bcrf_email_prefs_footer')) require_once __DIR__ . '/email_prefs.php';
if (!function_exists('bcrf_notify')) require_once __DIR__ . '/notifications.php';

define('BCRF_DEPOSIT_REMIND_DAYS', 3);
define('BCRF_BALANCE_REMIND_DAYS', 5);
define('BCRF_PASS_REMIND_DAYS', 14);

function bcrf_reminders_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT deposit_reminded_at, balance_reminded_at FROM event_quotes LIMIT 0"); $pdo->query("SELECT renewal_reminded_at FROM passes LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function bcrf_reminders_today(?DateTimeImmutable $now = null): DateTimeImmutable {
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    return ($now ?? new DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
}

/** Quotes due a reminder now. $kind: deposit | balance. Each item: ['quote' => …, 'subject' => …, 'status' => …]. */
function bcrf_reminders_quotes_due(PDO $pdo, string $kind, ?DateTimeImmutable $now = null): array {
    if (!bcrf_reminders_ready($pdo)) return [];
    $today = bcrf_reminders_today($now);
    $d = $today->format('Y-m-d');
    if ($kind === 'deposit') {
        $st = $pdo->prepare("SELECT * FROM event_quotes WHERE deposit_cents > 0 AND deposit_due IS NOT NULL AND sent_at IS NOT NULL AND deposit_reminded_at IS NULL
                             AND deposit_due BETWEEN ? AND ? ORDER BY deposit_due, id LIMIT 200");
        $st->execute([$d, $today->modify('+' . BCRF_DEPOSIT_REMIND_DAYS . ' days')->format('Y-m-d')]);
    } else {
        $st = $pdo->prepare("SELECT * FROM event_quotes WHERE balance_when = 'before' AND total_cents > 0 AND sent_at IS NOT NULL AND balance_reminded_at IS NULL ORDER BY id LIMIT 500");
        $st->execute();
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $q = bcrf_quote_decode($row);
        $s = bcrf_quote_subject($pdo, $q['subject_type'], $q['subject_id']);
        if (!$s || !$s['live'] || in_array($s['status'], ['completed', 'no_show'], true) || trim($s['email']) === '') continue;
        $stt = bcrf_quote_status($pdo, $q);
        if ($kind === 'deposit') {
            if ($stt['deposit_left'] <= 0) continue;
        } else {
            if ($stt['deposit_left'] > 0 || $stt['balance'] <= 0 || $s['date'] === '') continue;
            $visit = new DateTimeImmutable($s['date'], $today->getTimezone());
            if ($visit <= $today || $visit > $today->modify('+' . BCRF_BALANCE_REMIND_DAYS . ' days')) continue;   // not yet, or the day has come
        }
        $out[] = ['quote' => $q, 'subject' => $s, 'status' => $stt];
    }
    return $out;
}

/** Active, paid passes ending within 14 days that haven't had the email. */
function bcrf_reminders_passes_due(PDO $pdo, ?DateTimeImmutable $now = null): array {
    if (!bcrf_reminders_ready($pdo)) return [];
    $today = bcrf_reminders_today($now);
    $st = $pdo->prepare("SELECT p.*, t.label FROM passes p JOIN pass_types t ON t.id = p.pass_type_id
                         WHERE p.status = 'active' AND p.payment_status IN ('paid', 'waived') AND p.renewal_reminded_at IS NULL AND p.email <> ''
                           AND p.valid_until BETWEEN ? AND ? ORDER BY p.valid_until, p.id LIMIT 200");
    $st->execute([$today->format('Y-m-d'), $today->modify('+' . BCRF_PASS_REMIND_DAYS . ' days')->format('Y-m-d')]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_reminder_first(string $name): string { return trim(explode(' ', trim($name))[0] ?? ''); }

/** [subject, inner HTML] for a deposit or balance reminder. */
function bcrf_reminder_quote_email(PDO $pdo, string $kind, array $q, array $s, array $stt): array {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $p = 'color:#e8d5b0;margin:0 0 12px';
    $first = bcrf_reminder_first($s['name']);
    $what = strtolower($s['what']);
    $online = bcrf_quote_payable($pdo, $q, $s, $stt)['balance'] > 0;
    $btn = 'display:inline-block;background:#8A3324;color:#F7F1E3;text-decoration:none;font-weight:bold;padding:11px 22px;border-radius:8px;border:2px solid #D9A441';
    if ($kind === 'deposit') {
        $amount = bcrf_quote_money($stt['deposit_left']);
        $due = date('l, F j', strtotime((string)$q['deposit_due']));
        $subject = 'Reminder: your deposit for ' . bcrf_quote_when($s);
        $lead = 'A friendly reminder that the <strong style="color:#f5ead6">' . $h($amount) . ' deposit</strong> for your ' . $h($what) . ' on <strong style="color:#f5ead6">'
              . $h(bcrf_quote_when($s)) . '</strong> is due by ' . $h($due) . '. The deposit holds your date.';
    } else {
        $amount = bcrf_quote_money($stt['balance']);
        $subject = 'Reminder: balance due before your visit on ' . date('F j', strtotime($s['date']));
        $lead = 'We\'re looking forward to your ' . $h($what) . ' on <strong style="color:#f5ead6">' . $h(bcrf_quote_when($s)) . '</strong>! '
              . 'The <strong style="color:#f5ead6">' . $h($amount) . ' balance</strong> is due before the day.';
    }
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;font-size:1.3rem;margin:0 0 14px">' . ($kind === 'deposit' ? 'Your deposit is due soon' : 'Your balance is due soon') . '</h1>'
        . '<p style="' . $p . '">' . ($first !== '' ? 'Hi ' . $h($first) . ', ' : '') . $lead . '</p>'
        . '<p style="margin:0 0 16px"><a style="' . $btn . '" href="' . $h(bcrf_quote_url($q)) . '">' . ($online ? 'See your quote and pay online' : 'See your quote') . '</a></p>'
        . '<p style="' . $p . '">' . ($online ? 'Rather pay another way, or already paid?' : 'To pay, or if you\'ve already paid,') . ' just reply to this email and we\'ll sort it out.</p>'
        . '<p style="' . $p . '">' . $h(bcrf_quote_signoff()) . '</p>'
        . bcrf_email_prefs_footer($pdo, (string)$s['email'], 'this reminder', 'you have a ' . $what . ' booked with us');
    return [$subject, $inner];
}

/** [subject, inner HTML] for the pass-ending-soon email. Never names a price: the passes page has today's. */
function bcrf_reminder_pass_email(PDO $pdo, array $pass): array {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $p = 'color:#e8d5b0;margin:0 0 12px';
    $first = bcrf_reminder_first((string)$pass['holder_name']);
    $ends = date('l, F j, Y', strtotime((string)$pass['valid_until']));
    $btn = 'display:inline-block;background:#8A3324;color:#F7F1E3;text-decoration:none;font-weight:bold;padding:11px 22px;border-radius:8px;border:2px solid #D9A441';
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;font-size:1.3rem;margin:0 0 14px">Your farm pass ends soon</h1>'
        . '<p style="' . $p . '">' . ($first !== '' ? 'Hi ' . $h($first) . ', thank' : 'Thank') . ' you for being a passholder this year! Your <strong style="color:#f5ead6">'
        . $h($pass['label']) . '</strong> is good through <strong style="color:#f5ead6">' . $h($ends) . '</strong>.</p>'
        . '<p style="' . $p . '">Want another year of visits? You can get your next pass on our passes page.</p>'
        . '<p style="margin:0 0 16px"><a style="' . $btn . '" href="' . $h(bcrf_farm_url('/passes/')) . '">See passes</a></p>'
        . '<p style="' . $p . '">Questions? Just reply to this email.</p>'
        . '<p style="' . $p . '">' . $h(bcrf_quote_signoff()) . '</p>'
        . bcrf_email_prefs_footer($pdo, (string)$pass['email'], 'this reminder', 'you have a pass with us');
    return ['Your ' . bcrf_farm_name() . ' pass ends ' . date('F j', strtotime((string)$pass['valid_until'])), $inner];
}

/**
 * What would go out now, without sending or claiming anything: [['kind', 'to', 'subject', 'html'], …].
 * Used by the tests and to show the owner the wording before BCRF_REMINDER_EMAILS_LIVE is turned on.
 */
function bcrf_reminder_preview(PDO $pdo, ?DateTimeImmutable $now = null): array {
    $out = [];
    foreach (['deposit', 'balance'] as $kind) foreach (bcrf_reminders_quotes_due($pdo, $kind, $now) as $d) {
        [$sub, $html] = bcrf_reminder_quote_email($pdo, $kind, $d['quote'], $d['subject'], $d['status']);
        $out[] = ['kind' => $kind, 'to' => $d['subject']['email'], 'subject' => $sub, 'html' => $html];
    }
    foreach (bcrf_reminders_passes_due($pdo, $now) as $pass) {
        [$sub, $html] = bcrf_reminder_pass_email($pdo, $pass);
        $out[] = ['kind' => 'pass', 'to' => $pass['email'], 'subject' => $sub, 'html' => $html];
    }
    return $out;
}

/**
 * Send what's due. $send(to, subject, innerHtml): bool defaults to the booking emails' sender. $live overrides
 * BCRF_REMINDER_EMAILS_LIVE (tests). Returns ['due' => n, 'sent' => n, 'held' => n (not live)].
 */
function bcrf_reminders_run(PDO $pdo, ?DateTimeImmutable $now = null, ?callable $send = null, ?bool $live = null): array {
    $live ??= BCRF_REMINDER_EMAILS_LIVE;
    if (!$send) {
        if (!function_exists('_booking_mail')) require_once __DIR__ . '/booking.php';
        $send = fn($to, $subject, $inner) => _booking_mail($to, $subject, $inner);
    }
    $r = ['due' => 0, 'sent' => 0, 'held' => 0];
    if (!bcrf_reminders_ready($pdo)) return $r;
    $go = function (string $table, string $col, int $id, string $to, array $mail, array $note) use ($pdo, $live, $send, &$r) {
        $r['due']++;
        if (!$live) { $r['held']++; return; }
        $claim = $pdo->prepare("UPDATE `$table` SET `$col` = UTC_TIMESTAMP() WHERE id = ? AND `$col` IS NULL");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) return;   // another run has it
        try {
            if ($send($to, $mail[0], $mail[1])) { $r['sent']++; bcrf_notify($pdo, $note + ['kind' => 'reminder_sent']); }
        } catch (Throwable $e) { error_log('[BCRF] reminder ' . $col . ' for ' . $table . ' ' . $id . ': ' . $e->getMessage()); }
    };
    foreach (['deposit', 'balance'] as $kind) foreach (bcrf_reminders_quotes_due($pdo, $kind, $now) as $d) {
        $q = $d['quote']; $s = $d['subject'];
        $go('event_quotes', $kind . '_reminded_at', $q['id'], (string)$s['email'], bcrf_reminder_quote_email($pdo, $kind, $q, $s, $d['status']),
            ['dedupe' => 'reminder_' . $kind . ':' . $q['id'], 'link' => '/admin/quotes.php?for=' . rawurlencode($q['subject_type'] . ':' . $q['subject_id']),
             'title' => ucfirst($kind) . ' reminder emailed to ' . $s['name'],
             'body' => bcrf_quote_money($kind === 'deposit' ? $d['status']['deposit_left'] : $d['status']['balance']) . ' · ' . $s['what'] . ' · ' . bcrf_quote_when($s)]);
    }
    foreach (bcrf_reminders_passes_due($pdo, $now) as $pass) {
        $go('passes', 'renewal_reminded_at', (int)$pass['id'], (string)$pass['email'], bcrf_reminder_pass_email($pdo, $pass),
            ['dedupe' => 'reminder_pass:' . $pass['id'], 'link' => '/admin/admin.php?view=passes&search=' . rawurlencode((string)$pass['holder_name']),
             'title' => 'Pass renewal reminder emailed to ' . $pass['holder_name'], 'body' => $pass['label'] . ' ends ' . date('M j', strtotime((string)$pass['valid_until']))]);
    }
    return $r;
}

} // function_exists guard
