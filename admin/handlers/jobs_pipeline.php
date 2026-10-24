<?php
// admin/handlers/jobs_pipeline.php
// Saves for Hiring > Applicants: move status (one or many), notes, spam, archive.
// Administrators only (enforced by the POST gate in admin.php and again here).
// Runs inside admin.php, so $pdo, $current_role and the session are in scope.

require_once __DIR__ . '/../../includes/jobs/pipeline.php';
require_once __DIR__ . '/../includes/audit.php';

// Where to go afterwards: back to the page the form came from (admin only), else the list.
$__jp_back = (string)($_POST['back'] ?? '');
if (!preg_match('#^view=jobs(&[A-Za-z0-9_%=&.\-+]*)?(\#[a-z]+)?$#', $__jp_back)) $__jp_back = 'view=jobs';

$__jp_go = function (string $msg, string $type = 'success', ?string $qs = null) use ($__jp_back) {
    $_SESSION['toast_msg']  = $msg;
    $_SESSION['toast_type'] = $type;
    header('Location: /admin/admin.php?' . ($qs ?? $__jp_back));
    exit;
};

if (($current_role ?? '') !== 'administrator') {
    $__jp_go('Administrator access required.', 'error', 'view=dashboard');
}
if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    $__jp_go('Security token expired. Please try again.', 'error');
}

$act = (string)($_POST['jobs_pipeline_action'] ?? '');
$id  = (int)($_POST['id'] ?? 0);

$__jp_app = function (int $id) use ($pdo, $__jp_go): array {
    $a = $id > 0 ? jobs_app_find($pdo, $id) : null;
    if (!$a) $__jp_go('That application was not found.', 'error', 'view=jobs');
    return $a;
};

switch ($act) {

    // ── One application: move ───────────────────────────────────────────────
    case 'move': {
        $app = $__jp_app($id);
        $to  = (string)($_POST['to'] ?? '');
        $opt = [
            'note'        => (string)($_POST['note'] ?? ''),
            'send_email'  => !empty($_POST['send_email']),
            'queue_email' => !empty($_POST['queue_email']),
        ];
        if ($to === 'interview') {
            $opt['interview_at']       = jobs_parse_interview((string)($_POST['interview_date'] ?? ''), (string)($_POST['interview_time'] ?? ''));
            $opt['interview_location'] = (string)($_POST['interview_location'] ?? '');
        }
        if ($to === 'offer') $opt['offer_details'] = (string)($_POST['offer_details'] ?? '');
        // Interview and Offer need details, so a quick move from the list opens the page instead.
        if (in_array($to, ['interview', 'offer'], true) && empty($_POST['from_detail'])) {
            $__jp_go('Add the ' . ($to === 'interview' ? 'interview time' : 'offer details') . ', then tap Move.', 'success',
                'view=jobs&app=' . (int)$app['id'] . '&to=' . $to . '#move');
        }
        [$ok, $msg] = jobs_move_status($pdo, $app, $to, $opt);
        if (!$ok && !empty($_POST['from_detail'])) {
            $_SESSION['jobs_form_old']['move'] = $_POST;
            $__jp_go($msg, 'error', 'view=jobs&app=' . (int)$app['id'] . '&to=' . rawurlencode($to) . '#move');
        }
        $__jp_go($msg, $ok ? 'success' : 'error');
    }

    // ── Notes ───────────────────────────────────────────────────────────────
    case 'note_add': {
        $app = $__jp_app($id);
        if (!jobs_add_note($pdo, (int)$app['id'], (string)($_POST['body'] ?? ''))) $__jp_go('Type a note first.', 'error');
        $__jp_go('Note added.');
    }
    case 'note_delete': {
        $app  = $__jp_app($id);
        $nid  = (int)($_POST['note_id'] ?? 0);
        $pdo->prepare('DELETE FROM application_notes WHERE id = ? AND application_id = ?')->execute([$nid, (int)$app['id']]);
        $__jp_go('Note deleted.');
    }

    // ── Spam / archive (one) ────────────────────────────────────────────────
    case 'spam':
    case 'not_spam': {
        $app = $__jp_app($id);
        jobs_set_spam($pdo, $app, $act === 'spam');
        erp_audit($pdo, 'update', 'applications', (int)$app['id'], trim($app['first_name'] . ' ' . $app['last_name']) . ($act === 'spam' ? ': marked spam' : ': not spam'));
        $__jp_go($act === 'spam' ? 'Marked as spam and hidden. Any held emails to them were cancelled.' : 'No longer marked as spam.');
    }
    case 'archive':
    case 'unarchive': {
        $app = $__jp_app($id);
        jobs_set_archived($pdo, $app, $act === 'archive');
        erp_audit($pdo, 'update', 'applications', (int)$app['id'], trim($app['first_name'] . ' ' . $app['last_name']) . ($act === 'archive' ? ': archived' : ': unarchived'));
        $__jp_go($act === 'archive' ? 'Archived. Find it under Show: Archived.' : 'Brought back from the archive.');
    }

    // ── Many at once ────────────────────────────────────────────────────────
    case 'bulk': {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0)));
        if (!$ids) $__jp_go('Tick at least one application first.', 'error');
        if (count($ids) > 300) $__jp_go('Too many at once (300 max).', 'error');
        $do   = (string)($_POST['bulk_do'] ?? '');
        $note = trim((string)($_POST['bulk_note'] ?? ''));
        $done = 0; $skipped = [];

        foreach ($ids as $aid) {
            $app = jobs_app_find($pdo, $aid);
            if (!$app) continue;
            $nm = trim($app['first_name'] . ' ' . $app['last_name']);
            if (str_starts_with($do, 'status:')) {
                $to = substr($do, 7);
                if (!in_array($to, jobs_bulk_statuses(), true)) $__jp_go('Pick what to do.', 'error');
                if ($app['status'] === $to) { $done++; continue; }
                [$ok, $m] = jobs_move_status($pdo, $app, $to, ['note' => $note, 'queue_email' => !empty($_POST['queue_email'])]);
                if ($ok) $done++; else $skipped[] = $nm;
            } elseif ($do === 'note') {
                if ($note === '') $__jp_go('Type the note to add.', 'error');
                jobs_add_note($pdo, $aid, $note); $done++;
            } elseif ($do === 'archive' || $do === 'unarchive') {
                if (($do === 'archive') === ($app['archived_at'] !== null)) { $done++; continue; }
                jobs_set_archived($pdo, $app, $do === 'archive'); $done++;
            } elseif ($do === 'spam' || $do === 'not_spam') {
                if (($do === 'spam') === (bool)$app['is_spam']) { $done++; continue; }
                jobs_set_spam($pdo, $app, $do === 'spam'); $done++;
            } else {
                $__jp_go('Pick what to do.', 'error');
            }
        }
        $labels = ['note' => 'Note added to', 'archive' => 'Archived', 'unarchive' => 'Brought back', 'spam' => 'Marked as spam:', 'not_spam' => 'Not spam:'];
        $what   = str_starts_with($do, 'status:') ? 'Moved ' . $done . ' to ' . (jobs_status_labels()[substr($do, 7)] ?? '') : ($labels[$do] ?? 'Updated') . ' ' . $done;
        if (!str_starts_with($do, 'status:')) $what .= $done === 1 ? ' application' : ' applications';
        $what .= '.';
        if ($do === 'status:not_selected' && !empty($_POST['queue_email'])) $what .= ' Their "not selected" emails are waiting in the queue (nothing sent).';
        if ($skipped) $what .= ' Skipped: ' . implode(', ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '…' : '') . ' (hired or spam).';
        erp_audit($pdo, 'update', 'applications', null, 'Bulk ' . $do . ': ' . count($ids) . ' applications');
        $__jp_go($what, $skipped && !$done ? 'error' : 'success');
    }

    default:
        $__jp_go('Unknown action.', 'error');
}
