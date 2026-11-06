<?php
// includes/ga.php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
// Google Analytics 4 helpers (Nov 2026, task "GA4 & Search Console: privacy, conversions, admin reports").
//
//   bcrf_ga_is_staff()            signed-in staff → page gets data-ga-internal="1" → traffic_type=internal
//   bcrf_ga_flash($name, $form)   queue a one-time event (generate_lead, sign_up…) for the NEXT page view,
//                                 so a redirect after a form still records it, and bots that skip the
//                                 real success path don't
//   bcrf_ga_body_attrs()          the data-ga-* attributes for <body> (used by includes/header.php)
//   bcrf_ga_tags_html()           GA script tags for standalone pages that don't use includes/head.php
//   bcrf_ga_capture_ids()         read the visitor's GA client and session IDs from their cookies
//                                 (stored on the booking/pass at checkout, so the purchase is credited
//                                 to the visit's real source)
//   bcrf_utm_url() / bcrf_utm_html()  campaign tags on links in newsletters, job alerts and social posts
//   bcrf_ga_purchase($pdo, ...)   send a GA4 "purchase" from the server (Measurement Protocol) once per
//                                 paid booking or pass; called from the Stripe webhook and the success
//                                 pages' fallback. Needs GA4_API_SECRET in bcrf-secrets.php.
//
// Browser side: js/min.gtag.js removes emails and tokens from page addresses before GA sees them.

if (!function_exists('bcrf_ga_measurement_id')) {

function bcrf_ga_measurement_id(): string {
    return (string)($GLOBALS['bcrf']['GA4_MEASUREMENT_ID'] ?? 'G-DLB6LEFWQZ');
}
function bcrf_ga_api_secret(): string {
    return (string)(getenv('GA4_API_SECRET') ?: ($GLOBALS['bcrf']['GA4_API_SECRET'] ?? ''));
}

/** Signed-in staff (any console role). Public 'user' accounts are customers, not internal traffic. */
function bcrf_ga_is_staff(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    return in_array((string)($_SESSION['user_role'] ?? ''), ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'], true);
}

/** Queue one event for the next page view. Event names: GA4 recommended (generate_lead, sign_up) or custom. */
function bcrf_ga_flash(string $name, string $form = ''): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION['bcrf_ga_flash'] = ['name' => preg_replace('/[^a-z0-9_]/', '', strtolower($name)), 'form' => preg_replace('/[^a-z0-9_]/', '', strtolower($form))];
}

/** data-ga-* attributes for <body>: a queued event (once), else the page's own $gaEvent/$gaForm. */
function bcrf_ga_body_attrs(string $event = '', string $form = ''): string {
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['bcrf_ga_flash']['name'])) {
        $event = $_SESSION['bcrf_ga_flash']['name'];
        $form  = $_SESSION['bcrf_ga_flash']['form'] ?? '';
        unset($_SESSION['bcrf_ga_flash']);
    }
    $out = '';
    if ($event !== '') $out .= ' data-ga-event="' . htmlspecialchars($event, ENT_QUOTES) . '"';
    if ($event !== '' && $form !== '') $out .= ' data-ga-form="' . htmlspecialchars($form, ENT_QUOTES) . '"';
    return $out;
}

/** <html> attribute that marks staff visits as internal traffic. */
function bcrf_ga_html_attrs(): string {
    return bcrf_ga_is_staff() ? ' data-ga-internal="1"' : '';
}

/** Script tags for standalone pages (their own <head>). Same files send-message.php uses. */
function bcrf_ga_tags_html(): string {
    $v = function_exists('bcrf_asset_v') ? bcrf_asset_v('/js/min.gtag.js') : '1';
    $va = function_exists('bcrf_asset_v') ? bcrf_asset_v('/js/min.analytics.js') : '1';
    return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . htmlspecialchars(bcrf_ga_measurement_id(), ENT_QUOTES) . '"></script>' . "\n"
         . ('<script defer src="' . bcrf_farm_url() . '/js/min.gtag.js?v=') . htmlspecialchars($v, ENT_QUOTES) . '"></script>' . "\n"
         . ('<script defer src="' . bcrf_farm_url() . '/js/min.analytics.js?v=') . htmlspecialchars($va, ENT_QUOTES) . '"></script>' . "\n";
}

/**
 * GA client ID and session ID from the visitor's cookies.
 * _ga = "GA1.1.123456789.1700000000" -> client_id "123456789.1700000000"
 * _ga_<ID> = "GS1.1.1700000000.3.1..." or "GS2.1.s1700000000$o3$g1..." -> session_id "1700000000"
 */
function bcrf_ga_capture_ids(): array {
    $cid = '';
    if (preg_match('/^GA\d\.\d\.(\d+\.\d+)$/', (string)($_COOKIE['_ga'] ?? ''), $m)) $cid = $m[1];
    $sid = '';
    $ck = '_ga_' . substr(bcrf_ga_measurement_id(), 2);
    $raw = (string)($_COOKIE[$ck] ?? '');
    if (preg_match('/^GS1\.\d\.(\d+)\./', $raw, $m) || preg_match('/^GS2\.\d\.s(\d+)/', $raw, $m)) $sid = $m[1];
    return ['cid' => $cid, 'sid' => $sid];
}

/** Columns that hold GA IDs and the "purchase sent" marker. Added on first use (Phase 0 moves this to a migration). */
function bcrf_ga_schema(PDO $pdo, string $table): void {
    static $done = [];
    if (isset($done[$table]) || !in_array($table, ['bookings', 'passes', 'tickets'], true)) return;
    $done[$table] = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('ga_client_id', $cols, true))    $pdo->exec("ALTER TABLE `$table` ADD COLUMN ga_client_id VARCHAR(40) NULL DEFAULT NULL");
        if (!in_array('ga_session_id', $cols, true))   $pdo->exec("ALTER TABLE `$table` ADD COLUMN ga_session_id VARCHAR(20) NULL DEFAULT NULL");
        if (!in_array('ga_purchase_sent', $cols, true)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN ga_purchase_sent DATETIME NULL DEFAULT NULL");
    } catch (Throwable $e) {
        error_log('[ga] schema ' . $table . ': ' . $e->getMessage());
    }
}

/** Store the visitor's GA IDs on a booking or pass at checkout. Never fails the checkout. */
function bcrf_ga_store_ids(PDO $pdo, string $table, int $id): void {
    $ids = bcrf_ga_capture_ids();
    if ($ids['cid'] === '' || $id <= 0) return;
    bcrf_ga_schema($pdo, $table);
    try {
        $pdo->prepare("UPDATE `$table` SET ga_client_id = ?, ga_session_id = ? WHERE id = ?")
            ->execute([$ids['cid'], $ids['sid'] !== '' ? $ids['sid'] : null, $id]);
    } catch (Throwable $e) { error_log('[ga] store ids ' . $table . ' ' . $id . ': ' . $e->getMessage()); }
}

/**
 * Send a GA4 purchase for a paid booking ('bookings') or pass ('passes'), once.
 * $amountCents: the paid amount if known (Stripe amount_total); otherwise read from the record.
 * Safe to call more than once and from several places: the ga_purchase_sent claim makes it send at most once.
 */
function bcrf_ga_purchase(PDO $pdo, string $table, int $id, ?int $amountCents = null): void {
    $secret = bcrf_ga_api_secret();
    if ($secret === '' || $id <= 0 || !in_array($table, ['bookings', 'passes', 'tickets'], true)) return;   // tickets: online admission (Farm OS 4b)
    bcrf_ga_schema($pdo, $table);
    try {
        // Claim it first so two callers (webhook + success page) can't both send.
        $claim = $pdo->prepare("UPDATE `$table` SET ga_purchase_sent = UTC_TIMESTAMP() WHERE id = ? AND ga_purchase_sent IS NULL AND payment_status = 'paid'");
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) return;

        if ($table === 'tickets') {
            $st = $pdo->prepare("SELECT id, amount_cents / 100 AS amount_paid, ga_client_id, ga_session_id, 'Admission tickets' AS label FROM tickets WHERE id = ?");
        } elseif ($table === 'bookings') {
            $st = $pdo->prepare("SELECT b.id, b.amount_total, b.party_size, b.ga_client_id, b.ga_session_id, t.type_key, t.label
                                 FROM bookings b LEFT JOIN booking_types t ON t.id = b.type_id WHERE b.id = ?");
        } else {
            $st = $pdo->prepare("SELECT p.id, p.amount_paid, p.ga_client_id, p.ga_session_id, t.label
                                 FROM passes p LEFT JOIN pass_types t ON t.id = p.pass_type_id WHERE p.id = ?");
        }
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return;

        $value = $amountCents !== null ? $amountCents / 100 : (float)($table === 'bookings' ? $r['amount_total'] : $r['amount_paid']);
        if ($value <= 0) return;
        $isTicket = $table === 'tickets' || ($table === 'bookings' && ($r['type_key'] ?? '') === 'day_admission');
        $item = [
            'item_id'       => $table === 'passes' ? 'pass' : ($isTicket ? 'admission' : 'booking-' . (($r['type_key'] ?? '') ?: 'other')),
            'item_name'     => (string)($r['label'] ?: ($table === 'passes' ? 'Annual pass' : 'Booking')),
            'item_category' => $table === 'passes' ? 'Annual passes' : ($isTicket ? 'Admission tickets' : 'Bookings'),
            'price'         => round($value, 2),
            'quantity'      => 1,
        ];
        $params = [
            'transaction_id' => ($table === 'passes' ? 'P-' : ($table === 'tickets' ? 'T-' : 'B-')) . $r['id'],
            'currency'       => 'USD',
            'value'          => round($value, 2),
            'items'          => [$item],
            'engagement_time_msec' => 1,
        ];
        if (!empty($r['ga_session_id'])) $params['session_id'] = (string)$r['ga_session_id'];
        // No GA cookie (blocked or cleared): still count the sale under a server-made ID.
        $cid = !empty($r['ga_client_id']) ? (string)$r['ga_client_id'] : (random_int(100000000, 999999999) . '.' . time());

        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode(bcrf_ga_measurement_id()) . '&api_secret=' . rawurlencode($secret);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['client_id' => $cid, 'events' => [['name' => 'purchase', 'params' => $params]]]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code < 200 || $code >= 300) {
            error_log('[ga] purchase ' . $params['transaction_id'] . ' HTTP ' . $code . ' ' . curl_error($ch));
            // Release the claim so a later call can retry.
            $pdo->prepare("UPDATE `$table` SET ga_purchase_sent = NULL WHERE id = ?")->execute([$id]);
        }
    } catch (Throwable $e) {
        error_log('[ga] purchase ' . $table . ' ' . $id . ': ' . $e->getMessage());
    }
}

/**
 * Add campaign (UTM) tags to a link to our own site, so GA shows where the visit came from.
 * Leaves other sites alone, and private links (unsubscribe, confirm, pass, account) untouched.
 */
function bcrf_utm_url(string $url, string $source, string $medium, string $campaign): string {
    if (!preg_match('#^https?://(www\.)?batcaveroadfarm\.com(/|$)#i', $url)) return $url;
    if (preg_match('#/(unsubscribe\.php|subscribe-confirm\.php|passes/verify\.php|passes/success\.php|account/|jobs/alerts/)#i', $url)) return $url;
    if (preg_match('/[?&]utm_source=/', $url)) return $url;
    $frag = '';
    if (($h = strpos($url, '#')) !== false) { $frag = substr($url, $h); $url = substr($url, 0, $h); }
    $q = http_build_query(['utm_source' => $source, 'utm_medium' => $medium,
                           'utm_campaign' => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($campaign)), '-') ?: 'general']);
    return $url . (strpos($url, '?') === false ? '?' : '&') . $q . $frag;
}

/** Same, for every href="…" in an HTML email body (links may be &amp;-escaped). */
function bcrf_utm_html(string $html, string $source, string $medium, string $campaign): string {
    return preg_replace_callback('/href=(["\'])(https?:\/\/(?:www\.)?batcaveroadfarm\.com[^"\']*)\1/i', function ($m) use ($source, $medium, $campaign) {
        $raw = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
        return 'href=' . $m[1] . htmlspecialchars(bcrf_utm_url($raw, $source, $medium, $campaign), ENT_QUOTES) . $m[1];
    }, $html);
}

}
