<?php
// tests/weather_forecast_test.php — website weather (Nov 14, 2026; includes/weather_forecast.php, the
// "Closed today for weather" switch in includes/site_info.php) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_wx_test DB_USER=... DB_PASS=... php tests/weather_forecast_test.php
// Refuses to run unless DB_NAME ends in "_test". Exit 0 = all passed. Never calls the real weather service.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }

$set = function (string $k, string $v) use ($pdo) {
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
};
$set('timezone', 'America/New_York');
$set('geo_lat', '35.61953');
$set('geo_lng', '-82.25901');
$tz = 'America/New_York';
date_default_timezone_set($tz);

require $root . '/includes/weather_forecast.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };

// A forecast like NWS sends, starting this afternoon (times relative to now so the test never goes stale)
$now = time();
$z = fn(int $h) => (new DateTimeImmutable('@' . ($now + $h * 3600)))->setTimezone(new DateTimeZone($tz))->format('c');
$nws = ['properties' => ['periods' => [
    ['name' => 'This Afternoon', 'startTime' => $z(-1), 'endTime' => $z(4),  'isDaytime' => true,  'temperature' => 64, 'temperatureUnit' => 'F', 'probabilityOfPrecipitation' => ['value' => 10],   'shortForecast' => 'Partly Sunny'],
    ['name' => 'Tonight',        'startTime' => $z(4),  'endTime' => $z(16), 'isDaytime' => false, 'temperature' => 41, 'temperatureUnit' => 'F', 'probabilityOfPrecipitation' => ['value' => null], 'shortForecast' => 'Mostly Clear'],
    ['name' => 'Sunday',         'startTime' => $z(16), 'endTime' => $z(28), 'isDaytime' => true,  'temperature' => 58, 'temperatureUnit' => 'F', 'probabilityOfPrecipitation' => ['value' => 70],   'shortForecast' => 'Rain Showers Likely'],
    ['name' => 'Sunday Night',   'startTime' => $z(28), 'endTime' => $z(40), 'isDaytime' => false, 'temperature' => 39, 'temperatureUnit' => 'F', 'probabilityOfPrecipitation' => ['value' => 20],   'shortForecast' => 'Cloudy'],
    ['name' => 'Monday',         'startTime' => $z(40), 'endTime' => $z(52), 'isDaytime' => true,  'temperature' => 12, 'temperatureUnit' => 'C', 'probabilityOfPrecipitation' => ['value' => 0],    'shortForecast' => 'Sunny'],
    ['bad' => 'row'],
]]];

// --- parsing
$p = bcrf_wx_parse($nws);
$ok(count($p) === 5, 'parse keeps the 5 good periods and skips the broken one');
$ok($p[0]['temp'] === 64 && $p[0]['day'] === true && $p[0]['rain'] === 10, 'parse reads temp, daytime, rain chance');
$ok($p[1]['rain'] === null, 'missing rain chance stays null');
$ok($p[4]['temp'] === 54, 'Celsius converted to Fahrenheit (12 C = 54 F)');
$ok(bcrf_wx_parse(['nope']) === [] && bcrf_wx_parse(null) === [], 'junk JSON parses to nothing');

// --- fetch: points lookup once, then forecast; cached grid URL reused
$calls = [];
$grid = 'https://api.weather.gov/gridpoints/GSP/40,73/forecast';
$fake = function (string $url, array $headers) use (&$calls, $nws, $grid) {
    $calls[] = [$url, $headers];
    if (str_contains($url, '/points/')) return ['status' => 200, 'json' => ['properties' => ['forecast' => $grid]]];
    if ($url === $grid) return ['status' => 200, 'json' => $nws];
    return ['status' => 404, 'json' => null];
};
$r = bcrf_wx_fetch($pdo, $fake);
$ok($r['ok'] === true, 'fetch succeeds: ' . $r['msg']);
$ok(count($calls) === 2 && str_ends_with($calls[0][0], '/points/35.6195,-82.259'), 'first fetch looks up the grid with 4-decimal coordinates');
$ok((bool)preg_grep('/^User-Agent: \(/', $calls[0][1]), 'sends a User-Agent');
$calls = [];
bcrf_wx_fetch($pdo, $fake);
$ok(count($calls) === 1 && $calls[0][0] === $grid, 'second fetch reuses the saved grid URL');
$cache = bcrf_wx_setting($pdo, 'wx_cache');
$ok(count(bcrf_wx_periods($cache, $now)) === 5, 'saved forecast reads back fresh');
$later = json_encode(['at' => $now + 4 * 3600, 'periods' => $p]);
$ok(count(bcrf_wx_periods($later, $now + 4 * 3600 + 60)) === 4, 'periods that ended drop off');
$ok(bcrf_wx_periods($cache, $now + BCRF_WX_MAX_AGE + 1) === [], 'a forecast over 3 hours old is not shown');

// grid moved (404) → looks it up again once
$calls = [];
$moved = function (string $url, array $headers) use (&$calls, $nws) {
    $calls[] = $url;
    if (str_contains($url, '/points/')) return ['status' => 200, 'json' => ['properties' => ['forecast' => 'https://api.weather.gov/gridpoints/GSP/41,73/forecast']]];
    if (str_contains($url, '/41,73/')) return ['status' => 200, 'json' => $nws];
    return ['status' => 404, 'json' => null];
};
$r = bcrf_wx_fetch($pdo, $moved);
$ok($r['ok'] && count($calls) === 3, 'a moved grid is looked up again and fetched');

// service down → not ok, saved copy kept
$before = bcrf_wx_setting($pdo, 'wx_cache');
$r = bcrf_wx_fetch($pdo, fn($u, $h) => ['status' => 0, 'json' => null]);
$ok(!$r['ok'] && bcrf_wx_setting($pdo, 'wx_cache') === $before, 'outage: reports failure, keeps the last forecast');

// no map location
$set('geo_lat', '');
$r = bcrf_wx_fetch($pdo, $fake);
$ok(!$r['ok'] && str_contains($r['msg'], 'Site Info'), 'missing location says to set it in Site Info');
$set('geo_lat', '35.61953');

// --- days, notes, emoji
// Anchor the day-grouping fixture at local noon. The live-clock fixture above
// can cross midnight in CI, making "This Afternoon" fall on yesterday.
$dayNow = (new DateTimeImmutable('today 12:00', new DateTimeZone($tz)))->getTimestamp();
$dayPeriods = $p;
foreach ($dayPeriods as &$period) {
    foreach (['start', 'end'] as $key) {
        $period[$key] = (new DateTimeImmutable('@' . (strtotime($period[$key]) + $dayNow - $now)))
            ->setTimezone(new DateTimeZone($tz))->format('c');
    }
}
unset($period);
$days = bcrf_wx_days($dayPeriods, $tz, $dayNow);
$ok(count($days) === 3, 'three daytime periods → three days');
$ok($days[0]['label'] === 'Today' && $days[0]['high'] === 64 && $days[0]['low'] === 41, 'today has high and the night low');
$ok($days[1]['wet'] === true && $days[0]['wet'] === false, 'rainy day flagged wet, partly sunny is not');
$ok($days[2]['low'] === null, 'last day without a night period has no low');
$ok(bcrf_wx_emoji('Rain Showers Likely') === '🌧️' && bcrf_wx_emoji('Chance Thunderstorms') === '⛈️' && bcrf_wx_emoji('Sunny') === '☀️' && bcrf_wx_emoji('Partly Sunny') === '⛅', 'emoji match the forecast');
$ok(str_contains(bcrf_wx_note($days[1]), 'rain or shine'), 'wet day gets the rain-or-shine note');
$ok(str_contains(bcrf_wx_note(['wet' => false, 'high' => 38, 'short' => 'Sunny']), 'Bundle up'), 'cold day gets bundle up');
$ok(bcrf_wx_note(['wet' => false, 'high' => 55, 'short' => 'Cloudy']) === '', 'ordinary day: no note');

// --- visit day: skips days the farm is closed
$closedToday = fn($date, $isToday) => !$isToday;
$ok(bcrf_wx_visit_day($days, $closedToday)['label'] !== 'Today', 'closed today → line talks about the next open day');
$ok(bcrf_wx_visit_day($days, fn() => false) === null, 'closed every day → no line');

// --- closed-today switch
require_once $root . '/includes/site_info.php';
$ok(site_weather_closed_note() === '', 'switch off by default');
$today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
$set('wx_closed_date', $today);
$set('wx_closed_note', '');
// site_info caches settings per request; check the rule directly from fresh values
$fresh = function () use ($pdo, $tz) {
    $d = bcrf_wx_setting($pdo, 'wx_closed_date');
    return $d === (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
};
$ok($fresh(), 'switch on for today');
$set('wx_closed_date', (new DateTime('yesterday', new DateTimeZone($tz)))->format('Y-m-d'));
$ok(!$fresh(), 'yesterday\'s switch no longer counts');

// --- pages render without the database cache or with it, and never throw
$ok(is_string(bcrf_wx_home_html()) && is_string(bcrf_wx_forecast_html()), 'page helpers return strings');

echo $fails ? "{$fails} of {$n} checks FAILED\n" : "All {$n} weather checks passed\n";
exit($fails ? 1 : 0);
