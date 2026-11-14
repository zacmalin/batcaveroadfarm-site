<?php
// admin/export.php — one place for "Download CSV" on admin lists (Nov 14, 2026; includes/record_tools.php bcrf_csv_send()).
//   GET export.php?what=customers [&q=]                         Customers › Find
//   GET export.php?what=documents [&show=&q=&from=&to=]         Invoices & receipts (and papers), for the books / taxes
//   GET export.php?what=parties   [&status=]                    Private events
//   GET export.php?what=bookings  [&from=&to=]                  Bookings by date (default 90 days back to a year ahead)
//   GET export.php?what=waivers   [&q=]                         Signed waivers
//   GET export.php?what=incidents [&status=&type=]              Incident reports
//   GET export.php?what=tasks     [&status=&who=&from=&to=&q=&related=]   Everyone's tasks (managers)
// Each export needs the same access as its list, keeps the list's filters, and is logged in the audit trail (action export).
// Money is a plain number (12.50); dates are the farm's local time. Free text that starts with = + - @ is kept as text.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/record_tools.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

$role = (string)($_SESSION['user_role'] ?? '');
$what = (string)($_GET['what'] ?? '');
$need = [
    'customers' => [['view', 'customers']],
    'documents' => [['table', 'inventory', 'edit'], ['view', 'animal_expenses'], ['table', 'animals']],
    'parties'   => [['view', 'event_inquiries']],
    'bookings'  => [['view', 'bookings']],
    'waivers'   => [['view', 'customers']],
    'incidents' => [['view', 'incidents']],
    'tasks'     => [['view', 'tasks_manage']],
][$what] ?? null;
$deny = function (int $code, string $msg) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); exit($msg); };
if ($need === null) $deny(404, 'Unknown export.');
if ($role !== 'administrator' && !bcrf_search_allowed($need, bcrf_search_can_for($role))) $deny(403, 'You don\'t have access to that list.');

$g = fn(string $k, int $max = 100) => trim(mb_substr((string)($_GET[$k] ?? ''), 0, $max));
$date = function (string $k) use ($g): string {
    $v = $g($k, 10);
    return (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v) ? $v : '';
};
$local = function ($utc, string $f = 'Y-m-d H:i'): string {
    if (!$utc) return '';
    try { return (new DateTimeImmutable((string)$utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($f); }
    catch (Throwable $e) { return ''; }
};
$like = fn(string $q) => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
$stamp = date('Y-m-d');
$rows = [];

try {
    switch ($what) {
        case 'customers': {
            require_once __DIR__ . '/../includes/crm.php';
            $q = $g('q'); $w = 'c.merged_into_id IS NULL'; $a = [];
            if ($q !== '') {
                $w .= " AND (CONCAT_WS(' ', c.first_name, c.last_name) LIKE ? OR c.email LIKE ? OR c.phone_number LIKE ?)";
                $a = [$like($q), $like($q), $like($q)];
            }
            $st = $pdo->prepare("SELECT c.id, c.first_name, c.last_name, c.email, c.phone_number, c.created_at, c.source, c.account_status, c.staff_user_id,
                                        (c.password IS NOT NULL AND c.password <> '') AS has_account, h.name AS household
                                 FROM customers c LEFT JOIN households h ON h.id = c.household_id WHERE $w ORDER BY c.last_name, c.first_name, c.id LIMIT 20000");
            $st->execute($a);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = [(int)$r['id'], $r['first_name'], $r['last_name'], $r['email'], $r['phone_number'], $r['household'], $r['has_account'] ? 'yes' : 'no',
                           $r['staff_user_id'] ? 'yes' : 'no', $r['account_status'], $r['source'], substr((string)$r['created_at'], 0, 10)];
            bcrf_csv_send($pdo, "customers-$stamp.csv", ['id', 'first_name', 'last_name', 'email', 'phone', 'household', 'website_account', 'staff', 'status', 'source', 'since'], $rows, 'Customers');
        }
        case 'documents': {
            require_once __DIR__ . '/../includes/documents.php';
            if (!bcrf_docs_ready($pdo)) $deny(404, 'Invoices & receipts are not set up yet.');
            $show = in_array($g('show'), ['inbox', 'expense', 'papers', 'expiring', 'deleted'], true) ? $g('show') : 'all';
            if ($show === 'deleted' && !bcrf_doc_is_owner()) $show = 'all';
            foreach (bcrf_doc_list($pdo, ['show' => $show, 'q' => $g('q'), 'from' => $date('from'), 'to' => $date('to')], 5000) as $d)
                $rows[] = [(int)$d['id'], BCRF_DOC_TYPES[$d['doc_type']] ?? $d['doc_type'], bcrf_doc_link_label($d),
                           $d['expense_category'] ? (BCRF_DOC_EXPENSE_CATS[$d['expense_category']] ?? $d['expense_category']) : '',
                           bcrf_doc_vendor_label($d), $d['doc_date'], bcrf_csv_money($d['total_cents']), $d['ref'], $d['expires_on'] ?? '', $d['note'],
                           (int)$d['files_n'], $d['by_name'], $local($d['created_at']), $d['deleted_at'] ? 'deleted' : ''];
            bcrf_csv_send($pdo, "invoices-receipts-$stamp.csv", ['id', 'kind', 'for', 'expense_type', 'vendor', 'date', 'total', 'number', 'expires', 'note', 'pages', 'uploaded_by', 'uploaded_at', 'deleted'], $rows, 'Invoices & receipts');
        }
        case 'parties': {
            $status = $g('status');
            $ok = ['new', 'contacted', 'quoted', 'confirmed', 'declined', 'archived'];
            $w = in_array($status, $ok, true) ? 'WHERE e.status = ?' : '';
            $st = $pdo->prepare("SELECT e.*, p.name AS package_name FROM event_inquiries e LEFT JOIN party_packages p ON p.id = e.package_id $w ORDER BY e.preferred_date DESC, e.id DESC LIMIT 20000");
            try { $st->execute($w ? [$status] : []); }
            catch (Throwable $e) {   // party packages not set up yet
                $st = $pdo->prepare("SELECT e.*, NULL AS package_name FROM event_inquiries e $w ORDER BY e.preferred_date DESC, e.id DESC LIMIT 20000");
                $st->execute($w ? [$status] : []);
            }
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = [(int)$r['id'], $r['preferred_date'], $r['start_time'] ? substr((string)$r['start_time'], 0, 5) : '', $r['event_type'], $r['customer_name'], $r['email'], $r['phone'],
                           (int)$r['adult_count'], (int)$r['child_count'], $r['birthday_age'], $r['package_name'], $r['status'], $r['special_requests'], $r['admin_notes'], substr((string)$r['created_at'], 0, 10)];
            bcrf_csv_send($pdo, "parties-$stamp.csv", ['id', 'date', 'start', 'kind', 'name', 'email', 'phone', 'adults', 'children', 'birthday_age', 'package', 'status', 'their_notes', 'staff_note', 'asked_on'], $rows, 'Private events');
        }
        case 'bookings': {
            $from = $date('from') ?: date('Y-m-d', strtotime('-90 days'));
            $to = $date('to') ?: date('Y-m-d', strtotime('+365 days'));
            $st = $pdo->prepare("SELECT b.*, t.label FROM bookings b JOIN booking_types t ON t.id = b.type_id WHERE b.booking_date BETWEEN ? AND ? ORDER BY b.booking_date, b.start_time, b.id LIMIT 20000");
            $st->execute([$from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
                $rows[] = [(int)$r['id'], $r['booking_date'], $r['start_time'] ? substr((string)$r['start_time'], 0, 5) : '', $r['end_time'] ? substr((string)$r['end_time'], 0, 5) : '',
                           $r['label'], $r['customer_name'], $r['email'], $r['phone'], (int)$r['party_size'], $r['status'], $r['payment_status'],
                           $r['amount_total'] !== null ? number_format((float)$r['amount_total'], 2, '.', '') : '', $r['notes'], $r['admin_note'], substr((string)$r['created_at'], 0, 10)];
            bcrf_csv_send($pdo, "bookings-$from-to-$to.csv", ['id', 'date', 'start', 'end', 'kind', 'name', 'email', 'phone', 'guests', 'status', 'payment', 'amount', 'their_notes', 'staff_note', 'booked_on'], $rows, 'Bookings');
        }
        case 'waivers': {
            require_once __DIR__ . '/../includes/waivers.php';
            foreach (bcrf_waiver_search($pdo, $g('q'), 5000) as $r)
                $rows[] = [(int)$r['id'], $r['signer_name'], $r['signer_email'], $r['signer_phone'], implode(', ', array_map(fn($m) => (string)($m['name'] ?? ''), $r['minors'])),
                           BCRF_WAIVER_VIA[$r['via']] ?? $r['via'], $local($r['signed_at']), $local($r['expires_at'], 'Y-m-d'), $r['voided_at'] ? $local($r['voided_at'], 'Y-m-d') : '', 'v' . (int)$r['version']];
            bcrf_csv_send($pdo, "waivers-$stamp.csv", ['id', 'signer', 'email', 'phone', 'children', 'signed_via', 'signed_at', 'runs_out', 'voided', 'version'], $rows, 'Waivers');
        }
        case 'incidents': {
            require_once __DIR__ . '/../includes/incidents.php';
            $status = in_array($g('status'), ['open', 'closed'], true) ? $g('status') : '';
            foreach (bcrf_incident_list($pdo, ['status' => $status, 'type' => $g('type')], 1000) as $r)
                $rows[] = [(int)$r['id'], $local($r['happened_at']), BCRF_INCIDENT_TYPES[$r['type']] ?? $r['type'], $r['injury'] ? (BCRF_INCIDENT_INJURIES[$r['injury']] ?? $r['injury']) : '',
                           BCRF_INCIDENT_SEVERITY[$r['severity']] ?? $r['severity'], $r['location'], $r['animal_names'], (int)$r['people_count'], $r['description'], $r['action_taken'],
                           $r['follow_up'], $r['status'], $r['closed_at'] ? $local($r['closed_at'], 'Y-m-d') : '', $r['close_note'], $r['created_by_name']];
            bcrf_csv_send($pdo, "incidents-$stamp.csv", ['id', 'happened', 'kind', 'injury', 'severity', 'where', 'animals', 'visitors', 'what_happened', 'action_taken', 'follow_up', 'status', 'closed', 'how_settled', 'reported_by'], $rows, 'Incidents');
        }
        case 'tasks': {
            require_once __DIR__ . '/../includes/tasks.php';
            $f = ['status' => $g('status') ?: 'open', 'who' => $g('who', 40), 'from' => $date('from'), 'to' => $date('to'), 'q' => $g('q'), 'related' => $g('related', 40)];
            if (!preg_match('/^[a-z_]{2,20}:\d{1,10}$/', $f['related'])) $f['related'] = '';
            foreach (bcrf_tasks_list($pdo, $f, 2000) as $t) {
                $rel = $t['related_type'] ? (isset(bcrf_record_kinds()[$t['related_type']]) ? bcrf_record_label($pdo, (string)$t['related_type'], $t['related_id']) : $t['related_type'] . ' ' . $t['related_id']) : '';
                $rows[] = [(int)$t['id'], $t['title'], BCRF_TASK_STATUSES[$t['status']] ?? $t['status'], BCRF_TASK_PRIORITIES[$t['priority']] ?? $t['priority'], $t['due_date'],
                           $t['due_time'] ? substr((string)$t['due_time'], 0, 5) : '', bcrf_tasks_who($t), $rel, $t['checked'] . '/' . count($t['checklist']),
                           $t['completed_at'] ? $local($t['completed_at']) : '', trim((string)($t['completed_name'] ?? '')), $t['completion_note']];
            }
            bcrf_csv_send($pdo, "tasks-$stamp.csv", ['id', 'task', 'status', 'priority', 'due', 'by', 'who', 'linked_to', 'checklist_done', 'finished_at', 'finished_by', 'finish_note'], $rows, 'Tasks');
        }
    }
} catch (Throwable $e) {
    error_log('[export] ' . $what . ': ' . $e->getMessage());
    $deny(500, 'Could not make that file. Try again, or tell an owner.');
}
$deny(404, 'Unknown export.');
