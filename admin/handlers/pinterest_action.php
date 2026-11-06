<?php
// admin/handlers/pinterest_action.php — Pinterest drafts screen actions (Part A, Nov 5, 2026).
// Included by admin/views/pinterest.php (Social Queue › Pinterest) for POST pinterest_action. admin.php has
// already checked the CSRF token and the "social" screen permission.
// Anyone who can open Social Queue can generate, edit, regenerate and skip drafts. Approving, un-approving
// and recording a Pin you posted by hand need administrator or moderator (spec: publish permission), and so do
// sending, retrying and choosing a board (Part B). Posting itself happens in the job pinterest.send.

require_once dirname(__DIR__, 2) . '/includes/pinterest.php';
require_once dirname(__DIR__, 2) . '/includes/queue.php';
require_once dirname(__DIR__, 2) . '/includes/pinterest_send.php';
require_once __DIR__ . '/../includes/audit.php';

$pinBack = function (string $msg, string $type = 'success'): void {
    $_SESSION['toast_msg'] = $msg; $_SESSION['toast_type'] = $type;
    $f = preg_replace('/[^a-z_]/', '', (string)($_POST['back'] ?? ''));
    $t = preg_replace('/[^a-z]/', '', (string)($_POST['back_type'] ?? ''));
    $anchor = (int)($_POST['id'] ?? 0) > 0 ? '#pin-' . (int)$_POST['id'] : '';
    header('Location: /admin/admin.php?view=social&tab=pinterest' . ($f !== '' ? '&f=' . $f : '') . ($t !== '' ? '&t=' . $t : '') . $anchor);
    exit;
};
if (!bcrf_pin_ready($pdo)) $pinBack('Run migration 0007_pinterest_drafts first (Settings › Database).', 'error');

$act    = (string)($_POST['pinterest_action'] ?? '');
$id     = (int)($_POST['id'] ?? 0);
$uid    = (int)($_SESSION['user_id'] ?? 0) ?: null;
$canPub = in_array($current_role ?? '', ['administrator', 'moderator'], true);
$audit  = function (string $action, int $tid, string $label, array $after = []) use ($pdo, $uid): void {
    bcrf_audit_event($pdo, ['actor_id' => $uid, 'actor_label' => (string)($_SESSION['username'] ?? ''), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'action' => $action, 'object_type' => 'social_queue_targets', 'object_id' => (string)$tid, 'object_label' => mb_substr($label, 0, 200), 'after' => $after ?: null]);
};
$row = null;
if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM social_queue_targets WHERE id = ? AND platform = 'pinterest'");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) $pinBack('That Pin draft no longer exists.', 'error');
}
$label = $row ? 'Pinterest: ' . $row['title'] : 'Pinterest';

switch ($act) {
    case 'start_batch': {
        $types = array_values(array_intersect(['recipe', 'animal'], (array)($_POST['types'] ?? [])));
        if (!$types) $pinBack('Tick recipes, animals or both.', 'error');
        $active = bcrf_pin_batch_active($pdo);
        $bid = bcrf_pin_batch_create($pdo, $types, $uid);
        if ($active) $pinBack('A batch is already running (#' . $bid . '). It continues in the background.', 'error');
        $audit('create', $bid, 'Pinterest bulk drafts batch #' . $bid, ['types' => $types]);
        $r = bcrf_pin_batch_step($pdo, $bid);   // first chunk now, so there is something to look at
        $pinBack('Batch #' . $bid . ' started: ' . $r['worked'] . ' done now' . ($r['done'] ? ', all finished.' : '; the rest runs in the background (or tap “Process next 10”).'));
    }
    case 'run_step': {
        $b = bcrf_pin_batch_active($pdo);
        if (!$b) $pinBack('No batch is running.', 'error');
        $r = bcrf_pin_batch_step($pdo, (int)$b['id']);
        $pinBack($r['worked'] ? 'Processed ' . $r['worked'] . ($r['done'] ? '. Batch finished.' : '. Checkpoint saved.') : $r['msg'], $r['worked'] || $r['done'] ? 'success' : 'error');
    }
    case 'cancel_batch': {
        $b = bcrf_pin_batch_active($pdo);
        if ($b) {
            $pdo->prepare("UPDATE pinterest_batches SET status = 'cancelled', finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$b['id']]);
            $audit('update', (int)$b['id'], 'Pinterest batch #' . $b['id'] . ' cancelled');
        }
        $pinBack($b ? 'Batch cancelled. Drafts already made are kept.' : 'No batch is running.');
    }
    case 'save': {
        if (!in_array($row['status'], ['draft', 'failed'], true)) $pinBack('Only drafts can be edited. Move it back to draft first.', 'error');
        $title = trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? '')));
        $desc  = trim(preg_replace("/[ \t]+/u", ' ', (string)($_POST['description'] ?? '')));
        $alt   = trim(preg_replace('/\s+/u', ' ', (string)($_POST['alt_text'] ?? '')));
        if ($title === '' || mb_strlen($title) > 100) $pinBack('Title must be 1–100 characters.', 'error');
        if (mb_strlen($desc) > 500) $pinBack('Description must be 500 characters or fewer.', 'error');
        if (mb_strlen($alt) > 500) $pinBack('Alt text must be 500 characters or fewer.', 'error');
        $pdo->prepare("UPDATE social_queue_targets SET title = ?, description = ?, alt_text = ?, edited_at = UTC_TIMESTAMP(), edited_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$title, $desc, $alt, $uid, $id]);
        if ($title !== $row['title'] && $row['content_type'] === 'recipe') {   // the picture shows the recipe title
            bcrf_pin_generate($pdo, $row['content_type'], (int)$row['content_id'], ['force' => true, 'actor_id' => $uid]);
        }
        $audit('update', $id, 'Pinterest: ' . $title, ['title' => $title, 'description' => $desc, 'alt_text' => $alt]);
        $pinBack('Saved. Regenerating will keep your text.');
    }
    case 'regenerate':
    case 'reset_text': {
        if (!in_array($row['status'], ['draft', 'failed'], true)) $pinBack('Only drafts can be regenerated.', 'error');
        $r = bcrf_pin_generate($pdo, $row['content_type'], (int)$row['content_id'], ['force' => true, 'reset_text' => $act === 'reset_text', 'actor_id' => $uid, 'origin' => 'manual']);
        if ($r['result'] === 'skipped') {
            $pdo->prepare("UPDATE social_queue_targets SET last_error_code = 'ineligible', last_error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$r['msg'], $id]);
        }
        $audit('update', $id, $label . ' (' . ($act === 'reset_text' ? 'reset text' : 'regenerated') . ')', ['result' => $r['result']]);
        $pinBack($r['msg'], in_array($r['result'], ['created', 'updated', 'unchanged'], true) ? 'success' : 'error');
    }
    case 'approve': {
        if (!$canPub) $pinBack('Only administrators and moderators can approve Pins.', 'error');
        $why = bcrf_pin_ineligible($row['content_type'], bcrf_pin_record($pdo, $row['content_type'], (int)$row['content_id']));
        if ($why !== '') $pinBack("Can't approve: {$why}.", 'error');
        $img = bcrf_pin_image_abs($row['image_path']);
        if (!$img || !is_file($img)) $pinBack("Can't approve: the image is missing. Regenerate first.", 'error');
        $st = $pdo->prepare("UPDATE social_queue_targets SET status = 'approved', approved_at = UTC_TIMESTAMP(), approved_by = ?, updated_at = UTC_TIMESTAMP()
                             WHERE id = ? AND status = 'draft'");
        $st->execute([$uid, $id]);
        if (!$st->rowCount()) $pinBack('Only drafts can be approved.', 'error');
        $audit('approve', $id, $label);
        if (!bcrf_pinapi_ready($pdo)) $pinBack('Approved. It will post once Pinterest is connected (or pin it by hand and record the link).');
        $why = bcrf_pin_queue_send($pdo, $id);
        if ($why !== '') {
            $pdo->prepare("UPDATE social_queue_targets SET last_error_code = 'paused', last_error = ? WHERE id = ? AND status = 'approved'")->execute([$why, $id]);
            $pinBack('Approved, but not sent yet: ' . $why, 'error');
        }
        $pinBack('Approved and queued. It posts within a few minutes.');
    }
    case 'send': {
        if (!$canPub) $pinBack('Only administrators and moderators can send Pins.', 'error');
        $why = bcrf_pin_queue_send($pdo, $id);
        if ($why === '') $audit('update', $id, $label . ' (sent to queue)');
        $pinBack($why === '' ? 'Queued. It posts within a few minutes.' : $why, $why === '' ? 'success' : 'error');
    }
    case 'send_all': {
        if (!$canPub) $pinBack('Only administrators and moderators can send Pins.', 'error');
        $ids = $pdo->query("SELECT id FROM social_queue_targets WHERE platform = 'pinterest' AND status = 'approved' ORDER BY approved_at, id")->fetchAll(PDO::FETCH_COLUMN);
        $n = 0; $why = '';
        foreach ($ids as $tid) { $w = bcrf_pin_queue_send($pdo, (int)$tid); if ($w === '') $n++; else $why = $w; }
        if ($n) $audit('update', 0, 'Pinterest: ' . $n . ' approved Pins sent to queue');
        $pinBack($n ? "Queued {$n} Pin" . ($n === 1 ? '' : 's') . '. They post a few at a time over the next minutes.' . ($why ? ' Some were held: ' . $why : '') : ($why ?: 'Nothing approved to send.'), $n ? 'success' : 'error');
    }
    case 'retry': {
        if (!$canPub) $pinBack('Only administrators and moderators can retry Pins.', 'error');
        $stuck = $row['status'] === 'sending' && strtotime($row['updated_at'] . ' UTC') < time() - 900;
        if ($row['status'] !== 'failed' && !$stuck) $pinBack('Only failed (or stuck) Pins can be retried.', 'error');
        if (($stuck || $row['last_error_code'] === 'ambiguous') && empty($_POST['checked'])) {
            $pinBack('First check the board on Pinterest: if the Pin is already there, record its link instead. Then tick the box and retry.', 'error');
        }
        if ($stuck) $pdo->prepare("UPDATE social_queue_targets SET status = 'failed' WHERE id = ? AND status = 'sending'")->execute([$id]);
        $why = bcrf_pin_queue_send($pdo, $id);
        if ($why === '') $audit('update', $id, $label . ' (retry)');
        $pinBack($why === '' ? 'Queued again.' : $why, $why === '' ? 'success' : 'error');
    }
    case 'set_board': {
        if (!$canPub) $pinBack('Only administrators and moderators can choose boards.', 'error');
        if (!in_array($row['status'], ['draft', 'approved', 'failed'], true)) $pinBack('The board can only change before the Pin is sent.', 'error');
        $b = trim((string)($_POST['board_id'] ?? ''));
        if ($b !== '' && !isset(bcrf_pinapi_boards($pdo)[$b])) $pinBack('That board is not on the connected account. Sync boards in Settings › Pinterest.', 'error');
        $pdo->prepare("UPDATE social_queue_targets SET board_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$b === '' ? null : $b, $id]);
        $audit('update', $id, $label . ' (board)', ['board_id' => $b]);
        $pinBack($b === '' ? 'Using the default board for this type.' : 'Board saved for this Pin.');
    }
    case 'unapprove': {
        if (!$canPub) $pinBack('Only administrators and moderators can do that.', 'error');
        $st = $pdo->prepare("UPDATE social_queue_targets SET status = 'draft', approved_at = NULL, approved_by = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('approved', 'scheduled')");
        $st->execute([$id]);
        if ($st->rowCount()) $audit('update', $id, $label . ' (back to draft)');
        $pinBack($st->rowCount() ? 'Moved back to drafts.' : 'Only approved or queued Pins can be moved back.', $st->rowCount() ? 'success' : 'error');
    }
    case 'skip':
    case 'unskip': {
        $from = $act === 'skip' ? "('draft','failed')" : "('skipped')";
        $st = $pdo->prepare("UPDATE social_queue_targets SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN $from");
        $st->execute([$act === 'skip' ? 'skipped' : 'draft', $id]);
        if ($st->rowCount()) $audit('update', $id, $label . ($act === 'skip' ? ' (skipped)' : ' (restored)'));
        $pinBack($st->rowCount() ? ($act === 'skip' ? 'Skipped. Batches will leave it alone.' : 'Back in drafts.') : 'Nothing changed.');
    }
    case 'mark_pinned': {
        if (!$canPub) $pinBack('Only administrators and moderators can record a posted Pin.', 'error');
        $url = trim((string)($_POST['pin_url'] ?? ''));
        if (!preg_match('#^https://([a-z]{2,3}\.)?(www\.)?pinterest\.[a-z.]{2,6}/pin/([0-9]{5,25})/?#i', $url, $m)) {
            $pinBack('Paste the Pin link, like https://www.pinterest.com/pin/1234567890/', 'error');
        }
        try {
            $st = $pdo->prepare("UPDATE social_queue_targets SET status = 'sent', external_id = ?, external_url = ?, sent_at = UTC_TIMESTAMP(),
                                     approved_at = COALESCE(approved_at, UTC_TIMESTAMP()), approved_by = COALESCE(approved_by, ?), updated_at = UTC_TIMESTAMP()
                                 WHERE id = ? AND status IN ('draft','approved','failed')");
            $st->execute([$m[3], 'https://www.pinterest.com/pin/' . $m[3] . '/', $uid, $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') $pinBack('That Pin is already recorded on another draft.', 'error');
            throw $e;
        }
        if ($st->rowCount()) $audit('update', $id, $label . ' (posted by hand)', ['external_id' => $m[3]]);
        $pinBack($st->rowCount() ? 'Recorded as posted. It won’t be posted again.' : 'Only drafts, approved or failed Pins can be recorded.', $st->rowCount() ? 'success' : 'error');
    }
}
$pinBack('Unknown action.', 'error');
