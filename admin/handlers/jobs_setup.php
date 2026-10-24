<?php
// admin/handlers/jobs_setup.php
// Saves for the Hiring setup screens: categories, question bank, job editor,
// hiring rounds (cycles) and the questions attached to each job.
// Administrators only (enforced by the POST gate in admin.php and again here).
// Runs inside admin.php, so $pdo, $current_role and the session are in scope.

require_once __DIR__ . '/../../includes/jobs/repo.php';
require_once __DIR__ . '/../includes/audit.php';

$__jobs_go = function (string $qs, string $msg, string $type = 'success') {
    $_SESSION['toast_msg']  = $msg;
    $_SESSION['toast_type'] = $type;
    header('Location: /admin/admin.php?' . $qs);
    exit;
};

if (($current_role ?? '') !== 'administrator') {
    $__jobs_go('view=dashboard', 'Administrator access required.', 'error');
}
if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    $__jobs_go('view=jobs_posts', 'Security token expired. Please try again.', 'error');
}

$act = (string)($_POST['jobs_setup_action'] ?? '');
$id  = (int)($_POST['id'] ?? 0);

// Keep form input so a failed save doesn't lose what was typed.
$__keep = function (string $key) { $_SESSION['jobs_form_old'][$key] = $_POST; };

switch ($act) {

    // ── Categories ──────────────────────────────────────────────────────────
    case 'cat_save': {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        if ($name === '') $__jobs_go('view=jobs_categories', 'Category name is required.', 'error');
        $sort   = (int)($_POST['sort_order'] ?? 0);
        $minors = !empty($_POST['allows_minors']) ? 1 : 0;
        $active = !empty($_POST['is_active']) ? 1 : 0;
        if ($id > 0) {
            // Turning off "allows under 18" is blocked while a job in it still accepts minors.
            if (!$minors) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE category_id = ? AND min_age < 18");
                $st->execute([$id]);
                if ((int)$st->fetchColumn() > 0) {
                    $__jobs_go('view=jobs_categories', 'Some jobs in this category accept under-18s. Change those jobs to 18 and older first.', 'error');
                }
            }
            $pdo->prepare("UPDATE job_categories SET name = ?, sort_order = ?, allows_minors = ?, is_active = ? WHERE id = ?")
                ->execute([$name, $sort, $minors, $active, $id]);
            erp_audit($pdo, 'update', 'job_categories', $id, $name);
            $__jobs_go('view=jobs_categories', 'Category saved.');
        }
        $slug = jobs_unique_slug($pdo, 'job_categories', $name);
        $pdo->prepare("INSERT INTO job_categories (name, slug, sort_order, allows_minors, is_active) VALUES (?, ?, ?, ?, ?)")
            ->execute([$name, $slug, $sort, $minors, $active]);
        erp_audit($pdo, 'create', 'job_categories', (int)$pdo->lastInsertId(), $name);
        $__jobs_go('view=jobs_categories', 'Category added.');
    }

    case 'cat_delete': {
        $st = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE category_id = ?");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) $__jobs_go('view=jobs_categories', 'This category has jobs. Move or delete them first, or just untick Active.', 'error');
        $cat = jobs_category($pdo, $id);
        $pdo->prepare("DELETE FROM job_categories WHERE id = ?")->execute([$id]);
        if ($cat) erp_audit($pdo, 'delete', 'job_categories', $id, $cat['name']);
        $__jobs_go('view=jobs_categories', 'Category deleted.');
    }

    // ── Question bank ───────────────────────────────────────────────────────
    case 'q_save': {
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 300);
        $help  = mb_substr(trim((string)($_POST['help_text'] ?? '')), 0, 500) ?: null;
        $type  = array_key_exists($_POST['type'] ?? '', jobs_question_type_labels()) ? $_POST['type'] : 'short_text';
        $back  = 'view=jobs_questions' . ($id ? '&edit=' . $id : '&new=1');
        if ($label === '') { $__keep('q'); $__jobs_go($back, 'Question text is required.', 'error'); }

        // Collect options (existing rows by id + new blank rows).
        $isChoice = in_array($type, jobs_choice_types(), true);
        $keepOpts = [];
        foreach ((array)($_POST['opt_label'] ?? []) as $oid => $ol) {
            $ol = mb_substr(trim((string)$ol), 0, 200);
            if ($ol === '' || !empty($_POST['opt_del'][$oid])) continue;
            $keepOpts[] = ['id' => (int)$oid, 'label' => $ol, 'ko' => !empty($_POST['opt_ko'][$oid]) ? 1 : 0];
        }
        $newLabels = (array)($_POST['new_opt_label'] ?? []);
        foreach ($newLabels as $i => $ol) {
            $ol = mb_substr(trim((string)$ol), 0, 200);
            if ($ol === '') continue;
            $keepOpts[] = ['id' => 0, 'label' => $ol, 'ko' => !empty($_POST['new_opt_ko'][$i]) ? 1 : 0];
        }
        if ($isChoice && count($keepOpts) < 2) { $__keep('q'); $__jobs_go($back, 'Pick-one and pick-any questions need at least 2 choices.', 'error'); }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE questions SET label = ?, help_text = ?, type = ? WHERE id = ?")->execute([$label, $help, $type, $id]);
            } else {
                $pdo->prepare("INSERT INTO questions (label, help_text, type) VALUES (?, ?, ?)")->execute([$label, $help, $type]);
                $id = (int)$pdo->lastInsertId();
            }
            if ($isChoice) {
                $keepIds = [];
                $upd = $pdo->prepare("UPDATE question_options SET label = ?, is_knockout = ?, sort_order = ? WHERE id = ? AND question_id = ?");
                $ins = $pdo->prepare("INSERT INTO question_options (question_id, label, is_knockout, sort_order) VALUES (?, ?, ?, ?)");
                foreach ($keepOpts as $n => $o) {
                    if ($o['id'] > 0) { $upd->execute([$o['label'], $o['ko'], ($n + 1) * 10, $o['id'], $id]); $keepIds[] = $o['id']; }
                    else { $ins->execute([$id, $o['label'], $o['ko'], ($n + 1) * 10]); $keepIds[] = (int)$pdo->lastInsertId(); }
                }
                // Remove choices that were deleted. Past answers keep their text snapshot.
                $ph = implode(',', array_fill(0, count($keepIds), '?'));
                $pdo->prepare("DELETE FROM question_options WHERE question_id = ? AND id NOT IN ({$ph})")->execute([$id, ...$keepIds]);
            } else {
                $pdo->prepare("DELETE FROM question_options WHERE question_id = ?")->execute([$id]);
            }
            // Knockout answer on attached jobs only applies to yes/no questions.
            if ($type !== 'yes_no') $pdo->prepare("UPDATE job_questions SET knockout_answer = 'none' WHERE question_id = ?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        unset($_SESSION['jobs_form_old']['q']);
        erp_audit($pdo, $act === 'q_save' && (int)($_POST['id'] ?? 0) ? 'update' : 'create', 'questions', $id, mb_substr($label, 0, 120));
        $ret = (int)($_POST['return_job'] ?? 0);
        if ($ret > 0 && !(int)($_POST['id'] ?? 0)) {
            // Created from the job editor: attach it to that job straight away.
            $mx = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM job_questions WHERE job_id = ?");
            $mx->execute([$ret]);
            $pdo->prepare("INSERT IGNORE INTO job_questions (job_id, question_id, sort_order, is_required) VALUES (?, ?, ?, 1)")
                ->execute([$ret, $id, (int)$mx->fetchColumn()]);
            $__jobs_go('view=jobs_posts&edit=' . $ret . '#questions', 'Question created and added to this job.');
        }
        $__jobs_go('view=jobs_questions', 'Question saved.');
    }

    case 'q_archive':
    case 'q_unarchive': {
        $arch = $act === 'q_archive' ? 1 : 0;
        $pdo->prepare("UPDATE questions SET is_archived = ? WHERE id = ?")->execute([$arch, $id]);
        $q = jobs_question($pdo, $id);
        if ($q) erp_audit($pdo, 'update', 'questions', $id, mb_substr($q['label'], 0, 100) . ($arch ? ' - archived' : ' - restored'));
        $msg = $arch ? 'Question archived. It stays on past applications; remove it from any open job you no longer want it on.' : 'Question restored.';
        $__jobs_go('view=jobs_questions', $msg);
    }

    case 'q_starters': {
        $have = (int)$pdo->query("SELECT COUNT(*) FROM questions")->fetchColumn();
        if ($have > 0) $__jobs_go('view=jobs_questions', 'The question bank already has questions.', 'error');
        $insQ = $pdo->prepare("INSERT INTO questions (label, help_text, type) VALUES (?, ?, ?)");
        $insO = $pdo->prepare("INSERT INTO question_options (question_id, label, is_knockout, sort_order) VALUES (?, ?, ?, ?)");
        foreach (jobs_starter_questions() as [$l, $h, $t, $opts]) {
            $insQ->execute([$l, $h, $t]);
            $qid = (int)$pdo->lastInsertId();
            foreach ($opts as $n => [$ol, $ko]) $insO->execute([$qid, $ol, $ko, ($n + 1) * 10]);
        }
        erp_audit($pdo, 'create', 'questions', null, 'Starter questions added');
        $__jobs_go('view=jobs_questions', 'Starter questions added. Edit or archive any you don\'t want.');
    }

    // ── Jobs ────────────────────────────────────────────────────────────────
    case 'job_save': {
        [$data, $errs] = jobs_validate_job($pdo, $_POST);
        if ($errs) {
            $__keep('job');
            $__jobs_go('view=jobs_posts&edit=' . ($id ?: 'new'), implode(' ', $errs), 'error');
        }
        $slugIn = trim((string)($_POST['slug'] ?? ''));
        $isNew  = $id === 0;
        $id = jobs_save_job($pdo, $id, $data, $slugIn);
        unset($_SESSION['jobs_form_old']['job']);
        erp_audit($pdo, $isNew ? 'create' : 'update', 'jobs', $id, $data['title']);
        $__jobs_go('view=jobs_posts&edit=' . $id, $isNew ? 'Job saved. Add questions below, then tap Open hiring when you\'re ready to post it.' : 'Job saved.');
    }

    case 'job_delete': {
        $st = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE job_id = ?");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) $__jobs_go('view=jobs_posts&edit=' . $id, 'This job has applications, so it can\'t be deleted (records must be kept). Close hiring instead.', 'error');
        $job = jobs_find($pdo, $id);
        $pdo->prepare("DELETE FROM jobs WHERE id = ?")->execute([$id]);   // cycles + attached questions cascade
        if ($job) erp_audit($pdo, 'delete', 'jobs', $id, $job['title']);
        $__jobs_go('view=jobs_posts', 'Job deleted.');
    }

    case 'job_duplicate': {
        $job = jobs_find($pdo, $id);
        if (!$job) $__jobs_go('view=jobs_posts', 'Job not found.', 'error');
        $copy = array_intersect_key($job, array_flip(['category_id', 'engagement_type', 'schedule_type', 'summary', 'description', 'responsibilities',
            'requirements', 'physical_requirements', 'schedule_notes', 'pay_min', 'pay_max', 'pay_unit', 'work_trade_terms', 'work_trade_hourly_value',
            'min_age', 'default_openings']));
        $copy['title'] = mb_substr($job['title'] . ' (copy)', 0, 150);
        $newId = jobs_save_job($pdo, 0, $copy, '');
        $pdo->prepare("INSERT INTO job_questions (job_id, question_id, sort_order, is_required, knockout_answer)
                       SELECT ?, question_id, sort_order, is_required, knockout_answer FROM job_questions WHERE job_id = ?")->execute([$newId, $id]);
        erp_audit($pdo, 'create', 'jobs', $newId, $copy['title']);
        $__jobs_go('view=jobs_posts&edit=' . $newId, 'Copy made. Rename it and adjust anything that\'s different.');
    }

    // ── Hiring rounds ───────────────────────────────────────────────────────
    case 'job_open': {
        $job = jobs_find($pdo, $id);
        if (!$job) $__jobs_go('view=jobs_posts', 'Job not found.', 'error');
        $closes = jobs_parse_closes($_POST['closes_at'] ?? '');
        if ($closes === false) $__jobs_go('view=jobs_posts&edit=' . $id, 'Closing date must be today or later (or leave it blank).', 'error');
        if (!(int)$job['allows_minors'] && (int)$job['min_age'] < 18) $__jobs_go('view=jobs_posts&edit=' . $id, 'This job is set below 18 but its category is 18 and older. Fix the minimum age first.', 'error');
        $openings = (int)($_POST['openings'] ?? $job['default_openings']);
        try {
            jobs_open_cycle($pdo, $id, $openings, $closes, !empty($_POST['auto_close']));
        } catch (RuntimeException $e) {
            $__jobs_go('view=jobs_posts&edit=' . $id, $e->getMessage(), 'error');
        }
        erp_audit($pdo, 'update', 'jobs', $id, $job['title'] . ' - hiring opened (' . max(1, $openings) . ' opening' . ($openings > 1 ? 's' : '') . ')');
        $msg = 'Hiring is open and the job is on the public Jobs page.';
        if (is_file(__DIR__ . '/../../includes/jobs/alerts.php')) {          // Jobs pack P7
            require_once __DIR__ . '/../../includes/jobs/alerts.php';
            try {
                $nc = jobs_notify_counts($pdo, $id);
                $n  = $nc['alerts'] + $nc['pool'];
                if ($n) $msg .= ' ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . ' can be told about it. Check the posting, then tap Let people know.';
            } catch (Throwable $e) { error_log('[jobs notify counts] ' . $e->getMessage()); }
        }
        $__jobs_go('view=jobs_posts&edit=' . $id . '#notify', $msg);
    }

    case 'job_close': {
        $job = jobs_find($pdo, $id);
        if (!$job) $__jobs_go('view=jobs_posts', 'Job not found.', 'error');
        $cid = jobs_close_cycle($pdo, $id);
        erp_audit($pdo, 'update', 'jobs', $id, $job['title'] . ' - hiring closed');
        $back = (($_POST['from'] ?? '') === 'list') ? 'view=jobs_posts' : 'view=jobs_posts&edit=' . $id;
        $__jobs_go($back, $cid ? 'Hiring closed. Applications from this round stay in the pipeline.' : 'Job turned off.');
    }

    case 'cycle_update': {
        $cy = jobs_open_cycle_row($pdo, $id);
        if (!$cy) $__jobs_go('view=jobs_posts&edit=' . $id, 'No open hiring round to update.', 'error');
        $closes = jobs_parse_closes($_POST['closes_at'] ?? '');
        if ($closes === false) $__jobs_go('view=jobs_posts&edit=' . $id, 'Closing date must be today or later (or leave it blank).', 'error');
        $openings = (int)($_POST['openings'] ?? 1);
        if ($openings < (int)$cy['hires_count']) $openings = (int)$cy['hires_count'];
        jobs_update_cycle($pdo, (int)$cy['id'], $openings, $closes, !empty($_POST['auto_close']));
        erp_audit($pdo, 'update', 'job_cycles', (int)$cy['id'], 'Openings ' . $openings . ($closes ? ', closes ' . $closes : ''));
        $__jobs_go('view=jobs_posts&edit=' . $id, 'Hiring round updated.');
    }

    // ── Questions on a job ──────────────────────────────────────────────────
    case 'jq_add': {
        $qid = (int)($_POST['question_id'] ?? 0);
        $q = $qid ? jobs_question($pdo, $qid) : null;
        if (!$q || (int)$q['is_archived']) $__jobs_go('view=jobs_posts&edit=' . $id . '#questions', 'Pick a question to add.', 'error');
        $mx = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 10 FROM job_questions WHERE job_id = ?");
        $mx->execute([$id]);
        $pdo->prepare("INSERT IGNORE INTO job_questions (job_id, question_id, sort_order, is_required) VALUES (?, ?, ?, 1)")
            ->execute([$id, $qid, (int)$mx->fetchColumn()]);
        $__jobs_go('view=jobs_posts&edit=' . $id . '#questions', 'Question added.');
    }

    case 'jq_save': {
        // Bulk save of Required + knockout for every attached question.
        $req = (array)($_POST['jq_required'] ?? []);
        $ko  = (array)($_POST['jq_knockout'] ?? []);
        $st  = $pdo->prepare("SELECT jq.id, q.type FROM job_questions jq JOIN questions q ON q.id = jq.question_id WHERE jq.job_id = ?");
        $st->execute([$id]);
        $up = $pdo->prepare("UPDATE job_questions SET is_required = ?, knockout_answer = ? WHERE id = ? AND job_id = ?");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['type'] === 'yes_no' && in_array($ko[$r['id']] ?? 'none', ['yes', 'no'], true) ? $ko[$r['id']] : 'none';
            $up->execute([!empty($req[$r['id']]) ? 1 : 0, $k, $r['id'], $id]);
        }
        $job = jobs_find($pdo, $id);
        if ($job) erp_audit($pdo, 'update', 'jobs', $id, $job['title'] . ' - questions updated');
        $__jobs_go('view=jobs_posts&edit=' . $id . '#questions', 'Questions saved.');
    }

    case 'jq_move': {
        $jq  = (int)($_POST['jq_id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        jobs_renumber_job_questions($pdo, $id);
        $st = $pdo->prepare("SELECT id, sort_order FROM job_questions WHERE job_id = ? ORDER BY sort_order");
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $i => $r) {
            if ((int)$r['id'] !== $jq) continue;
            $j = $i + $dir;
            if (!isset($rows[$j])) break;
            $up = $pdo->prepare("UPDATE job_questions SET sort_order = ? WHERE id = ?");
            $up->execute([$rows[$j]['sort_order'], $r['id']]);
            $up->execute([$r['sort_order'], $rows[$j]['id']]);
            break;
        }
        header('Location: /admin/admin.php?view=jobs_posts&edit=' . $id . '#questions');
        exit;
    }

    case 'jq_remove': {
        $pdo->prepare("DELETE FROM job_questions WHERE id = ? AND job_id = ?")->execute([(int)($_POST['jq_id'] ?? 0), $id]);
        jobs_renumber_job_questions($pdo, $id);
        $__jobs_go('view=jobs_posts&edit=' . $id . '#questions', 'Question removed from this job (it stays in the bank).');
    }
}

$__jobs_go('view=jobs_posts', 'Unknown action.', 'error');
