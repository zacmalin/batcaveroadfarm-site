<?php
// tests/email_prefs_test.php — Farm OS Phase 3 (Nov 12, 2026): notification preferences (includes/email_prefs.php,
// migration 0037) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/email_prefs_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$GLOBALS['pdo'] = $pdo;
$root = dirname(__DIR__);
ini_set('log_errors', '0');
date_default_timezone_set('America/New_York');
$_SESSION = ['user_id' => 1, 'user_role' => 'administrator'];

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require $root . '/admin/includes/audit.php';
require $root . '/includes/email_prefs.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };

// -- Migration --
$ok(bcrf_email_prefs_ready($pdo), 'tables exist');
$ok((int)$one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mailing_list' AND column_name IN ('consent_source', 'consent_at', 'unsubscribed_at')") === 3, 'mailing_list consent columns');
$pdo->exec("INSERT INTO mailing_list (email, is_subscribed, track_nursery_updates, track_inventory_alerts) VALUES ('old@x.test', 1, 1, 0)");
(require $root . '/admin/migrations/0037_email_preferences.php')($pdo);
$ok((int)$one("SELECT is_subscribed FROM mailing_list WHERE email = 'old@x.test'") === 1 && $one("SELECT consent_source FROM mailing_list WHERE email = 'old@x.test'") === null, 'repeat run keeps existing subscribers as they were');

// -- Defaults: nothing stored means reminders on, news off --
$p = bcrf_email_prefs_get($pdo, 'new@x.test');
$ok($p['visit_reminders'] && $p['rewards_reminders'] && !$p['newsletter'] && !$p['nursery'] && !$p['inventory'], 'defaults for a new address');
$ok(bcrf_email_pref_allowed($pdo, 'new@x.test', 'visit_reminders') && bcrf_email_pref_allowed($pdo, 'new@x.test', 'nonsense'), 'allowed by default; unknown kinds never block');
$ok((int)$one("SELECT COUNT(*) FROM email_preferences") === 0, 'reading makes no row');
$p = bcrf_email_prefs_get($pdo, 'OLD@x.test');
$ok($p['newsletter'] && $p['nursery'] && !$p['inventory'], 'existing subscriber shows their topics');
$ok(bcrf_email_prefs_get($pdo, 'not an email')['visit_reminders'] === true && bcrf_email_prefs_url($pdo, 'nope') === '', 'bad address is harmless');

// -- Link --
$url = bcrf_email_prefs_url($pdo, ' Emily@X.test ');
$ok((bool)preg_match('~/preferences\.php\?t=[a-f0-9]{32}$~', $url), 'link has a random token');
$ok(stripos($url, 'emily') === false, 'link never carries the address');
$tok = substr($url, -32);
$row = bcrf_email_prefs_by_token($pdo, $tok);
$ok($row && $row['email'] === 'emily@x.test', 'token finds the address');
$ok(bcrf_email_prefs_url($pdo, 'emily@x.test') === $url, 'same link each time');
$ok(bcrf_email_prefs_by_token($pdo, 'zz') === null && bcrf_email_prefs_by_token($pdo, str_repeat('0', 32)) === null, 'bad tokens find nothing');

// -- Service emails --
$ch = bcrf_email_prefs_save($pdo, 'emily@x.test', ['visit_reminders' => false], 'link', null, true);
$ok($ch === ['visit_reminders' => false], 'turning a reminder off reports the change');
$ok(!bcrf_email_pref_allowed($pdo, 'emily@x.test', 'visit_reminders') && bcrf_email_pref_allowed($pdo, 'emily@x.test', 'rewards_reminders'), 'only that reminder stops');
$ok(bcrf_email_prefs_save($pdo, 'emily@x.test', ['visit_reminders' => false], 'link', null, true) === [], 'saving the same again changes nothing');
$ok((int)$one("SELECT COUNT(*) FROM email_preference_log WHERE email = 'emily@x.test' AND setting = 'visit_reminders' AND value = 0 AND via = 'link'") === 1, 'change is logged once');
bcrf_email_prefs_save($pdo, 'emily@x.test', ['visit_reminders' => true], 'portal');
$ok(bcrf_email_pref_allowed($pdo, 'emily@x.test', 'visit_reminders'), 'reminders can be turned back on without proof (not marketing)');

// -- Marketing needs proof of the inbox --
$ch = bcrf_email_prefs_save($pdo, 'pat@x.test', ['newsletter' => true, 'nursery' => true], 'staff', 1, false);
$ok($ch === [] && !bcrf_email_prefs_get($pdo, 'pat@x.test')['newsletter'], 'staff can\'t sign someone up for news');
$ok((int)$one("SELECT COUNT(*) FROM mailing_list WHERE email = 'pat@x.test'") === 0, 'no mailing_list row made without consent');
$ch = bcrf_email_prefs_save($pdo, 'pat@x.test', ['newsletter' => true, 'nursery' => false, 'inventory' => true], 'link', null, true);
$ok($ch === ['newsletter' => true, 'inventory' => true], 'link holder can join news, changes reported');
$m = $pdo->query("SELECT * FROM mailing_list WHERE email = 'pat@x.test'")->fetch();
$ok($m && (int)$m['is_subscribed'] === 1 && (int)$m['track_nursery_updates'] === 0 && (int)$m['track_inventory_alerts'] === 1, 'mailing_list holds the choices (same state everywhere)');
$ok($m['consent_source'] === 'link' && $m['consent_at'] !== null, 'consent source and time recorded');
$ch = bcrf_email_prefs_save($pdo, 'pat@x.test', ['nursery' => true], 'staff', 1, false);
$ok($ch === [] && !bcrf_email_prefs_get($pdo, 'pat@x.test')['nursery'], 'staff can\'t add a topic either');
$ch = bcrf_email_prefs_save($pdo, 'pat@x.test', ['inventory' => false], 'staff', 1);
$ok($ch === ['inventory' => false] && (int)$one("SELECT is_subscribed FROM mailing_list WHERE email = 'pat@x.test'") === 1, 'staff can turn a topic off; newsletter stays');
$ok((int)$one("SELECT actor_id FROM email_preference_log WHERE email = 'pat@x.test' AND via = 'staff'") === 1, 'staff change logged with who');
bcrf_email_prefs_save($pdo, 'pat@x.test', ['newsletter' => false], 'portal');
$m = $pdo->query("SELECT * FROM mailing_list WHERE email = 'pat@x.test'")->fetch();
$ok((int)$m['is_subscribed'] === 0 && $m['unsubscribed_at'] !== null && $m['consent_source'] === 'link', 'newsletter off: unsubscribed time kept with the original consent');
$ok(!bcrf_email_prefs_get($pdo, 'pat@x.test')['inventory'], 'topics read as off while the newsletter is off');
bcrf_email_prefs_save($pdo, 'pat@x.test', ['newsletter' => true, 'nursery' => true], 'portal', null, true);
$m = $pdo->query("SELECT * FROM mailing_list WHERE email = 'pat@x.test'")->fetch();
$ok((int)$m['is_subscribed'] === 1 && $m['consent_source'] === 'portal' && $m['unsubscribed_at'] === null, 'rejoining records the new consent');
$ch = bcrf_email_prefs_save($pdo, 'kim@x.test', ['newsletter' => false, 'nursery' => true], 'link', null, true);
$ok($ch === [] && (int)$one("SELECT COUNT(*) FROM mailing_list WHERE email = 'kim@x.test'") === 0, 'a topic without the newsletter does nothing');

// -- Existing subscriber, consent from before tracking --
bcrf_email_prefs_save($pdo, 'old@x.test', ['rewards_reminders' => false], 'link', null, true);
$m = $pdo->query("SELECT * FROM mailing_list WHERE email = 'old@x.test'")->fetch();
$ok((int)$m['is_subscribed'] === 1 && (int)$m['track_nursery_updates'] === 1 && $m['consent_source'] === null, 'changing a reminder leaves news untouched');

// -- All off --
$ch = bcrf_email_prefs_all_off($pdo, 'old@x.test', 'link');
$p = bcrf_email_prefs_get($pdo, 'old@x.test');
$ok(!$p['visit_reminders'] && !$p['rewards_reminders'] && !$p['newsletter'] && isset($ch['newsletter'], $ch['visit_reminders']), 'stop everything optional');
$ok((int)$one("SELECT is_subscribed FROM mailing_list WHERE email = 'old@x.test'") === 0, 'all off unsubscribes');

// -- Form helpers --
$ok(bcrf_email_prefs_from_post(['pref_visit_reminders' => '0', 'pref_newsletter' => '1', 'pref_bogus' => '1', 'x' => 1]) === ['visit_reminders' => false, 'newsletter' => true], 'reads only known checkboxes');
$html = bcrf_email_prefs_fields(bcrf_email_prefs_get($pdo, 'new@x.test'), false);
$ok(substr_count($html, 'disabled') === 3 && str_contains($html, 'Verify your email'), 'unverified: news boxes locked with a note');
$html = bcrf_email_prefs_fields(bcrf_email_prefs_get($pdo, 'pat@x.test'), false);
$ok(substr_count($html, 'disabled') === 1, 'unverified: what\'s already on can still be turned off');
$ok(substr_count(bcrf_email_prefs_fields(bcrf_email_prefs_get($pdo, 'new@x.test'), true), 'disabled') === 0, 'verified: nothing locked');
$f = bcrf_email_prefs_footer($pdo, 'emily@x.test', 'visit reminders');
$ok(str_contains($f, $url) && str_contains($f, 'visit reminders') && str_contains($f, 'you booked with us'), 'footer links to the preferences page');

// -- Senders respect it --
require $root . '/admin/includes/email_template.php';
require $root . '/includes/booking.php';
bcrf_email_prefs_save($pdo, 'quiet@x.test', ['visit_reminders' => false], 'link');
$sentBefore = $GLOBALS['bcf_mail_last_error'] ?? null;
$ok(booking_send_reminder(['email' => 'quiet@x.test', 'customer_name' => 'Q', 'booking_date' => '2026-12-05', 'start_time' => '10:00:00', 'token' => 't'], ['label' => 'Visit']) === false, 'visit reminder not sent when turned off');
$ok(($GLOBALS['bcf_mail_last_error'] ?? null) === $sentBefore, 'mailer was not called');
$ok(function_exists('booking_send_review_request'), 'the review-request cron has its sender');
$ok(booking_send_review_request(['email' => 'quiet@x.test', 'customer_name' => 'Q Person'], ['label' => 'Visit']) === false, 'thank-you not sent when turned off');
$ok(($GLOBALS['bcf_mail_last_error'] ?? null) === $sentBefore, 'mailer still not called');
$ok(booking_send_review_request(['email' => '', 'customer_name' => 'Q'], null) === false, 'no email, no thank-you');
$src = file_get_contents($root . '/includes/rewards.php');
$ok(str_contains($src, "bcrf_email_pref_allowed(\$pdo, (string)\$r['email'], 'rewards_reminders')"), 'rewards reminders check the setting');

echo $fails ? "$n checks, $fails failed\n" : "$n checks, 0 failed\n";
exit($fails ? 1 : 0);
