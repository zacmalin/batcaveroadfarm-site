<?php // admin/cron/fetch_weather.php
/*
 * Website weather (Nov 14, 2026): saves the National Weather Service forecast for the farm's spot (Site Info map
 * location) so the homepage line and the Plan Your Visit 3-day forecast never wait on the weather service.
 * Rules and display: includes/weather_forecast.php. Started by the Farm OS runner every 30 minutes.
 * A failed fetch only counts as a failed run once the saved forecast is too old to show (3 hours), so a short
 * weather-service hiccup doesn't raise alarms.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden'); }

ob_start();
$bcrf_cfg = null;
foreach ([__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../../../config.php'] as $cand) {
    if (is_file($cand)) { $bcrf_cfg = $cand; break; }
}
if (!$bcrf_cfg) { fwrite(STDERR, "fetch_weather: config.php not found\n"); exit(1); }
require_once $bcrf_cfg;
require_once dirname($bcrf_cfg) . '/includes/weather_forecast.php';
if (ob_get_level() > 0) { ob_end_clean(); }
require_once dirname($bcrf_cfg) . '/includes/cron_health.php';
bcrf_cron_begin('fetch_weather');

try {
    $r = bcrf_wx_fetch($pdo);
    if (!$r['ok']) error_log('[weather] fetch: ' . $r['msg']);   // shows on System Health and in the PHP error log
    if (!$r['ok'] && !bcrf_wx_periods(bcrf_wx_setting($pdo, 'wx_cache'))) bcrf_cron_exit(1, $r['msg']);
    if (!$r['ok']) fwrite(STDERR, 'fetch_weather: ' . $r['msg'] . " (keeping the last forecast)\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'fetch_weather: ' . $e->getMessage() . "\n");
    bcrf_cron_exit(1, $e->getMessage());
}
bcrf_cron_finish();
