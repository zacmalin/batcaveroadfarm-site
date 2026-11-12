<?php
// tests/task_forms_test.php — Farm OS Phase 3 slice 2 (Nov 11, 2026) against a real, THROWAWAY MySQL 8 database:
// questions on finishing, follow-up tasks, photos, the morning list and late-task emails (includes/tasks.php,
// includes/task_notify.php, migration 0030).
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/task_forms_test.php
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration.
// Emails are captured, not sent. Photos go to a temporary folder. Exit 0 = all passed.
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
(require $root . '/admin/migrations/0030_task_forms_alerts.php')($pdo);   // safe to repeat

$tmp = sys_get_temp_dir() . '/bcrf_task_photos_' . getmypid();
@mkdir($tmp, 0700, true);
putenv('BCRF_UPLOAD_DIR=' . $tmp);
$GLOBALS['bcrf_tasks_test_upload'] = true;
$GLOBALS['mails'] = [];
function bcf_send_mail(string $to, string $subject, string $html, array $o = []): bool { $GLOBALS['mails'][] = [$to, $subject, $html]; return true; }
function bcrf_farm_url(): string { return 'https://farm.test'; }
require $root . '/includes/task_notify.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$throws = function (callable $f, string $what, string $like = '') use ($ok) {
    try { $f(); $ok(false, "$what (no error)"); }
    catch (BcrfTaskError $e) { $ok($like === '' || stripos($e->getMessage(), $like) !== false, "$what [" . $e->getMessage() . "]"); }
};
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$as = function (string $role, int $uid) { $_SESSION = ['user_id' => $uid, 'user_role' => $role]; };
function hasViewAccess(string $role, string $view): bool {
    global $pdo;
    if ($role === 'administrator') return true;
    $st = $pdo->prepare("SELECT can_access FROM view_permissions WHERE role = ? AND view_name = ?");
    $st->execute([$role, $view]);
    return (int)$st->fetchColumn() === 1;
}
$mailsTo = function (?string $to = null) { return array_values(array_filter($GLOBALS['mails'], fn($m) => $to === null || $m[0] === $to)); };
$jpeg = function (int $w, int $h) use ($tmp): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 120, 160, 90));
    $f = $tmp . '/in_' . bin2hex(random_bytes(4)) . '.jpg';
    imagejpeg($im, $f, 85);
    return $f;
};
$upload = fn(string $f) => ['name' => basename($f), 'tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f)];

$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
  (1, 'Zac', 'Owner', 'o@x.test', 'administrator', 'active'), (2, 'Mia', 'Manager', 'm@x.test', 'moderator', 'active'),
  (3, 'Sam', 'Hand', 's@x.test', 'Staff', 'active'), (4, 'Ann', 'Hand', 'a@x.test', 'Staff', 'active'), (5, 'Kim', 'Shop', 'k@x.test', 'Shopkeep', 'active'),
  (6, 'Ed', 'Gone', 'e@x.test', 'Staff', 'disabled'), (7, 'Pat', 'Media', 'p@x.test', 'Media', 'active')");
$pdo->exec("DELETE FROM site_hours");
$ins = $pdo->prepare("INSERT INTO site_hours (scope, day_of_week, block_order, open_time, close_time, is_closed) VALUES ('general', ?, 0, ?, ?, ?)");
foreach ([0, 2, 3, 4, 5, 6] as $d) $ins->execute([$d, '10:00:00', '16:00:00', 0]);
$ins->execute([1, null, null, 1]);
$pdo->exec("UPDATE task_recurrences SET active = 0");   // starter chores out of the way; this file makes its own
$pdo->exec("DELETE FROM tasks");

// -- Migration --
$ok(bcrf_tasks_v2($pdo), 'migration 0030 ran');
foreach (['form_json', 'photo_rule', 'followup_unticked'] as $c) foreach (['tasks', 'task_recurrences', 'task_templates'] as $t)
    $ok((int)$one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?", [$t, $c]) === 1, "$t.$c added");
$ok((string)$one("SELECT setting_value FROM site_settings WHERE setting_key = 'task_late_after_min'") === '30' && (string)$one("SELECT setting_value FROM site_settings WHERE setting_key = 'task_escalate_after_min'") === '120', 'late/escalate settings seeded');
$pdo->exec("UPDATE site_settings SET setting_value = '45' WHERE setting_key = 'task_late_after_min'");
(require $root . '/admin/migrations/0030_task_forms_alerts.php')($pdo);
$ok((string)$one("SELECT setting_value FROM site_settings WHERE setting_key = 'task_late_after_min'") === '45', 'running 0030 again keeps a changed setting');
$pdo->exec("UPDATE site_settings SET setting_value = '30' WHERE setting_key = 'task_late_after_min'");

// -- Reading questions --
$form = bcrf_task_form_parse("Any animal off feed or limping? (yes/no, follow up if yes)\nWater trough temperature (number, °F)\nGate condition (choice: good, needs repair, broken)*\nAnything the vet should know (text)\nEggs collected?\nFeeders full? (yes/no, follow up)*\n\n");
$ok(count($form) === 6, 'six questions read, blank lines ignored');
$ok($form[0]['type'] === 'yesno' && $form[0]['followup'] === 'yes' && !$form[0]['required'], 'yes/no, follow up if yes');
$ok($form[1]['type'] === 'number' && $form[1]['unit'] === '°F', 'number with unit');
$ok($form[2]['type'] === 'choice' && $form[2]['options'] === ['good', 'needs repair', 'broken'] && $form[2]['required'], 'choice list, must answer');
$ok($form[3]['type'] === 'text' && $form[4]['type'] === 'yesno' && $form[4]['followup'] === '', 'text; no brackets = plain yes/no');
$ok($form[5]['followup'] === 'no' && $form[5]['required'], '"follow up" means when No; * = must answer');
$ok(array_column($form, 'k') === ['q1', 'q2', 'q3', 'q4', 'q5', 'q6'], 'questions keyed q1..q6');
$ok(bcrf_task_form_parse(bcrf_task_form_text($form)) === $form, 'text round trip gives the same form');
$throws(fn() => bcrf_task_form_parse('Fence ok? (maybe)'), 'unknown kind refused', 'question 1');
$throws(fn() => bcrf_task_form_parse('Size (choice: big)'), 'one-option choice refused', 'two choices');
$throws(fn() => bcrf_task_form_parse("A\nB (yes/no, follow up if maybe)"), 'bad follow-up refused', 'question 2');
$throws(fn() => bcrf_task_form_parse(implode("\n", array_fill(0, 21, 'Q?'))), 'over 20 questions refused', '20');
$ok(bcrf_task_form_parse('') === [], 'no questions = empty form');

// -- Checking answers --
$throws(fn() => bcrf_task_form_answers($form, []), 'must-answer blank refused', 'Gate condition');
$throws(fn() => bcrf_task_form_answers($form, ['q3' => 'good', 'q6' => 'yes', 'q2' => 'warm']), 'number checked', 'number');
$throws(fn() => bcrf_task_form_answers($form, ['q3' => 'fine', 'q6' => 'yes']), 'choice must be on the list', 'choices');
$throws(fn() => bcrf_task_form_answers($form, ['q3' => 'good', 'q6' => 'perhaps']), 'yes/no checked', 'yes or no');
$a = bcrf_task_form_answers($form, ['q1' => 'YES', 'q2' => '1,250.50', 'q3' => 'broken', 'q4' => str_repeat('x', 600), 'q5' => '', 'q6' => 'no']);
$ok($a['q1'] === 'yes' && $a['q2'] === '1250.5' && $a['q3'] === 'broken' && mb_strlen($a['q4']) === 500 && !isset($a['q5']) && $a['q6'] === 'no', 'answers cleaned: case, number, length, blanks left out');
$ok(bcrf_task_answer_label($form[1], '41.5') === '41.5 °F' && bcrf_task_answer_label($form[0], 'no') === 'No' && bcrf_task_answer_label($form[3], null) === '—', 'answer labels');
$ok(bcrf_task_answer_flags($form[0], 'yes') && !bcrf_task_answer_flags($form[0], 'no') && bcrf_task_answer_flags($form[5], 'no') && !bcrf_task_answer_flags($form[4], 'no'), 'which answers flag a follow-up');

// -- Chores carry questions and photo rule onto each day --
$GLOBALS['bcrf_tasks_today'] = '2026-11-12'; $GLOBALS['bcrf_tasks_time'] = '06:00:00';
$as('administrator', 1);
$throws(fn() => bcrf_rec_save($pdo, ['title' => 'Bad', 'freq' => 'daily', 'questions' => 'X (huh)']), 'chore with an unreadable question is not saved', 'question 1');
$ok((int)$one("SELECT COUNT(*) FROM task_recurrences WHERE title = 'Bad'") === 0, '...and nothing was written');
$rid = bcrf_rec_save($pdo, ['title' => 'Pen check', 'freq' => 'daily', 'who' => 'role:Staff', 'due_anchor' => 'time', 'due_time' => '09:00', 'start_date' => '2026-11-12',
    'checklist' => "Fences\nWater\nShade", 'questions' => "Any animal hurt? (yes/no, follow up if yes)\nTrough temp (number, °F)", 'photo_rule' => 'required', 'followup_unticked' => '1']);
$day = $pdo->query("SELECT * FROM tasks WHERE recurrence_id = $rid AND occurrence_date = '2026-11-12'")->fetch();
$ok($day && $day['photo_rule'] === 'required' && (int)$day['followup_unticked'] === 1 && count(json_decode($day['form_json'], true)) === 2, 'chore day copies questions, photo rule, follow-up setting');
$ok((int)$one("SELECT COUNT(*) FROM tasks WHERE recurrence_id = $rid AND photo_rule = 'required'") === 7, 'all 7 days made with them');
$r = bcrf_rec_get($pdo, $rid);
$ok(bcrf_task_form_text(json_decode($r['form_json'], true)) === "Any animal hurt? (yes/no, follow up if yes)\nTrough temp (number, °F)", 'chore form reads back as typed');
// Changing only the questions rebuilds the untouched days ahead
$touched = (int)$one("SELECT id FROM tasks WHERE recurrence_id = $rid AND occurrence_date = '2026-11-13'");
bcrf_task_check($pdo, $touched, 0, true);
bcrf_rec_save($pdo, ['title' => 'Pen check', 'freq' => 'daily', 'who' => 'role:Staff', 'due_anchor' => 'time', 'due_time' => '09:00', 'start_date' => '2026-11-12',
    'checklist' => "Fences\nWater\nShade", 'questions' => "Any animal hurt? (yes/no, follow up if yes)\nTrough temp (number, °F)\nMud? (yes/no)", 'photo_rule' => 'required', 'followup_unticked' => '1'], $rid);
$ok(count(json_decode($one("SELECT form_json FROM tasks WHERE recurrence_id = $rid AND occurrence_date = '2026-11-15'"), true)) === 3, 'question edit reaches untouched days');
$ok(count(json_decode($one("SELECT form_json FROM tasks WHERE id = $touched"), true)) === 2, 'a started day keeps the questions it had');

// -- Finishing with questions and a required photo --
$as('Staff', 3);
$tid = (int)$one("SELECT id FROM tasks WHERE recurrence_id = $rid AND occurrence_date = '2026-11-12'");
bcrf_task_check($pdo, $tid, 0, true);
bcrf_task_check($pdo, $tid, 1, true);
$throws(fn() => bcrf_task_set($pdo, $tid, 'done', '', true, ['q1' => 'no']), 'photo required before finishing', 'photo');
$ok($one("SELECT status FROM tasks WHERE id = $tid") === 'in_progress', '...task still open');
$pid = bcrf_task_photo_add($pdo, $tid, $upload($jpeg(3000, 2000)));
$p = bcrf_task_photo_get($pdo, $pid);
$path = bcrf_task_photo_path($p);
$ok($pid > 0 && is_file($path) && (int)$p['width'] === 1600 && (int)$p['height'] === 1067, 'photo shrunk to 1600 px on its long side');
$ok(str_starts_with($path, $tmp . '/tasks/') && preg_match('#/\d{4}/\d{2}/[a-f0-9]{24}\.webp$#', $path) && getimagesize($path)[2] === IMAGETYPE_WEBP, 'saved as WebP, random name, in the private folder');
$ok(is_file($tmp . '/tasks/.htaccess'), 'private folder has a deny-all .htaccess');
$small = bcrf_task_photo_add($pdo, $tid, $upload($jpeg(800, 600)));
$ok((int)bcrf_task_photo_get($pdo, $small)['width'] === 800, 'small photo kept at its size');
$bad = $tmp . '/not_a_photo.jpg'; file_put_contents($bad, '<?php echo 1;');
$throws(fn() => bcrf_task_photo_add($pdo, $tid, $upload($bad)), 'non-image refused', 'not a photo');
$throws(fn() => bcrf_task_photo_add($pdo, $tid, ['name' => 'x.jpg', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]), 'too-big upload explained', 'too big');
$ok(bcrf_task_photo_path(['file' => '../../etc/passwd']) === '' && bcrf_task_photo_path(['file' => '2026/11/abc.webp']) === '', 'only names this code made map to a file');
$as('Shopkeep', 5);
$throws(fn() => bcrf_task_photo_add($pdo, $tid, $upload($jpeg(100, 100))), "someone who can't see the task can't add a photo", 'not yours');
$as('Staff', 4);
$throws(fn() => bcrf_task_photo_delete($pdo, $small), "another farm hand can't remove your photo", 'whoever added');
$as('Staff', 3);
bcrf_task_photo_delete($pdo, $small);
$ok(count(bcrf_task_photos($pdo, $tid)) === 1, 'your own photo removed');
$throws(fn() => bcrf_task_set($pdo, $tid, 'done', 'Fox tracks by the coop', true, ['q2' => 'cold']), 'bad number refused on finishing', 'number');
$GLOBALS['bcrf_task_followups_made'] = [];
$t = bcrf_task_set($pdo, $tid, 'done', 'Fox tracks by the coop', true, ['q1' => 'yes', 'q2' => '38']);
$ok($t['status'] === 'done' && $t['answers'] === ['q1' => 'yes', 'q2' => '38'], 'finished; answers saved');
$fu = bcrf_task_followups_of($pdo, $tid);
$titles = array_column($fu, 'title');
sort($titles);
$ok($titles === ['Follow up: Any animal hurt?', 'Follow up: Shade'], 'follow-ups: one flagged answer, one unticked step');
$ok(count($GLOBALS['bcrf_task_followups_made']) === 2, 'screen told how many follow-ups were made');
$f0 = $fu[0];
$ok($f0['assignee_role'] === 'Staff' && $f0['priority'] === 'high' && $f0['due_date'] === '2026-11-12' && $f0['related_type'] === 'task' && (int)$f0['related_id'] === $tid && (int)$f0['followup_of'] === $tid, 'follow-up: same group, high, due today, linked back');
$ok(str_contains((string)$fu[0]['description'] . $fu[1]['description'], 'Fox tracks by the coop'), 'the finishing note is on the follow-up');
bcrf_task_set($pdo, $tid, 'reopen');
bcrf_task_set($pdo, $tid, 'done', '', true, ['q1' => 'yes', 'q2' => '38']);
$ok(count(bcrf_task_followups_of($pdo, $tid)) === 2, 'reopened and finished again: no duplicate follow-ups');
$ok(json_decode($one("SELECT answers_json FROM tasks WHERE id = $tid"), true)['q2'] === '38', 'answers kept through reopen');
$throws(fn() => bcrf_task_photo_delete($pdo, $pid), "can't remove a photo from a finished task", 'Reopen');

// -- Saved checklists carry questions to one-off tasks --
$as('administrator', 1);
$throws(fn() => bcrf_tpl_save($pdo, ['title' => 'Empty']), 'checklist needs a step or a question', 'at least one');
$tpl = bcrf_tpl_save($pdo, ['title' => 'Vet visit', 'questions' => "Weight (number, lb)*\nMeds given? (yes/no)", 'photo_rule' => 'optional']);
$ok(bcrf_tpl_get($pdo, $tpl)['photo_rule'] === 'optional', 'checklist with questions only is fine');
$one_off = bcrf_task_create($pdo, ['title' => 'Vet: Daisy', 'template_id' => $tpl, 'photo_rule' => 'optional', 'due_date' => '2026-11-12', 'who' => 'user:3']);
$t = bcrf_task_get($pdo, $one_off);
$ok(count($t['form']) === 2 && $t['photo_rule'] === 'optional', 'task from a saved checklist gets its questions and photo choice');
$plain = bcrf_task_create($pdo, ['title' => 'Fix gate', 'photo_rule' => 'required', 'due_date' => '2026-11-12']);
$ok(bcrf_task_get($pdo, $plain)['photo_rule'] === 'required' && bcrf_task_get($pdo, $plain)['form'] === [], 'one-off task can need a photo');
bcrf_task_update($pdo, $plain, ['title' => 'Fix gate', 'photo_rule' => 'none', 'due_date' => '2026-11-12']);
$ok(bcrf_task_get($pdo, $plain)['photo_rule'] === 'none', 'editing a task changes its photo rule');
$as('Staff', 3);
$throws(fn() => bcrf_task_set($pdo, $one_off, 'done', '', true, []), 'must-answer question checked on a one-off task', 'Weight');
$t = bcrf_task_set($pdo, $one_off, 'done', '', true, ['q1' => '142']);
$ok($t['status'] === 'done' && !bcrf_task_followups_of($pdo, $one_off), 'optional photo not needed; no follow-ups without flags');

// -- Morning list --
$pdo->exec("DELETE FROM tasks"); $pdo->exec("UPDATE task_recurrences SET active = 0");
$GLOBALS['bcrf_tasks_today'] = '2026-11-20'; $GLOBALS['bcrf_tasks_nocache'] = true;
$as('administrator', 1);
$mk = function (string $title, string $who, ?string $date, ?string $time = null) use ($pdo) {
    return bcrf_task_create($pdo, ['title' => $title, 'who' => $who, 'due_date' => $date ?? '', 'due_time' => $time ?? '']);
};
$late1 = $mk('Late for Sam', 'user:3', '2026-11-18');
$mk('Today for Staff', 'role:Staff', '2026-11-20', '09:00');
$mk('Today for anyone', 'anyone', '2026-11-20');
$mk('Tomorrow for Sam', 'user:3', '2026-11-21');
$mk('Someday', 'user:3', null);
$mk('For Kim', 'user:5', '2026-11-20');
bcrf_task_set($pdo, $mk('Done already', 'user:3', '2026-11-20'), 'done', '', true);
$staff = bcrf_task_staff($pdo);
$ok(array_keys($staff) === [1, 2, 3, 4, 5], 'staff for emails: active, can open Tasks (not disabled, not Media)');
bcrf_task_prefs_save($pdo, 4, false, true);   // Ann turns the morning list off
$GLOBALS['mails'] = [];
$r = bcrf_task_digest_run($pdo);
$ok($r['sent'] === 4 && $r['failed'] === 0, 'morning list sent to 4 people (Ann opted out)');
$sam = $mailsTo('s@x.test');
$ok(count($sam) === 1 && str_contains($sam[0][1], '3 farm tasks (1 late)'), 'Sam: late + today in the subject');
$ok(str_contains($sam[0][2], 'Late for Sam') && str_contains($sam[0][2], 'Today for Staff') && str_contains($sam[0][2], 'Today for anyone')
    && !str_contains($sam[0][2], 'Tomorrow') && !str_contains($sam[0][2], 'Someday') && !str_contains($sam[0][2], 'Done already') && !str_contains($sam[0][2], 'For Kim'), 'Sam sees only his, his group\'s and anyone\'s, due by today');
$ok(str_contains($sam[0][2], 'https://farm.test/admin/tasks.php?id=' . $late1), 'each task links to its page');
$ok(!$mailsTo('a@x.test'), 'no list for Ann');
$ok(count($mailsTo('k@x.test')) === 1 && str_contains($mailsTo('k@x.test')[0][1], '2 farm tasks'), 'Kim: hers and anyone\'s');
$GLOBALS['mails'] = [];
$r = bcrf_task_digest_run($pdo);
$ok($r['sent'] === 0 && !$GLOBALS['mails'], 'second run the same day sends nothing');
$pdo->exec("DELETE FROM tasks");
$GLOBALS['bcrf_tasks_today'] = '2026-11-21';
$r = bcrf_task_digest_run($pdo);
$ok($r['sent'] === 0 && $r['empty'] === 4 && !$GLOBALS['mails'], 'nothing due: nobody gets an empty email');

// -- Late tasks --
$GLOBALS['bcrf_tasks_today'] = '2026-11-20';
bcrf_task_prefs_save($pdo, 4, false, true);
$feed  = $mk('Morning feed', 'anyone', '2026-11-20', '07:30');
$sam9  = $mk('Sam at 9', 'user:3', '2026-11-20', '09:00');
$noTime = $mk('Order hay', 'role:Staff', '2026-11-19');
$old   = $mk('Old one', 'user:3', '2026-11-17', '08:00');
$later = $mk('Later today', 'user:3', '2026-11-20', '15:00');
$GLOBALS['mails'] = [];
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 06:45:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r === ['late' => 0, 'escalated' => 0, 'emails' => 0], 'quiet before 7 AM');
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 07:50:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r['late'] === 0 && $r['escalated'] === 1 && !$mailsTo('s@x.test'), '7:50: feed only 20 min late (no nudge yet); yesterday\'s date-only task goes to managers');
$ok(count($mailsTo('o@x.test')) === 1 && count($mailsTo('m@x.test')) === 1 && str_contains($mailsTo('o@x.test')[0][2], 'Order hay'), 'managers (Owner, Manager) told about the date-only task');
$GLOBALS['mails'] = [];
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 08:05:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r['late'] === 1 && $r['escalated'] === 0, '8:05: feed 35 min late → nudged');
$ok(count($GLOBALS['mails']) === 5 && str_starts_with($mailsTo('s@x.test')[0][1], 'Late: Morning feed'), '"anyone" task: every staff member told (5)');
$ok(!array_filter($GLOBALS['mails'], fn($m) => str_contains($m[2], 'Old one')), 'tasks from days ago are left alone');
$GLOBALS['mails'] = [];
$r = bcrf_task_alerts_run($pdo);
$ok($r['late'] === 0 && !$GLOBALS['mails'], 'running again sends nothing new');
bcrf_task_prefs_save($pdo, 3, true, false);   // Sam turns late emails off
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 09:40:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r['late'] === 1 && !array_filter($mailsTo('s@x.test'), fn($m) => str_starts_with($m[1], 'Late')), '9:40: Sam\'s task late, but Sam opted out of late emails');
$ok($r['escalated'] === 1 && count($mailsTo('o@x.test')) === 1 && str_contains($mailsTo('o@x.test')[0][1], 'Still not done: Morning feed'), '9:40: feed 2h10 late → managers');
$GLOBALS['mails'] = [];
$as('administrator', 1);
bcrf_task_set($pdo, $sam9, 'done', '', true);
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 09:45:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r === ['late' => 0, 'escalated' => 0, 'emails' => 0], '9:45: nothing new (feed already escalated; Sam at 9 finished, so never escalated)');
$ok($one("SELECT escalated_at IS NULL FROM tasks WHERE id = $sam9") == 1, 'finished task never reaches the managers');
$GLOBALS['mails'] = [];
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 21:30:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r === ['late' => 0, 'escalated' => 0, 'emails' => 0], 'quiet after 9 PM (Later today waits)');
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 17:00:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r['late'] === 1 && $r['escalated'] === 1, '5 PM: Later today 2h late → nudged and sent to managers on the same run');
$ok($one("SELECT late_notified_at IS NOT NULL FROM tasks WHERE id = $later") == 1 && $one("SELECT late_notified_at IS NULL FROM tasks WHERE id = $old") == 1, 'flags set on the right tasks');
$GLOBALS['mails'] = [];
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 17:05:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r === ['late' => 0, 'escalated' => 0, 'emails' => 0], '5:05 PM: each email only once');
$pdo->exec("UPDATE site_settings SET setting_value = '10' WHERE setting_key = 'task_late_after_min'");
$quick = $mk('Quick one', 'user:4', '2026-11-20', '16:50');
$GLOBALS['mails'] = [];
$GLOBALS['bcrf_tasks_now'] = '2026-11-20 17:01:00';
$r = bcrf_task_alerts_run($pdo);
$ok($r['late'] === 1 && count($mailsTo('a@x.test')) === 1, 'a changed setting (10 min) is used');

@array_map('unlink', glob($tmp . '/tasks/*/*/*') ?: []);
echo ($fails ? "$fails of $n checks FAILED\n" : "All $n checks passed\n");
exit($fails ? 1 : 0);
