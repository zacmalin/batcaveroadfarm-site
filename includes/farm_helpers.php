<?php
// includes/farm_helpers.php — one farm time zone, one "today on the farm" and one money format (Nov 18, 2026 cleanup).
// Before, about 13 files each kept their own copy (bcrf_cal_tz, bcrf_rpt_tz, bcrf_doc_today, bcrf_quote_money, bcrf_gc_money…);
// those names still work and now call these. Kept apart from farm.php so tests that stub farm values can still load it.
if (!function_exists('bcrf_tz')) {

/** The farm's time zone (Settings, via config.php's bcrf_farm_tz()). The one fallback lives here (Nov 18, 2026). */
function bcrf_tz(): DateTimeZone {
    try { return new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York'); }
    catch (Throwable $e) { return new DateTimeZone('America/New_York'); }
}

/** Today on the farm, Y-m-d. */
function bcrf_today(): string {
    return (new DateTimeImmutable('now', bcrf_tz()))->format('Y-m-d');
}

/** Cents as money for people: 1234 -> "$12.34", -500 -> "-$5.00". */
function bcrf_money(int $cents): string {
    return ($cents < 0 ? '-' : '') . '$' . number_format(abs($cents) / 100, 2);
}

}
