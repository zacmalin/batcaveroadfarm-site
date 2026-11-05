<?php
// includes/farm.php — the farm's own values in one place (Farm OS Phase 0, Nov 5, 2026).
//
// Code asks here for the farm's name, web address and sender email addresses instead of writing
// them in. Values come from Settings (site_settings, edited in admin → Settings → Hours & Contacts),
// then the tenant config file (bcrf-secrets.php, loaded by config.php), then the fallbacks below.
// The fallbacks are this install's current values; once they're saved in Settings (Site Info → Save)
// the code no longer depends on them, and a second farm's onboarding (Phase 9) fills Settings instead.

if (!function_exists('bcrf_farm')) {

/** Setting keys this file knows, with the tenant-config key and the fallback for each. */
function bcrf_farm_keys(): array {
    return [
        //  setting key        tenant config key     fallback (this install)
        'business_name'    => ['BUSINESS_NAME',    'Bat Cave Road Farm'],
        'site_url'         => ['SITE_URL',         'https://batcaveroadfarm.com'],
        'email_support'    => ['EMAIL_SUPPORT',    'support@batcaveroadfarm.com'],
        'email_noreply'    => ['EMAIL_NOREPLY',    'no-reply@batcaveroadfarm.com'],
        'email_jobs'       => ['EMAIL_JOBS',       'jobs@batcaveroadfarm.com'],
        'email_newsletter' => ['EMAIL_NEWSLETTER', 'newsletter@batcaveroadfarm.com'],
        'meta_app_id'      => ['META_APP_ID',      '4401281856803313'],   // public app id (share previews)
    ];
}

/** One farm value: Settings → tenant config → fallback. */
function bcrf_farm(string $key): string {
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        global $pdo;
        try {
            if ($pdo instanceof PDO) {
                $in = implode(',', array_fill(0, count(bcrf_farm_keys()), '?'));
                $st = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
                $st->execute(array_keys(bcrf_farm_keys()));
                foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $v]) $settings[$k] = trim((string)$v);
            }
        } catch (Throwable $e) { /* settings table missing: fall through */ }
    }
    if (($settings[$key] ?? '') !== '') return $settings[$key];
    [$cfgKey, $fallback] = bcrf_farm_keys()[$key] ?? ['', ''];
    $cfg = $GLOBALS['bcrf'][$cfgKey] ?? (defined($cfgKey) ? constant($cfgKey) : '');
    if (is_string($cfg) && trim($cfg) !== '') return trim($cfg);
    if ($key === 'site_url' && defined('BCRF_SOCIAL_BASE_URL')) return (string)BCRF_SOCIAL_BASE_URL;   // older name for the same value
    return $fallback;
}

/** Full URL on the farm's site: bcrf_farm_url('/tickets/') → https://example.com/tickets/ */
function bcrf_farm_url(string $path = ''): string {
    $base = rtrim(bcrf_farm('site_url'), '/');
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

/** Bare domain of the farm's site (for Message-IDs and the like). */
function bcrf_farm_domain(): string {
    return (string)(parse_url(bcrf_farm('site_url'), PHP_URL_HOST) ?: 'localhost');
}

/** Sender address by role: support | noreply | jobs | newsletter. */
function bcrf_farm_email(string $role = 'support'): string {
    return bcrf_farm('email_' . $role);
}

/** Business name for emails, receipts and page titles. */
function bcrf_farm_name(): string {
    return bcrf_farm('business_name');
}

}
