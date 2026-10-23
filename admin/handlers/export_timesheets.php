<?php
// admin/handlers/export_timesheets.php
require_once '../../config.php';
check_admin();

if ($_SESSION['user_role'] !== 'administrator') die("Unauthorized.");

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="timesheets_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Staff Member', 'Clock In', 'Clock Out', 'IP Address', 'Notes']);

try {
    $stmt = $pdo->query("
        SELECT u.first_name, u.last_name, t.clock_in, t.clock_out, t.ip_address, t.notes 
        FROM time_cards t 
        JOIN users u ON t.user_id = u.id 
        ORDER BY t.clock_in DESC
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['first_name'] . ' ' . $row['last_name'],
            $row['clock_in'],
            $row['clock_out'] ?? 'Active',
            $row['ip_address'],
            $row['notes']
        ]);
    }
} catch (Throwable $e) {
    error_log('[ERP] CSV Export failed: ' . $e->getMessage());
}

fclose($output);
exit;
