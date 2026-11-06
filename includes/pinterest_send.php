<?php
// includes/pinterest_send.php — posting approved Pins and auto-posting new content (Part B, Nov 5, 2026).
//
//   bcrf_pin_queue_send($pdo, $targetId)   approved/failed → scheduled, and queue job pinterest.send
//   bcrf_pin_send($pdo, $targetId)         the job: posts one Pin. Returns ['result' => sent|retry|paused|failed|skipped, 'msg']
//   bcrf_pin_autopost($pdo, 'recipes', 12, $eventId)   job pinterest.autopost, from the content.published event
//
// Sending is idempotent: a row is claimed (scheduled → sending) before the API call, so two runners can't post it
// twice, and a sent row is never sent again (one Pin per record; uq_external guards the Pin id). Errors:
//   429 / 503 / couldn't connect      → back to scheduled, retried by the job queue (at most 5 tries)
//   timeout / 500 / 502 / 504          → failed with code "ambiguous": Pinterest may have made the Pin, so it is NOT
//                                        retried automatically; check Pinterest, then retry or record the Pin by hand
//   401 (sign-in) / missing board      → paused: back to approved with the reason; fix it and press Send again
//   other 4xx                          → failed with Pinterest's message
// Auto-posting only happens when Settings › Pinterest has it switched on for that content type, only for a record's
// FIRST publication (no Pin row yet and no earlier content.published event for it), never for edits or bulk drafts.

if (!function_exists('bcrf_pin_send')) {

require_once __DIR__ . '/pinterest.php';
require_once __DIR__ . '/adapter_pinterest.php';

define('BCRF_PIN_SEND_MAX_TRIES', 5);

function bcrf_pin_audit(PDO $pdo, string $action, int $id, string $label, array $after = []): void {
    if (!function_exists('bcrf_audit_event')) {
        $f = dirname(__DIR__) . '/admin/includes/audit.php';
        if (is_file($f)) require_once $f;
    }
    if (!function_exists('bcrf_audit_event')) return;
    $uid = PHP_SAPI !== 'cli' ? (int)($_SESSION['user_id'] ?? 0) : 0;
    bcrf_audit_event($pdo, ['actor_type' => $uid ? 'staff' : 'system', 'actor_id' => $uid ?: null, 'actor_label' => $uid ? (string)($_SESSION['username'] ?? '') : 'Pinterest',
        'action' => $action, 'object_type' => 'social_queue_targets', 'object_id' => (string)$id, 'object_label' => mb_substr($label, 0, 200), 'after' => $after ?: null]);
}

/** Board id for a Pin row: its own override, else the Settings board for its type. ['', reason] when unusable. */
function bcrf_pin_board_for(PDO $pdo, array $row): array {
    $id = trim((string)($row['board_id'] ?? ''));
    if ($id === '') $id = bcrf_pin_setting($pdo, 'pinterest_board_id_' . ($row['board_key'] ?: 'recipes'));
    if ($id === '') return ['', 'No Pinterest board chosen for ' . ($row['board_key'] ?: 'this type') . ' (Settings › Pinterest)'];
    $boards = bcrf_pinapi_boards($pdo);
    if (!isset($boards[$id])) return ['', 'The chosen board is not on the connected Pinterest account any more. Sync boards and pick again.'];
    return [$id, $boards[$id]];
}

/** Move a row to scheduled and queue the send job. Returns '' or why it can't be sent now. */
function bcrf_pin_queue_send(PDO $pdo, int $id): string {
    if (!bcrf_pinapi_ready($pdo)) return 'Pinterest is not connected (Settings › Pinterest).';
    $st = $pdo->prepare("SELECT * FROM social_queue_targets WHERE id = ? AND platform = 'pinterest'");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'Pin not found.';
    [$board, $why] = bcrf_pin_board_for($pdo, $row);
    if ($board === '') return $why;
    $u = $pdo->prepare("UPDATE social_queue_targets SET status = 'scheduled', scheduled_for = UTC_TIMESTAMP(), last_error_code = NULL, last_error = NULL,
                            attempts = IF(status = 'failed', 0, attempts), updated_at = UTC_TIMESTAMP()
                        WHERE id = ? AND status IN ('approved', 'failed')");
    $u->execute([$id]);
    if (!$u->rowCount()) return 'Only approved or failed Pins can be sent.';
    if (!function_exists('bcrf_queue_push')) require_once __DIR__ . '/queue.php';
    if (!bcrf_queue_push('pinterest.send', ['target_id' => $id], ['dedupe' => 'pinterest.send:' . $id, 'max_attempts' => BCRF_PIN_SEND_MAX_TRIES + 1])) {
        // no job queue (migration 0004 not run): send right now instead
        $r = bcrf_pin_send($pdo, $id);
        return $r['result'] === 'sent' ? '' : $r['msg'];
    }
    return '';
}

/** Post one scheduled Pin. Used by job pinterest.send. */
function bcrf_pin_send(PDO $pdo, int $id): array {
    $claim = $pdo->prepare("UPDATE social_queue_targets SET status = 'sending', attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
                            WHERE id = ? AND platform = 'pinterest' AND status = 'scheduled'");
    $claim->execute([$id]);
    if (!$claim->rowCount()) return ['result' => 'skipped', 'msg' => 'Not waiting to be sent (already sent, cancelled or being sent).'];
    $st = $pdo->prepare("SELECT * FROM social_queue_targets WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $set = function (string $status, ?string $code, ?string $err) use ($pdo, $id): void {
        $pdo->prepare("UPDATE social_queue_targets SET status = ?, last_error_code = ?, last_error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$status, $code, $err !== null ? mb_substr($err, 0, 1000) : null, $id]);
    };
    $label = 'Pinterest: ' . $row['title'];

    // Still public? Image still there?
    $why = bcrf_pin_ineligible($row['content_type'], bcrf_pin_record($pdo, $row['content_type'], (int)$row['content_id']));
    if ($why !== '') { $set('failed', 'ineligible', $why . ' — not posted.'); return ['result' => 'failed', 'msg' => $why]; }
    $img = bcrf_pin_image_abs($row['image_path']);
    if (!$img || !is_file($img)) { $set('approved', 'image', 'Image missing: move it back to draft and regenerate.'); return ['result' => 'paused', 'msg' => 'Image missing']; }
    [$board, $boardWhy] = bcrf_pin_board_for($pdo, $row);
    if ($board === '') { $set('approved', 'board', $boardWhy); return ['result' => 'paused', 'msg' => $boardWhy]; }

    try {
        $r = bcrf_pinapi_create_pin($pdo, $board, $row, $img);
    } catch (Throwable $e) {   // signed out, needs reconnect, lock busy …
        $set('approved', 'auth', $e->getMessage());
        return ['result' => 'paused', 'msg' => $e->getMessage()];
    }

    $s = (int)$r['status'];
    if ($s >= 200 && $s < 300 && !empty($r['json']['id'])) {
        $pinId = (string)$r['json']['id'];
        try {
            $pdo->prepare("UPDATE social_queue_targets SET status = 'sent', external_id = ?, external_url = ?, sent_at = UTC_TIMESTAMP(),
                               last_error_code = NULL, last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$pinId, 'https://www.pinterest.com/pin/' . rawurlencode($pinId) . '/', $id]);
        } catch (PDOException $e) {   // that Pin id is already recorded elsewhere: keep the post, report it
            $set('sent', 'duplicate_id', 'Posted as Pin ' . $pinId . ', which is already recorded on another row.');
        }
        bcrf_pin_audit($pdo, 'publish', $id, $label, ['pin_id' => $pinId, 'board_id' => $board]);
        return ['result' => 'sent', 'msg' => 'Posted (Pin ' . $pinId . ')'];
    }

    $msg = bcrf_pinapi_error($r);
    $connectFail = $s === 0 && preg_match('/resolve|connect|refused|SSL/i', (string)$r['error']) && !preg_match('/timed? ?out/i', (string)$r['error']);
    if ($s === 429 || $s === 503 || $connectFail) {
        if ((int)$row['attempts'] >= BCRF_PIN_SEND_MAX_TRIES) { $set('failed', 'gave_up', $msg . ' (gave up after ' . (int)$row['attempts'] . ' tries)'); return ['result' => 'failed', 'msg' => $msg]; }
        $set('scheduled', 'retry', $msg . ' — will retry');
        return ['result' => 'retry', 'msg' => $msg];
    }
    if ($s === 0 || in_array($s, [500, 502, 504], true)) {
        $set('failed', 'ambiguous', $msg . ' — Pinterest may or may not have made the Pin. Check the board before retrying.');
        return ['result' => 'failed', 'msg' => $msg];
    }
    if ($s === 401) { $set('approved', 'auth', $msg . ' — reconnect Pinterest, then Send again.'); return ['result' => 'paused', 'msg' => $msg]; }
    $hint = (stripos($msg, 'consumer type') !== false || $s === 403) ? ' (While the app has Trial access, use the Sandbox environment in Settings › Pinterest.)' : '';
    $set('failed', 'rejected', $msg . $hint);
    return ['result' => 'failed', 'msg' => $msg . $hint];
}

/** Job pinterest.autopost: first publication of a recipe/animal → draft → approved → queued (if switched on). */
function bcrf_pin_autopost(PDO $pdo, string $table, int $id, int $eventId = 0): string {
    $type = ['recipes' => 'recipe', 'animals' => 'animal'][$table] ?? '';
    if ($type === '' || $id <= 0 || !bcrf_pin_ready($pdo)) return 'not applicable';
    if (bcrf_pin_setting($pdo, 'pinterest_autopost_' . $type) !== '1') return 'auto-posting is off for ' . $type . 's';
    if (bcrf_pin_target($pdo, $type, $id)) return 'already has a Pin draft (not a first publication)';
    if ($eventId > 0) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM domain_events WHERE type = 'content.published' AND object_type = ? AND object_id = ? AND id < ?");
        $st->execute([$table, (string)$id, $eventId]);
        if ((int)$st->fetchColumn() > 0) return 'published before (edit, not a first publication)';
    }
    $g = bcrf_pin_generate($pdo, $type, $id, ['origin' => 'auto']);
    if ($g['result'] !== 'created') return 'draft: ' . $g['msg'];
    $pdo->prepare("UPDATE social_queue_targets SET status = 'approved', approved_at = UTC_TIMESTAMP(), approved_by = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'draft'")
        ->execute([$g['id']]);
    bcrf_pin_audit($pdo, 'approve', (int)$g['id'], 'Pinterest auto-post: ' . $type . ' #' . $id);
    $why = bcrf_pin_queue_send($pdo, (int)$g['id']);
    if ($why !== '') {
        $pdo->prepare("UPDATE social_queue_targets SET last_error_code = 'paused', last_error = ? WHERE id = ? AND status = 'approved'")->execute([$why, $g['id']]);
        return 'approved, not sent: ' . $why;
    }
    return 'queued';
}

}
