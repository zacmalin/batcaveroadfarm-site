<?php
// tests/staff_extras_test.php — per-person extra access and review before publishing (includes/staff_extras.php, migration 0064,
// Nov 14, 2026) against a real, THROWAWAY MySQL 8 database built from every migration.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_extras_test DB_USER=... DB_PASS=... php tests/staff_extras_test.php
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
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
(require $root . '/admin/migrations/0064_staff_access_extras.php')($pdo);   // safe to repeat

$GLOBALS['pdo'] = $pdo;
$_SESSION = [];
require $root . '/admin/includes/permission_checks.php';
require_once $root . '/includes/staff_extras.php';
require_once $root . '/includes/notifications.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$as = function (int $uid, string $role) { $_SESSION['user_id'] = $uid; $_SESSION['user_role'] = $role; bcrf_extras_forget(); };

$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
    (1, 'Olive', 'Owner', 'o@x.test', 'administrator', 'active'),
    (2, 'Bob', 'Writer', 'bob@x.test', 'Shopkeep', 'active'),
    (3, 'Sam', 'Shop', 'sam@x.test', 'Shopkeep', 'active'),
    (4, 'Cara', 'Content', 'cara@x.test', 'Media', 'active'),
    (5, 'Stu', 'Staff', 'stu@x.test', 'Staff', 'active'),
    (6, 'Mia', 'Manager', 'mia@x.test', 'moderator', 'active')");
$pdo->exec("INSERT INTO role_permissions (role, table_name, can_view, can_edit) VALUES ('Shopkeep', 'blog_posts', 1, 1), ('moderator', 'blog_posts', 1, 1)
            ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_edit = VALUES(can_edit)");
$pdo->exec("INSERT INTO view_permissions (role, view_name, can_access) VALUES ('Shopkeep', 'newsletter', 0), ('Media', 'newsletter', 0), ('Media', 'pos', 0)
            ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)");

$ok(bcrf_extras_ready($pdo), 'migration 0064 ran (twice)');

// Before: role only
$as(2, 'Shopkeep');
$ok(!hasTableAccess('Shopkeep', 'blog_posts', 'edit'), 'Shopkeep is blocked from the blog in code, even with a role tick');
$ok(!hasViewAccess('Shopkeep', 'newsletter'), 'no newsletter for Shopkeep');

// Save Bob's extras
[$p, $tv, $te] = bcrf_extras_save($pdo, 2, ['newsletter' => '1', 'not_on_page' => '1', 'permissions' => '1'],
                                  ['blog_posts' => ['edit' => '1'], 'breeds' => ['view' => '1'], 'users_fake' => ['view' => '1']], 1);
$ok($p === 1 && $tv === 2 && $te === 1, "counts $p/$tv/$te (items not on the page and Owner-only ones ignored)");
$ok((int)$pdo->query("SELECT COUNT(*) FROM staff_access_extras WHERE user_id = 2")->fetchColumn() === 3, 'three rows stored');
$ok((int)$pdo->query("SELECT can_view FROM staff_access_extras WHERE user_id = 2 AND item_key = 'blog_posts'")->fetchColumn() === 1, 'edit implies view');

$as(2, 'Shopkeep');
$ok(hasTableAccess('Shopkeep', 'blog_posts', 'edit') && hasTableAccess('Shopkeep', 'blog_posts', 'view'), 'Bob: blog edit as an extra lifts the Shopkeep block');
$ok(!hasTableAccess('Shopkeep', 'blog_posts', 'edit', false), 'Bob: role alone still has no blog');
$ok(hasViewAccess('Shopkeep', 'newsletter') && !hasViewAccess('Shopkeep', 'newsletter', false), 'Bob: newsletter page as an extra');
$ok(hasTableAccess('Shopkeep', 'breeds', 'view') && !hasTableAccess('Shopkeep', 'breeds', 'edit'), 'Bob: breeds view only');
$ok(!hasViewAccess('Shopkeep', 'permissions') && !hasViewAccess('Shopkeep', 'audit_log'), 'Owner-only pages never come from extras');
$ok(!hasViewAccess('Media', 'newsletter'), 'a check for another role ignores Bob\'s extras');
$ok(bcrf_needs_review('Shopkeep', 'blog_posts'), 'Bob\'s blog posts need review');
$ok(!bcrf_needs_review('Shopkeep', 'inventory'), 'review is only for public content');

// Another Shopkeep doesn't get them
$as(3, 'Shopkeep');
$ok(!hasTableAccess('Shopkeep', 'blog_posts', 'edit') && !hasViewAccess('Shopkeep', 'newsletter'), 'Sam (same role) has none of Bob\'s extras');
$ok(!bcrf_needs_review('Shopkeep', 'blog_posts'), 'no review flag without the extra');

// Managers publish without review
$as(6, 'moderator');
$ok(hasTableAccess('moderator', 'blog_posts', 'edit') && !bcrf_needs_review('moderator', 'blog_posts'), 'Manager edits the blog by role: no review');
$as(1, 'administrator');
$ok(!bcrf_needs_review('administrator', 'blog_posts'), 'Owner: no review');

// Content role and the register
$as(4, 'Media');
$ok(bcrf_content_role_blocked(['pos']), 'Content login kept off the register');
bcrf_extras_save($pdo, 4, ['pos' => '1'], [], 1);
$as(4, 'Media');
$ok(!bcrf_content_role_blocked(['pos']) && hasViewAccess('Media', 'pos'), 'Content login with the register as an extra can use it');
$as(2, 'Shopkeep');
$ok(!bcrf_content_role_blocked(['pos']), 'other roles are never blocked by the Content rule');

// Who can have extras
try { bcrf_extras_save($pdo, 1, ['pos' => '1'], [], 1); $ok(false, 'Owner refused'); } catch (RuntimeException $e) { $ok(true, ''); }
try { bcrf_extras_save($pdo, 5, ['pos' => '1'], [], 1); $ok(false, 'Staff refused'); } catch (RuntimeException $e) { $ok(true, ''); }
try { bcrf_extras_save($pdo, 999, [], [], 1); $ok(false, 'unknown person refused'); } catch (RuntimeException $e) { $ok(true, ''); }

// Search and the "Has extras" list
$ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);
$ok($ids(bcrf_extras_search($pdo, '')) === [2, 4, 6, 3], 'blank search lists Shopkeep/Content/Manager staff only: ' . implode(',', $ids(bcrf_extras_search($pdo, ''))));
$ok($ids(bcrf_extras_search($pdo, 'bob')) === [2] && $ids(bcrf_extras_search($pdo, 'cara@x')) === [4], 'search by name and email');
$ok($ids(bcrf_extras_search($pdo, '%')) === [], 'search treats % literally');
$people = bcrf_extras_people($pdo);
$ok($ids($people) === [2, 4] && (int)$people[0]['n'] === 3, 'Has extras: Bob (3) and Cara');

// Clear
bcrf_extras_save($pdo, 2, [], [], 1);
$as(2, 'Shopkeep');
$ok(!hasTableAccess('Shopkeep', 'blog_posts', 'edit') && $ids(bcrf_extras_people($pdo)) === [4], 'Remove all extras');

// Review helpers
$pdo->exec("INSERT INTO blog_posts (id, title, slug, date_published, author, article, status) VALUES (50, 'Live post', 'live-post', '2026-11-01', 'Bob', 'x', 'published'), (51, 'Draft post', 'draft-post', '2026-11-01', 'Bob', 'x', 'draft')");
$ok(bcrf_review_row_live($pdo, 'blog_posts', 50) && !bcrf_review_row_live($pdo, 'blog_posts', 51), 'live vs draft');
$ok(!bcrf_review_row_live($pdo, 'inventory', 1), 'non-review tables are never "live" here');
$nid = bcrf_review_request($pdo, 'blog_posts', 51, 'Draft post', 'Bob');
$row = $pdo->query("SELECT * FROM notifications WHERE id = " . (int)$nid)->fetch();
$ok($nid > 0 && $row['user_id'] === null && $row['role'] === null && $row['kind'] === 'content_review', 'review request goes to Owners and Managers');
$ok($row && $row['link'] === '/admin/admin.php?view=schema&table=blog_posts&edit_id=51' && str_contains($row['title'], 'Draft post'), 'review link opens the draft');
$ok(bcrf_review_link('animals', 7) === '/admin/animals.php?id=7&tab=public', 'animal review link');
$ok(bcrf_notifications_unread($pdo, 6, 'moderator') >= 1 && bcrf_notifications_unread($pdo, 3, 'Shopkeep') === 0, 'Manager sees it, a Shopkeep does not');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
