<?php
// admin/includes/audit.php — shared audit helpers (one copy, used by every handler).
//
// Farm OS Phase 0 (C-06, Nov 5, 2026): everything is written to audit_events (migration 0003),
// one immutable table with UTC times and before/after values. Until 0003 has run, writes fall back
// to the old audit_log table so nothing is lost; 0003 copies those rows across.

if (!function_exists('bcrf_audit_event')) {
    /**
     * Write one audit event. Never throws: a logging problem must not block the actual save.
     * $e keys: action, object_type (required); object_id, object_label, actor_type, actor_id,
     * actor_label, ip, before (array), after (array).
     * Returns false only when audit_events isn't there yet (callers then use the old table).
     */
    function bcrf_audit_event(PDO $pdo, array $e): bool
    {
        static $ready = null;   // null = unknown, false = table missing
        if ($ready === false) return false;
        $json = fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        try {
            $pdo->prepare("INSERT INTO audit_events
                    (occurred_at, actor_type, actor_id, actor_label, ip, action, object_type, object_id, object_label, before_json, after_json)
                    VALUES (UTC_TIMESTAMP(3), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    mb_substr((string)($e['actor_type'] ?? 'staff'), 0, 20),
                    isset($e['actor_id']) && $e['actor_id'] !== null ? (int)$e['actor_id'] : null,
                    mb_substr((string)($e['actor_label'] ?? ''), 0, 120),
                    isset($e['ip']) && $e['ip'] !== null ? substr((string)$e['ip'], 0, 45) : null,
                    mb_substr((string)$e['action'], 0, 40),
                    mb_substr((string)$e['object_type'], 0, 100),
                    isset($e['object_id']) && $e['object_id'] !== null ? mb_substr((string)$e['object_id'], 0, 64) : null,
                    mb_substr((string)($e['object_label'] ?? ''), 0, 255),
                    $json($e['before'] ?? null),
                    $json($e['after'] ?? null),
                ]);
            $ready = true;
            return true;
        } catch (Throwable $ex) {
            if ($ready === null && str_contains($ex->getMessage(), '42S02')) { $ready = false; return false; }   // table not created yet
            error_log('[ERP] audit_events write failed: ' . $ex->getMessage());
            return true;   // table exists; don't double-write to the old one
        }
    }

    /** Who is acting in this request: [actor_type, actor_id, actor_label, ip]. */
    function bcrf_audit_actor(?string $actor = null): array
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);   // real visitor IP behind Cloudflare
        if ($actor !== null) {   // automatic jobs (e.g. 'Stripe') log under their own name
            return [str_contains($actor, '(AI:') ? 'agent' : 'system', null, $actor, null];
        }
        $name = trim((string)($_SESSION['user_name'] ?? ''));
        if ($name === '') $name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        if ($name === '') $name = 'Unknown';
        $uid = $_SESSION['user_id'] ?? null;
        return [str_contains($name, '(AI:') ? 'agent' : ($uid ? 'staff' : 'system'), $uid, $name, $ip];
    }
}

if (!function_exists('erp_audit')) {
    /** Record a create / update / delete. Never throws. */
    function erp_audit(PDO $pdo, string $action, string $table, ?int $record_id, string $label = '', ?string $actor = null): void
    {
        if (!in_array($action, ['create', 'update', 'delete'], true)) return;
        [$type, $uid, $name, $ip] = bcrf_audit_actor($actor);
        $ok = bcrf_audit_event($pdo, [
            'actor_type' => $type, 'actor_id' => $uid, 'actor_label' => $name, 'ip' => $ip,
            'action' => $action, 'object_type' => $table, 'object_id' => $record_id, 'object_label' => $label,
        ]);
        if ($ok) return;
        try {   // before migration 0003: old table
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
        foreach (['name', 'title', 'breed_name', 'singular_name', 'label', 'customer_name', 'holder_name', 'display_name', 'receipt_number', 'subject', 'email', 'slug', 'sku'] as $k) {
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
     * Record a staff-login event: 'login', 'failed' or 'blocked'.
     * Shows up in Admin > Audit Log under "staff_login".
     */
    function erp_audit_security(PDO $pdo, string $event, string $email, ?int $user_id = null, string $user_name = ''): void
    {
        $labels = ['login' => 'LOGIN OK', 'failed' => 'LOGIN FAILED', 'blocked' => 'LOCKED OUT'];
        if (!isset($labels[$event])) return;
        $ip    = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $name  = $user_name !== '' ? $user_name : 'Login guard';
        $label = $labels[$event] . ': ' . $email;

        $ok = bcrf_audit_event($pdo, [
            'actor_type' => $user_id ? 'staff' : 'anonymous', 'actor_id' => $user_id, 'actor_label' => $name, 'ip' => $ip,
            'action' => $event, 'object_type' => 'staff_login', 'object_label' => $label,
        ]);
        if ($ok) return;
        try {   // before migration 0003: old table (its action column is VARCHAR(20))
            $pdo->prepare("INSERT INTO audit_log (user_id, user_name, action, table_name, record_id, record_label, ip_address)
                           VALUES (?, ?, ?, 'staff_login', NULL, ?, ?)")
                ->execute([$user_id, mb_substr($name, 0, 100), $event, mb_substr($label, 0, 255), $ip ? substr((string)$ip, 0, 45) : null]);
        } catch (Throwable $e) {
            error_log('[ERP] Security audit failed: ' . $e->getMessage());
        }
    }
}
