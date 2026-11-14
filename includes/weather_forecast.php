<?php
// includes/weather_forecast.php — website weather: homepage line, Plan Your Visit 3-day forecast (Nov 14, 2026).
// Source: National Weather Service (api.weather.gov; free, no key, asks for a User-Agent naming the site).
// admin/cron/fetch_weather.php pulls the forecast every 30 minutes and saves it in site_settings 'wx_cache'.
// Pages only read that saved copy: no visitor ever waits on the weather service, and there's no browser
// JavaScript, so the public CSP and the homepage photo load are untouched. Over 3 hours old = not shown.
// The farm's spot comes from Site Info (geo_lat / geo_lng) and the town name from its address, so nothing
// here is specific to one farm.
// The "Closed today for weather" switch lives in includes/site_info.php (site_weather_closed_note()), so the
// header status pill and banner can use it on every page; staff flip it in Weather day (admin/weather.php).

if (!function_exists('bcrf_wx_parse')) {

if (!function_exists('site_setting')) require_once __DIR__ . '/site_info.php';

define('BCRF_WX_MAX_AGE', 10800);   // seconds; older than this, the weather hides itself

/** Read a setting fresh from the database (the admin and the cron job write, then read in the same run). */
function bcrf_wx_setting(PDO $pdo, string $key): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        return (string)$st->fetchColumn();
    } catch (Throwable $e) { return ''; }
}

function bcrf_wx_save_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}

/**
 * NWS forecast JSON → up to 8 periods: name, start, end (ISO 8601 with offset), day (bool), temp (°F),
 * rain (chance %, or null), short ("Partly Sunny"). Empty array if the JSON isn't a usable forecast.
 */
function bcrf_wx_parse($json): array {
    $periods = is_array($json) ? ($json['properties']['periods'] ?? null) : null;
    if (!is_array($periods)) return [];
    $out = [];
    foreach ($periods as $p) {
        if (!is_array($p) || !isset($p['startTime'], $p['endTime'], $p['temperature'])) continue;
        if (strtotime((string)$p['startTime']) === false || strtotime((string)$p['endTime']) === false) continue;
        $t = (float)$p['temperature'];
        if (strtoupper((string)($p['temperatureUnit'] ?? 'F')) === 'C') $t = $t * 9 / 5 + 32;
        $rain = $p['probabilityOfPrecipitation']['value'] ?? null;
        $out[] = [
            'name'  => mb_substr(trim((string)($p['name'] ?? '')), 0, 40),
            'start' => (string)$p['startTime'],
            'end'   => (string)$p['endTime'],
            'day'   => !empty($p['isDaytime']),
            'temp'  => (int)round($t),
            'rain'  => is_numeric($rain) ? (int)$rain : null,
            'short' => mb_substr(trim((string)($p['shortForecast'] ?? '')), 0, 80),
        ];
        if (count($out) >= 8) break;
    }
    return $out;
}

/**
 * Fetch the forecast and save it. $http($url, $headers) returns bcrf_http()'s shape (tests pass a fake).
 * Returns ['ok' => bool, 'msg' => string].
 */
function bcrf_wx_fetch(PDO $pdo, ?callable $http = null): array {
    if ($http === null) {
        require_once __DIR__ . '/adapter_http.php';
        $http = fn(string $url, array $headers) => bcrf_http('GET', $url, ['headers' => $headers, 'timeout' => 15]);
    }
    $lat = trim(bcrf_wx_setting($pdo, 'geo_lat'));
    $lng = trim(bcrf_wx_setting($pdo, 'geo_lng'));
    if (!is_numeric($lat) || !is_numeric($lng)) return ['ok' => false, 'msg' => 'Set the farm\'s map location (latitude and longitude) in Site Info.'];
    $key = round((float)$lat, 4) . ',' . round((float)$lng, 4);   // NWS wants at most 4 decimals

    $site = function_exists('bcrf_farm_domain') ? bcrf_farm_domain() : 'farm website';
    $mail = function_exists('bcrf_farm_email') ? bcrf_farm_email('support') : '';
    $headers = ['User-Agent: (' . $site . ($mail !== '' ? ', ' . $mail : '') . ')', 'Accept: application/geo+json'];

    // Step 1 (cached): which forecast office/grid covers the farm.
    $pts = json_decode(bcrf_wx_setting($pdo, 'wx_points'), true);
    $url = (is_array($pts) && ($pts['key'] ?? '') === $key) ? (string)($pts['url'] ?? '') : '';
    for ($try = 0; $try < 2; $try++) {
        if ($url === '') {
            $r = $http('https://api.weather.gov/points/' . $key, $headers);
            $url = (string)($r['json']['properties']['forecast'] ?? '');
            if (!preg_match('#^https://api\.weather\.gov/gridpoints/[A-Z]{3}/\d+,\d+/forecast$#', $url)) {
                return ['ok' => false, 'msg' => 'Weather service didn\'t find the farm\'s spot (HTTP ' . (int)($r['status'] ?? 0) . ').'];
            }
            bcrf_wx_save_setting($pdo, 'wx_points', json_encode(['key' => $key, 'url' => $url]));
        }
        // Step 2: the forecast itself.
        $r = $http($url, $headers);
        $periods = bcrf_wx_parse($r['json'] ?? null);
        if ($periods) {
            bcrf_wx_save_setting($pdo, 'wx_cache', json_encode(['at' => time(), 'periods' => $periods]));
            return ['ok' => true, 'msg' => count($periods) . ' forecast periods saved.'];
        }
        if ((int)($r['status'] ?? 0) !== 404) break;
        $url = '';   // the grid moved: look it up again once
    }
    return ['ok' => false, 'msg' => 'Weather service didn\'t send a forecast (HTTP ' . (int)($r['status'] ?? 0) . ').'];
}

/** Saved periods that haven't ended yet, or [] if there's no fresh copy. */
function bcrf_wx_periods(?string $cacheJson = null, ?int $now = null): array {
    $now = $now ?? time();
    if ($cacheJson === null) $cacheJson = function_exists('site_setting') ? (string)site_setting('wx_cache') : '';
    $c = json_decode($cacheJson, true);
    if (!is_array($c) || !isset($c['at'], $c['periods']) || !is_array($c['periods'])) return [];
    if ($now - (int)$c['at'] > BCRF_WX_MAX_AGE) return [];
    return array_values(array_filter($c['periods'], fn($p) => is_array($p) && strtotime((string)($p['end'] ?? '')) > $now));
}

/** Farm-local date (Y-m-d) of a period's start. */
function bcrf_wx_date(array $p, string $tz): string {
    return (new DateTimeImmutable((string)$p['start']))->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
}

/**
 * Daytime periods with the following night's low: each ['date','label','high','low' (or null),'short','rain','wet'].
 * label is "Today", "Tomorrow" or the weekday name.
 */
function bcrf_wx_days(array $periods, string $tz, ?int $now = null): array {
    $now = $now ?? time();
    $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    $tomorrow = (new DateTimeImmutable($today . ' 12:00', new DateTimeZone($tz)))->modify('+1 day')->format('Y-m-d');
    $out = [];
    foreach ($periods as $i => $p) {
        if (empty($p['day'])) continue;
        $date = bcrf_wx_date($p, $tz);
        $next = $periods[$i + 1] ?? null;
        $low = ($next && empty($next['day'])) ? (int)$next['temp'] : null;
        $out[] = [
            'date'  => $date,
            'label' => $date === $today ? 'Today' : ($date === $tomorrow ? 'Tomorrow' : (new DateTimeImmutable($date))->format('l')),
            'high'  => (int)$p['temp'],
            'low'   => $low,
            'short' => (string)$p['short'],
            'rain'  => $p['rain'],
            'wet'   => bcrf_wx_is_wet($p),
        ];
    }
    return $out;
}

function bcrf_wx_is_wet(array $p): bool {
    return (($p['rain'] ?? 0) >= 40) || (bool)preg_match('/rain|shower|storm|drizzle|snow|sleet|ice/i', (string)($p['short'] ?? ''));
}

/** One emoji for a short forecast. */
function bcrf_wx_emoji(string $short): string {
    $s = strtolower($short);
    if (str_contains($s, 'thunder')) return '⛈️';
    if (preg_match('/snow|sleet|ice|flurr/', $s)) return '🌨️';
    if (preg_match('/rain|shower|drizzle/', $s)) return '🌧️';
    if (preg_match('/fog|haze|smoke/', $s)) return '🌫️';
    if (preg_match('/partly|mostly sunny|mostly clear/', $s)) return '⛅';
    if (str_contains($s, 'cloudy')) return '☁️';
    if (preg_match('/sunny|clear/', $s)) return '☀️';
    return '🌤️';
}

/** A short, cheerful nudge for the day's weather ('' when nothing needs saying). */
function bcrf_wx_note(array $day): string {
    if ($day['wet']) return 'We\'re open rain or shine, so bring a raincoat and boots!';
    if ($day['high'] <= 45) return 'Bundle up! The animals have their winter coats on too.';
    if ($day['high'] >= 88) return 'Hot one! Come early, and bring water and a hat.';
    if ($day['high'] >= 62 && $day['high'] <= 80 && preg_match('/sunny|clear/i', $day['short'])) return 'Perfect day to visit the animals!';
    return '';
}

/**
 * The day the homepage line talks about: the first forecast day the farm is open and not already closed for
 * the day. $openOn($date, $isToday) says whether the farm is (still) open that day. Null if none.
 */
function bcrf_wx_visit_day(array $days, callable $openOn): ?array {
    foreach ($days as $d) if ($openOn($d['date'], $d['label'] === 'Today')) return $d;
    return null;
}

/** Is the farm open on $date per Site Info hours ($stillOpen: today, and not past closing yet)? */
function bcrf_wx_open_on(string $date, bool $isToday, string $tz): bool {
    if (!function_exists('site_hours') || site_season() === 'closed') return false;
    $dt = new DateTimeImmutable($date . ' 12:00', new DateTimeZone($tz));
    $blocks = site_hours('general')[(int)$dt->format('w')]['blocks'] ?? [];
    if (!$blocks) return false;
    if ($isToday) {
        if (site_weather_closed_note() !== '') return false;
        $nowT = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('H:i:s');
        $last = end($blocks);
        if ($nowT >= (string)$last['close']) return false;
    }
    return true;
}

/** "in Old Fort" (the town from Site Info's address), or "at the farm". */
function bcrf_wx_where(): string {
    $a = function_exists('site_address') ? site_address(true) : [];
    $city = trim((string)($a['city'] ?? ''));
    return $city !== '' ? 'in ' . $city : 'at the farm';
}

/** Homepage: one line for the hours tile, or '' (no fresh forecast, season closed, closed today, …). */
function bcrf_wx_home_html(): string {
    try {
        $tz = function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York';
        $days = bcrf_wx_days(bcrf_wx_periods(), $tz);
        $d = bcrf_wx_visit_day(array_slice($days, 0, 4), fn($date, $isToday) => bcrf_wx_open_on($date, $isToday, $tz));
        if (!$d) return '';
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $note = bcrf_wx_note($d);
        return '<p class="wx-line"><span class="wx-emoji" aria-hidden="true">' . bcrf_wx_emoji($d['short']) . '</span> '
             . $h($d['label']) . ' ' . $h(bcrf_wx_where()) . ': ' . (int)$d['high'] . '°, ' . $h(strtolower($d['short'])) . '.'
             . ($note !== '' ? '<br><span class="wx-note">' . $h($note) . '</span>' : '')
             . ' <a class="wx-more" href="' . $h(bcrf_farm_url('/plan-your-visit/')) . '#weather">3-day forecast<span class="visually-hidden"> ' . $h(bcrf_wx_where()) . '</span></a></p>';
    } catch (Throwable $e) {
        error_log('[weather] home: ' . $e->getMessage());
        return '';
    }
}

/** Plan Your Visit: the 3-day forecast block, or '' when there's no fresh forecast. */
function bcrf_wx_forecast_html(): string {
    try {
        $tz = function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York';
        $days = array_slice(bcrf_wx_days(bcrf_wx_periods(), $tz), 0, 3);
        if (!$days) return '';
        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
        $html = '<div class="visit-info-block wx-forecast" id="weather"><h2>🌤️ Weather ' . $h(bcrf_wx_where()) . '</h2><ul class="wx-days">';
        foreach ($days as $d) {
            $open = bcrf_wx_open_on($d['date'], $d['label'] === 'Today', $tz);
            $html .= '<li class="wx-day"><span class="wx-emoji" aria-hidden="true">' . bcrf_wx_emoji($d['short']) . '</span>'
                   . '<span class="wx-day-text"><strong>' . $h($d['label']) . '</strong>' . ($open ? '' : ' <span class="wx-closed">(we\'re closed)</span>')
                   . '<br>' . $h($d['short']) . '<br>High ' . (int)$d['high'] . '°' . ($d['low'] !== null ? ' · Low ' . (int)$d['low'] . '°' : '') . '</span></li>';
        }
        $html .= '</ul><p class="wx-src">We\'re open rain or shine. Forecast from the National Weather Service.</p></div>';
        return $html;
    } catch (Throwable $e) {
        error_log('[weather] forecast: ' . $e->getMessage());
        return '';
    }
}

}
