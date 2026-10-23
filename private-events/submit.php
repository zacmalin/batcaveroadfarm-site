<?php
// private-events/submit.php — handles private event inquiry form submission.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';
if (is_file(__DIR__ . '/../admin/includes/email_template.php')) {
    require_once __DIR__ . '/../admin/includes/email_template.php';
}

function _pe_bail(string $code): void {
    header('Location: /private-events/?error=' . rawurlencode($code)); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /private-events/'); exit;
}

// Honeypot
if (!empty($_POST['website'])) { header('Location: /private-events/success.php'); exit; }

// CSRF
$csrf = trim((string)($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || $csrf === '' || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    _pe_bail('csrf');
}

// Validate
$name       = trim(strip_tags((string)($_POST['customer_name'] ?? '')));
$email      = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$eventType  = trim(preg_replace('/[^a-z_]/', '', (string)($_POST['event_type'] ?? '')));
$phone      = preg_replace('/[^0-9+\-() ]/', '', (string)($_POST['phone'] ?? ''));
$prefDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['preferred_date'] ?? '') ? $_POST['preferred_date'] : null;
$flexible   = !empty($_POST['date_flexible']) ? 1 : 0;
$adults     = max(0, (int)($_POST['adult_count'] ?? 0));
$children   = max(0, (int)($_POST['child_count'] ?? 0));
$bday       = (int)($_POST['birthday_age'] ?? 0) > 0 ? (int)$_POST['birthday_age'] : null;
$addons     = array_values(array_filter((array)($_POST['addons'] ?? []), fn($v) => preg_match('/^[a-z_]+$/', $v)));
$special    = trim(strip_tags((string)($_POST['special_requests'] ?? '')));
$heardFrom  = trim(strip_tags((string)($_POST['heard_from'] ?? '')));

if ($name === '')  _pe_bail('name');
if (!$email)       _pe_bail('email');
if ($eventType === '') _pe_bail('type');
if ($adults + $children < 1 && $special === '') _pe_bail('guests');

$eventLabels = [
    'birthday'   => 'Birthday Party',
    'field_trip' => 'School / Group Field Trip',
    'buyout'     => 'Private Farm Buyout',
    'corporate'  => 'Corporate / Team Event',
    'other'      => 'Other',
];
$addonLabels = [
    'guided_tour'    => 'Guided animal tour',
    'extended_time'  => 'Extended visit time',
    'custom_signage' => 'Custom signage / banner',
    'feeding_bags'   => 'Animal feeding bags for all guests',
    'private_barn'   => 'Private barn access',
    'photographer'   => 'Farm photographer',
];

// Insert into DB
try {
    $pdo->prepare("
        INSERT INTO event_inquiries
            (event_type, preferred_date, date_flexible, adult_count, child_count,
             birthday_age, addons, special_requests, heard_from,
             customer_name, email, phone)
        VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?)
    ")->execute([
        $eventType, $prefDate, $flexible, $adults, $children,
        $bday,
        !empty($addons) ? json_encode(array_map(fn($a) => $addonLabels[$a] ?? $a, $addons)) : null,
        $special !== '' ? $special : null,
        $heardFrom !== '' ? $heardFrom : null,
        $name, $email, $phone !== '' ? $phone : null,
    ]);
} catch (Throwable $e) {
    error_log('[BCRF private-events/submit] DB error: ' . $e->getMessage());
    _pe_bail('db');
}

// ── Emails ────────────────────────────────────────────────────────────────────
$typeLabel    = $eventLabels[$eventType] ?? $eventType;
$totalGuests  = $adults + $children;
$dateStr      = $prefDate ? date('D, M j Y', strtotime($prefDate)) . ($flexible ? ' (flexible)' : '') : 'Not specified (flexible)';
$addonStr     = !empty($addons) ? implode(', ', array_map(fn($a) => $addonLabels[$a] ?? $a, $addons)) : 'None selected';

// Farm notification
if (function_exists('bcf_send_mail')) {
    $rows = fn(array $pairs) => implode('', array_map(
        fn($l, $v) => '<tr><td style="padding:4px 16px 4px 0;color:#e8b84b;font-weight:bold;white-space:nowrap">' . $l . '</td><td style="padding:4px 0">' . $v . '</td></tr>',
        array_keys($pairs), $pairs
    ));

    $details = [
        'Event type'    => htmlspecialchars($typeLabel),
        'Preferred date'=> htmlspecialchars($dateStr),
        'Adults'        => $adults,
        'Children'      => $children,
        'Total guests'  => $totalGuests,
    ];
    if ($bday) $details['Birthday child age'] = $bday;
    $details['Add-ons requested'] = htmlspecialchars($addonStr);
    if ($special !== '') $details['Special requests'] = '<span style="white-space:pre-wrap">' . htmlspecialchars($special) . '</span>';
    if ($heardFrom !== '') $details['Heard from'] = htmlspecialchars($heardFrom);
    $details['Contact'] = htmlspecialchars($email) . ($phone ? ' · ' . htmlspecialchars($phone) : '');

    $notifInner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.4rem;margin:0 0 16px">New private event inquiry from ' . htmlspecialchars($name) . '</h1>'
        . '<table style="border-collapse:collapse;margin:0 0 16px">' . $rows($details) . '</table>';

    @bcf_send_mail('support@batcaveroadfarm.com',
        'Private event inquiry — ' . $typeLabel . ' from ' . $name,
        $notifInner,
        ['subtitle' => 'Private Event Inquiry', 'from_email' => 'support@batcaveroadfarm.com', 'reply_to' => $email]
    );

    // Auto-reply to visitor
    $replyInner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.4rem;margin:0 0 14px">Thanks for reaching out, ' . htmlspecialchars($name) . '! 🎉</h1>'
        . '<p style="margin:0 0 14px">We\'ve received your inquiry for a <strong>' . htmlspecialchars($typeLabel) . '</strong>'
        . ($prefDate ? ' on <strong>' . htmlspecialchars($dateStr) . '</strong>' : '') . '. We\'re excited to hear about your plans!</p>'
        . '<p style="margin:0 0 14px">A member of our team will be in touch within 24 hours to discuss your event and put together a custom package.</p>'
        . '<p style="margin:0;font-size:.9rem;color:rgba(245,234,214,.8)">In the meantime, feel free to call us at (828) 216-5488 if you have any questions. We\'re open Tuesday – Sunday, 10AM – 4PM.</p>';

    @bcf_send_mail($email,
        'We received your private event inquiry — Bat Cave Road Farm',
        $replyInner,
        ['subtitle' => 'Event Inquiry Received', 'from_email' => 'support@batcaveroadfarm.com']
    );
}

header('Location: /private-events/success.php'); exit;
