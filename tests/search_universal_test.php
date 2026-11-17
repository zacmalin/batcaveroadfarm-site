<?php
// tests/search_universal_test.php — universal search (includes/search.php + includes/admin_nav.php, Nov 14, 2026) against a
// real, THROWAWAY MySQL database: pages and settings by name or extra word, limited to what the person can open; the newer
// record types (tasks, applicants, animal health and care notes, ingredients, social posts, newsletters, subscribers,
// comments); staff notes on customers, bookings and parties.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_all_test DB_USER=... DB_PASS=... php tests/search_universal_test.php
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

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/search.php';
require_once $root . '/includes/admin_nav.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };

// -- Data --
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['customers', 'bookings', 'event_inquiries', 'tasks', 'applications', 'application_notes', 'jobs', 'animals', 'animal_medical_records',
          'animal_care_types', 'animal_care_logs', 'animal_care_log_animals', 'ingredients', 'social_queue', 'newsletter_campaigns',
          'mailing_list', 'recipe_comments', 'recipes'] as $t) $pdo->exec("DELETE FROM `$t`");
$pdo->exec("INSERT INTO customers (id, first_name, last_name, email, password, created_at, staff_notes) VALUES
    (1, 'Emily', 'Jenkins', 'emily@example.test', '', NOW(), NULL),
    (2, 'Pat', 'Rivera', 'pat@example.test', '', NOW(), 'Allergic to bees, keep the epipen handy')");
$pdo->exec("INSERT INTO bookings (id, type_id, customer_name, email, party_size, booking_date, start_time, end_time, token, status, notes, admin_note) VALUES
    (1, 1, 'Valley School', 'office@valley.test', 30, '2026-12-05', '10:00', '12:00', REPEAT('a', 64), 'pending', 'Two wheelchair users in the group', NULL)");
$pdo->exec("INSERT INTO event_inquiries (id, event_type, preferred_date, customer_name, email, status, special_requests, admin_notes, created_at) VALUES
    (1, 'birthday', '2026-12-05', 'Dana Smith', 'dana@example.test', 'new', 'Unicorn cake please', NULL, NOW())");
$pdo->exec("INSERT INTO tasks (id, title, description, status, assignee_user_id, assignee_role, created_by, created_at) VALUES
    (1, 'Fix the goat fence', 'North paddock gate hinge', 'open', 7, NULL, 1, UTC_TIMESTAMP()),
    (2, 'Order fence posts', NULL, 'open', 9, NULL, 1, UTC_TIMESTAMP()),
    (3, 'Paint the fence', NULL, 'open', NULL, 'Shopkeep', 1, UTC_TIMESTAMP()),
    (4, 'Check fence charger', NULL, 'open', NULL, NULL, 1, UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO jobs (id, category_id, title, slug) VALUES (1, 1, 'Weekend animal keeper', 'weekend-keeper')");
$pdo->exec("INSERT INTO applications (id, job_cycle_id, job_id, first_name, last_name, email, phone, age_bracket, status, is_spam, submitted_at, cover_note) VALUES
    (1, 1, 1, 'Jordan', 'Applewood', 'jordan@example.test', '828-555-0199', '18_plus', 'new', 0, NOW(), 'I raised goats for 4-H'),
    (2, 1, 1, 'Spam', 'Bot', 'spam@example.test', NULL, '18_plus', 'new', 1, NOW(), 'goats goats')");
$pdo->exec("INSERT INTO application_notes (application_id, body) VALUES (1, 'Great with kids, call back Tuesday')");
$pdo->exec("INSERT INTO animals (id, name, breed_id, classification_id, date_of_birth, status, tag, is_active) VALUES (1, 'Hazel', 1, 1, '2022-04-01', 'active', 'G-7', 1)");
$pdo->exec("INSERT INTO animal_medical_records (animal_id, record_date, kind, title, vet_name, diagnosis, created_at) VALUES (1, '2026-11-01', 'exam', 'Hoof trim', 'Dr. Okafor', 'Mild foot rot, keep dry', UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO animal_care_types (id, name) VALUES (1, 'Feeding')");
$pdo->exec("INSERT INTO animal_care_logs (id, care_type_id, logged_at, note) VALUES (1, 1, UTC_TIMESTAMP(), 'Left half her hay, watch appetite')");
$pdo->exec("INSERT INTO animal_care_log_animals (log_id, animal_id) VALUES (1, 1)");
$pdo->exec("INSERT INTO ingredients (id, singular_name, plural_name, allergens) VALUES (1, 'strawberry', 'strawberries', NULL)");
$pdo->exec("INSERT INTO social_queue (content_type, content_id, fb_caption, status, created_at) VALUES ('blog', 1, 'Pumpkin patch opens Saturday!', 'pending', NOW())");
$pdo->exec("INSERT INTO newsletter_campaigns (subject, body, target_segment) VALUES ('Holiday hours', 'We close early on Christmas Eve', 'all')");
$pdo->exec("INSERT INTO mailing_list (id, email, is_subscribed) VALUES (1, 'neighbor@example.test', 1)");
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published) VALUES (1, 'Apple butter', 'apple-butter', '2026-10-01')");
$pdo->exec("INSERT INTO recipe_comments (recipe_id, user_id, comment_text) VALUES (1, 1, 'Made this with pears instead, delicious')");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$admin = fn(array $c) => true;
$role = function (array $views, array $tables = []) {
    return function (array $c) use ($views, $tables): bool {
        if ($c[0] === 'always') return true;
        if ($c[0] === 'view') return in_array($c[1], $views, true);
        if ($c[0] === 'table') return in_array($c[1] . ':' . ($c[2] ?? 'view'), $tables, true);
        return false;
    };
};
$keys  = fn(array $r) => array_column($r['groups'], 'key');
$group = function (array $r, string $k) { foreach ($r['groups'] as $g) if ($g['key'] === $k) return $g; return null; };
$urls  = fn(?array $g) => $g ? array_column($g['rows'], 'url') : [];

// -- Pages and settings --
$r = bcrf_search($pdo, 'weather', $admin);
$ok(($r['groups'][0]['key'] ?? '') === 'pages', 'pages come first');
$ok(($group($r, 'pages')['rows'][0]['url'] ?? '') === '/admin/weather.php', '"weather" → Weather day page');
$ok(str_contains($group($r, 'pages')['rows'][0]['sub'] ?? '', 'Bookings'), 'page shows where it lives in the menu');
$r = bcrf_search($pdo, 'database', $admin);
$ok(($group($r, 'pages')['rows'][0]['url'] ?? '') === '/admin/migrations.php', '"database" → Settings › Database');
$r = bcrf_search($pdo, 'migrations', $admin);
$ok(in_array('/admin/migrations.php', $urls($group($r, 'pages')), true), 'extra word "migrations" finds Database');
$r = bcrf_search($pdo, 'tax', $admin);
$ok(($group($r, 'pages')['rows'][0]['url'] ?? '') === '/admin/payment_settings.php?tab=tax', '"tax" → Tax rates first');
$r = bcrf_search($pdo, 'data', $admin);
$ok(in_array('/admin/migrations.php', $urls($group($r, 'pages')), true), 'start of a word matches ("data" → Database)');
$r = bcrf_search($pdo, 'atabase', $admin);
$ok($group($r, 'pages') === null, 'middle of a word does not match');
$r = bcrf_search($pdo, 'weather', $role(['pos']));
$ok($group($r, 'pages') === null, 'Weather day hidden without bookings access');
$r = bcrf_search($pdo, 'weather', $role(['bookings']));
$ok(in_array('/admin/weather.php', $urls($group($r, 'pages')), true), 'Weather day shown with bookings access');
$r = bcrf_search($pdo, 'database', $role(['bookings', 'customers', 'pos']));
$ok($group($r, 'pages') === null, 'Database hidden from a manager without that view');
$r = bcrf_search($pdo, 'gate', $role(['pos']));
$ok(in_array('/admin/gate.php', $urls($group($r, 'pages')), true), 'off-menu page (Gate check-in) is findable');
$r = bcrf_search($pdo, 'pin', $role(['pos']));
$ok(!in_array('/admin/pin_settings.php', $urls($group($r, 'pages')), true), 'PIN settings need a PIN role');
$r = bcrf_search($pdo, 'pin', bcrf_search_can_for('administrator'));
$ok(in_array('/admin/pin_settings.php', $urls($group($r, 'pages')), true), 'Owner finds PIN sign-in');
$r = bcrf_search($pdo, 'sales tax', $admin);
$ok(in_array('/admin/payment_settings.php?tab=tax', $urls($group($r, 'pages')), true), 'two words both have to match');
$r = bcrf_search($pdo, 'Settings', $admin);
$ok(count($group($r, 'pages')['rows'] ?? []) === count(bcrf_search_pages('Settings', $admin)), 'every allowed page match comes back (page shows 5, rest under Show more)');

// Every extra word and extra page points at a real menu link, so a renamed page can't leave them dangling
$hrefs = [];
foreach (bcrf_admin_nav_sections() as $sec) foreach ($sec['items'] as $it) foreach ($it['tabs'] as $t) $hrefs[$t[1]] = true;
foreach (bcrf_admin_nav_extra_pages() as $p) $hrefs[$p[1]] = true;
foreach (array_keys(bcrf_admin_nav_keywords()) as $h) $ok(isset($hrefs[$h]), "keywords point at a real page: $h");
foreach (bcrf_admin_nav_extra_pages() as $p) $ok(is_file($root . parse_url($p[1], PHP_URL_PATH)), 'extra page exists: ' . $p[1]);
// No business names in the generic word list (Farm OS is sold to other farms)
$ok(!preg_match('/bat ?cave|bcrf|old fort/i', json_encode(bcrf_admin_nav_keywords()) . json_encode(bcrf_admin_nav_extra_pages())), 'no business names in search words');

// -- Notes --
$r = bcrf_search($pdo, 'epipen', $admin);
$g = $group($r, 'customers');
$ok($g && $g['rows'][0]['url'] === '/admin/customers.php?id=2' && str_starts_with($g['rows'][0]['sub'], 'Note: '), 'customer staff note found, shown as a note');
$r = bcrf_search($pdo, 'wheelchair', $admin);
$ok(str_contains($group($r, 'bookings')['rows'][0]['sub'] ?? '', 'Note: Two wheelchair'), 'booking note found');
$r = bcrf_search($pdo, 'unicorn', $admin);
$ok(($group($r, 'parties')['rows'][0]['url'] ?? '') === '/admin/party.php?id=1', 'party special request found');
$r = bcrf_search($pdo, 'Rivera', $admin);
$ok(($group($r, 'customers')['rows'][0]['sub'] ?? '') === 'pat@example.test', 'name match shows the email, not the note');

// -- Tasks: managers see all, others only their own / their role / anyone --
$r = bcrf_search($pdo, 'fence', $admin);
$ok(count($group($r, 'tasks')['rows'] ?? []) === 4, 'manager sees every task');
$r = bcrf_search($pdo, 'fence', $role(['tasks']), ['id' => 7, 'role' => 'Shopkeep']);
$u = $urls($group($r, 'tasks')); sort($u);
$ok($u === ['/admin/tasks.php?id=1', '/admin/tasks.php?id=3', '/admin/tasks.php?id=4'], 'non-manager: own task, own role, anyone (not someone else\'s): ' . implode(',', $u));
$r = bcrf_search($pdo, 'hinge', $role(['tasks']), ['id' => 7, 'role' => 'Shopkeep']);
$ok(str_contains($group($r, 'tasks')['rows'][0]['sub'] ?? '', 'hinge'), 'task description matched and shown');
$r = bcrf_search($pdo, 'fence', $role(['pos']));
$ok($group($r, 'tasks') === null, 'no Tasks access, no tasks');

// -- Other new types --
$r = bcrf_search($pdo, 'Applewood', $role(['jobs']));
$ok(($group($r, 'applicants')['rows'][0]['url'] ?? '') === '/admin/admin.php?view=jobs&app=1', 'applicant by name');
$r = bcrf_search($pdo, 'Tuesday', $role(['jobs']));
$ok(($group($r, 'applicants')['rows'][0]['url'] ?? '') === '/admin/admin.php?view=jobs&app=1', 'applicant by hiring note');
$r = bcrf_search($pdo, 'goats', $role(['jobs']));
$ok(count($group($r, 'applicants')['rows'] ?? []) === 1, 'spam applications left out');
$r = bcrf_search($pdo, 'Applewood', $role(['customers', 'pos']));
$ok($group($r, 'applicants') === null, 'applicants need Hiring access');
$r = bcrf_search($pdo, 'foot rot', $role(['animal_health'], ['animals:view']));
$ok(($group($r, 'health')['rows'][0]['url'] ?? '') === '/admin/animals.php?id=1&tab=health', 'health record by diagnosis, opens the animal\'s Health tab');
$r = bcrf_search($pdo, 'Okafor', $role(['animal_health'], ['animals:view']));
$ok($group($r, 'health') !== null, 'health record by vet');
$r = bcrf_search($pdo, 'foot rot', $role([], ['animals:view']));
$ok($group($r, 'health') === null, 'health needs both animals and Health access');
$r = bcrf_search($pdo, 'appetite', $role([], ['animals:view']));
$ok(($group($r, 'care')['rows'][0]['url'] ?? '') === '/admin/animals.php?id=1&tab=care' && str_contains($group($r, 'care')['rows'][0]['title'], 'Hazel'), 'care note, with the animal');
$r = bcrf_search($pdo, 'strawberries', $role([], ['ingredients:view']));
$ok(($group($r, 'ingredients')['rows'][0]['url'] ?? '') === '/admin/kitchen_ingredients.php?id=1', 'ingredient by plural');
$r = bcrf_search($pdo, 'pumpkin', $role(['social']));
$ok($group($r, 'social') !== null, 'social post caption');
$r = bcrf_search($pdo, 'Christmas', $role(['newsletter']));
$ok($group($r, 'newsletter') !== null, 'newsletter body');
$r = bcrf_search($pdo, 'neighbor', $role([], ['mailing_list:view']));
$ok($group($r, 'subscribers') !== null, 'subscriber email');
$r = bcrf_search($pdo, 'pears', $role(['comment_moderation']));
$ok(str_contains($group($r, 'comments')['sub'] ?? ($group($r, 'comments')['rows'][0]['sub'] ?? ''), 'Apple butter'), 'comment, with its recipe');
$r = bcrf_search($pdo, 'pears', $role(['pos']));
$ok($r['groups'] === [], 'nothing leaks to a register-only person');

// -- "Zac"-style name search spans everything --
$r = bcrf_search($pdo, 'Dana', $admin);
$ok(in_array('parties', $keys($r), true), 'name search reaches parties');

// -- Every query runs on the real schema --
$logf = tempnam(sys_get_temp_dir(), 'srch');
ini_set('log_errors', '1'); ini_set('error_log', $logf);
foreach (['zz', 'fence', '555-0199', 'Hazel', 'goats', 'pears', 'weather'] as $q) { bcrf_search($pdo, $q, $admin); bcrf_search($pdo, $q, $role(['tasks']), ['id' => 7, 'role' => 'Shopkeep']); }
ini_set('log_errors', '0');
$errs = trim((string)file_get_contents($logf)); @unlink($logf);
$ok($errs === '', 'no SQL errors in any kind: ' . $errs);

// -- Default $can --
$c = bcrf_search_can_for('Shopkeep');
$ok($c(['always']) && $c(['pinrole']), 'Shopkeep: always + PIN role');
$c = bcrf_search_can_for('Media');
$ok(!$c(['pinrole']), 'Media is not a PIN role');
$ok(!bcrf_search_can_for('user')(['always']), 'a public account gets nothing, even "always"');

echo $n . ' checks, ' . ($fails ? $fails . ' FAILED' : '0 failed') . "\n";
exit($fails ? 1 : 0);
