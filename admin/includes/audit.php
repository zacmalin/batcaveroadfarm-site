<?php
// admin/includes/audit.php — shared audit-log helpers (one copy, used by every handler).

if (!function_exists('erp_audit')) {
    /**
     * Record a create / update / delete in audit_log. Never throws:
     * a logging problem must not block the actual save.
     */
    function erp_audit(PDO $pdo, string $action, string $table, ?int $record_id, string $label = '', ?string $actor = null): void
    {
        if (!in_array($action, ['create', 'update', 'delete'], true)) return;
        try {
            $uid  = $actor === null ? ($_SESSION['user_id'] ?? null) : null;
            // $actor lets automatic jobs (e.g. 'Stripe') log under their own name.
            // Login stores the display name in user_name (first name). Fall back to first/last, then 'Unknown'.
            $name = $actor !== null ? $actor : trim((string)($_SESSION['user_name'] ?? ''));
            if ($name === '') $name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
            if ($name === '') $name = 'Unknown';
            $ip = $actor !== null ? null : ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? null));   // real visitor IP behind Cloudflare
            $pdo->prepare("INSERT INTO audit_log (user_id, user_name, action, table_name, record_id, record_label, ip_address)
                           VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$uid, mb_substr($name, 0, 100), $action, $table, $record_id, mb_substr($label, 0, 255), $ip ? substr($ip, 0, 45) : null]);
        } catch (Throwable $e) {
            error_log('[ERP] Audit log failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('erp_audit_label')) {
    /** Pick a human-readable label from a record (name, title, etc.). */
    function erp_audit_label(array $row): string
    {
        foreach (['name', 'title', 'breed_name', 'label', 'customer_name', 'holder_name', 'display_name', 'receipt_number', 'subject', 'email', 'slug', 'sku'] as $k) {
            if (!empty($row[$k]) && is_scalar($row[$k])) return trim(strip_tags((string)$row[$k]));
        }
        $first = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        return $first;
    }
}

if (!function_exists('erp_row_label')) {
    /** Look up a record and return its readable label. Only for known tables. */
    function erp_row_label(PDO $pdo, string $table, int $id): string
    {
        $allowed = ['bookings', 'booking_types', 'booking_blackouts', 'passes', 'pass_types', 'events', 'event_inquiries', 'inventory', 'campaigns', 'time_cards'];
        if (!in_array($table, $allowed, true) || $id <= 0) return '';
        try {
            $st = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $label = erp_audit_label($row);
            if ($table === 'bookings' && !empty($row['booking_date'])) $label = trim($label . ' (' . $row['booking_date'] . ')');
            if ($table === 'booking_blackouts' && !empty($row['d_date'])) $label = trim($row['d_date'] . ' ' . ($row['reason'] ?? ''));
            return $label;
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('erp_audit_security')) {
    /**
     * Record a staff-login event (fix pack K): 'login', 'failed' or 'blocked'.
     * Shows up in Admin > Audit Log under table "staff_login".
     *
     * If the audit_log.action column only allows create/update/delete (an ENUM),
     * the event is stored as 'update' instead. The label always says what
     * happened ("LOGIN FAILED: ..."), so nothing is lost either way.
     */
    function erp_audit_security(PDO $pdo, string $event, string $email, ?int $user_id = null, string $user_name = ''): void
    {
        static $actionOk = null;
        $labels = ['login' => 'LOGIN OK', 'failed' => 'LOGIN FAILED', 'blocked' => 'LOCKED OUT'];
        if (!isset($labels[$event])) return;

        try {
            if ($actionOk === null) {
                $actionOk = true;
                $col = $pdo->query("SHOW COLUMNS FROM audit_log LIKE 'action'")->fetch(PDO::FETCH_ASSOC);
                $type = strtolower((string)($col['Type'] ?? ''));
                if (str_starts_with($type, 'enum(') || str_starts_with($type, 'set(')) {
                    $actionOk = str_contains($type, "'login'") && str_contains($type, "'failed'") && str_contains($type, "'blocked'");
                } elseif (preg_match('/char\((\d+)\)/', $type, $m)) {
                    $actionOk = (int)$m[1] >= 7;
                }
            }
            $action = $actionOk ? $event : 'update';
            $ip     = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
            $name   = $user_name !== '' ? $user_name : 'Login guard';
            $label  = $labels[$event] . ': ' . $email;

            $pdo->prepare("INSERT INTO audit_log (user_id, user_name, action, table_name, record_id, record_label, ip_address)
                           VALUES (?, ?, ?, 'staff_login', NULL, ?, ?)")
                ->execute([$user_id, mb_substr($name, 0, 100), $action, mb_substr($label, 0, 255), $ip ? substr((string)$ip, 0, 45) : null]);
        } catch (Throwable $e) {
            error_log('[ERP] Security audit failed: ' . $e->getMessage());
        }
    }
}
