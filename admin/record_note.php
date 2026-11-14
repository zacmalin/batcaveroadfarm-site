<?php
// admin/record_note.php — save, hide or bring back a dated note on a record (Nov 14, 2026; includes/record_tools.php, migration 0067).
//   POST act=add     type, id, body, back     add a note to a customer, organization, vendor, animal or party
//   POST act=hide | unhide   note_id, back    the writer (same day) or an Owner
// No page of its own: it always goes back to the record page (back=) with a message.
// Permission: the same as opening that kind of record (includes/record_tools.php bcrf_record_kinds()).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/record_tools.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: /admin/'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$back = bcrf_record_safe_back($_POST['back'] ?? '') ?: '/admin/';
$uid = bcrf_record_uid();
$act = (string)($_POST['act'] ?? '');
$flash = ['ok', 'Saved.'];
try {
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) throw new BcrfRecordError('Security token expired. Reload the page and try again.');
    if ($act === 'add') {
        $type = (string)($_POST['type'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $nid = bcrf_record_note_add($pdo, $type, $id, (string)($_POST['body'] ?? ''), $uid);
        if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'record_notes', $nid, 'Note on ' . bcrf_record_label($pdo, $type, $id));
        $flash = ['ok', 'Note saved.'];
        if (!str_contains($back, '#')) $back .= '#notes';
    } elseif ($act === 'hide' || $act === 'unhide') {
        $n = bcrf_record_note_hide($pdo, (int)($_POST['note_id'] ?? 0), $act === 'hide', $uid);
        if (function_exists('erp_audit')) erp_audit($pdo, $act === 'hide' ? 'delete' : 'update', 'record_notes', (int)$n['id'],
                                                    ($act === 'hide' ? 'Note hidden on ' : 'Note brought back on ') . bcrf_record_label($pdo, (string)$n['record_type'], (int)$n['record_id']));
        $flash = ['ok', $act === 'hide' ? 'Note hidden.' : 'Note brought back.'];
        if (!str_contains($back, '#')) $back .= '#notes';
    } else {
        throw new BcrfRecordError('Unknown action.');
    }
} catch (BcrfRecordError $e) {
    $flash = ['err', $e->getMessage()];
} catch (Throwable $e) {
    error_log('[record_note] ' . $act . ': ' . $e->getMessage());
    $flash = ['err', 'Could not save the note. Try again.'];
}
$key = bcrf_record_flash_key($back);
if ($key !== '') $_SESSION[$key] = $flash;
header('Location: ' . $back);
exit;
