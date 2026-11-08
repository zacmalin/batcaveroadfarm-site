<?php
// Roost Rewards service-email content. Pure renderers only: no sending or enrollment.
// All delivery must use existing bcf_send_mail() shared branded email wrapper.
require_once __DIR__ . '/roost-expiration-preview.php';

function bcrf_roost_email_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Returns content for the existing bcf_send_mail($to,$subject,$innerHtml,$opts).
 * Caller must verify the recipient and durable enrollment before queueing.
 */
function bcrf_roost_welcome_email_content(string $dashboardUrl): array {
    if (!filter_var($dashboardUrl, FILTER_VALIDATE_URL) || !str_starts_with($dashboardUrl, 'https://')) {
        throw new BcrfRewardError('Secure rewards dashboard URL required.');
    }
    $link = bcrf_roost_email_escape($dashboardUrl);
    $html = '<h1 style="color:#e8b84b;">Welcome to Roost Rewards!</h1>'
        . '<p>Thanks for joining Bat Cave Road Farm’s rewards program.</p>'
        . '<p>Earn 5 whole points for every eligible dollar spent (rounded down per qualifying sale, with no carryover), and 25 points for each eligible verified farm visit.</p>'
        . '<p>Points are shared with your verified household. Qualifying purchases and verified visits reset the 12-month inactivity clock.</p>'
        . '<p><a href="'.$link.'" style="color:#e8b84b;">View your Rewards account</a> for your balance, available benefits and account details.</p>';
    return [
        'subject'=>'Welcome to Roost Rewards',
        'html'=>$html,
        'opts'=>['subtitle'=>'Roost Rewards', 'unsubscribe'=>false,
            'text'=>"Welcome to Roost Rewards! Earn 5 whole points per eligible dollar, rounded down per sale, and 25 points per eligible verified visit. Your verified household shares points. Qualifying purchases and verified visits reset the 12-month inactivity clock. View your account: $dashboardUrl"],
    ];
}

/**
 * Content only. The caller must recheck current household balance, last activity,
 * verification and recipient authorization before queueing AND before sending.
 */
function bcrf_roost_expiration_email_content(int $days, int $balance, string $expirationUtc, string $dashboardUrl): array {
    if (!in_array($days, [60, 30, 7], true) || $balance <= 0) {
        throw new BcrfRewardError('Invalid reminder milestone or balance.');
    }
    if (!filter_var($dashboardUrl, FILTER_VALIDATE_URL) || !str_starts_with($dashboardUrl, 'https://')) {
        throw new BcrfRewardError('Secure rewards dashboard URL required.');
    }
    $tz = new DateTimeZone('UTC');
    $expiration = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $expirationUtc, $tz);
    if ($expiration === false) $expiration = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expirationUtc, $tz);
    if ($expiration === false) throw new BcrfRewardError('Invalid UTC expiration timestamp.');
    $when = $expiration->format('F j, Y \\a\\t g:i A') . ' UTC';
    $safeDate = bcrf_roost_email_escape($when);
    $safeLink = bcrf_roost_email_escape($dashboardUrl);
    $html = '<h1 style="color:#e8b84b;">Your Roost Rewards points may expire soon</h1>'
        . '<p>You currently have <strong>'.number_format($balance).' points</strong>. Based on your last qualifying activity, these points are scheduled to expire on <strong>'.$safeDate.'</strong> unless your household has a qualifying purchase or verified farm visit before then.</p>'
        . '<p><a href="'.$safeLink.'" style="color:#e8b84b;">View your Rewards account</a> for current details. A redemption or newsletter click does not reset the inactivity clock.</p>';
    return [
        'subject'=>"Roost Rewards: $days days until points expiration",
        'html'=>$html,
        'opts'=>['subtitle'=>'Roost Rewards', 'unsubscribe'=>false,
            'text'=>"Roost Rewards: $balance points may expire on $when unless your household has a qualifying purchase or verified visit. A redemption or newsletter click does not reset inactivity. Review your account: $dashboardUrl"],
    ];
}
