<?php
// includes/staff_prefs.php — per-person settings saved on the staff account, not the device (Nov 15, 2026; migration 0068).
// First use: the phone bottom-bar shortcuts (admin/assets/admin-nav.js), so any phone you sign in on has your setup.
//   bcrf_staff_prefs_ready($pdo)                        table exists (migration 0068 run)
//   bcrf_staff_pref_get($pdo, $uid, $key)               decoded value, or null when nothing is saved
//   bcrf_staff_pref_set($pdo, $uid, $key, $value)       save (null removes it = back to the defaults)
//   bcrf_nav_shortcuts_clean($list)                     up to 4 distinct menu ids, safe characters only
if (!function_exists('bcrf_staff_prefs_ready')) {

function bcrf_staff_pref_keys(): array { return ['nav_shortcuts']; }

function bcrf_staff_prefs_ready(PDO $pdo): bool {
    static $ready = false;   // only "yes" is remembered, so a table made later in the same run is still found (Nov 15, 2026)
    if ($ready) return true;
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'staff_prefs'");
        $ready = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { $ready = false; }
    return $ready;
}

function bcrf_staff_pref_get(PDO $pdo, int $uid, string $key) {
    if ($uid <= 0 || !in_array($key, bcrf_staff_pref_keys(), true) || !bcrf_staff_prefs_ready($pdo)) return null;
    try {
        $st = $pdo->prepare('SELECT pref_value FROM staff_prefs WHERE user_id = ? AND pref_key = ?');
        $st->execute([$uid, $key]);
        $v = $st->fetchColumn();
        return $v === false ? null : json_decode((string)$v, true);
    } catch (Throwable $e) { return null; }
}

function bcrf_staff_pref_set(PDO $pdo, int $uid, string $key, $value): bool {
    if ($uid <= 0 || !in_array($key, bcrf_staff_pref_keys(), true) || !bcrf_staff_prefs_ready($pdo)) return false;
    if ($value === null) {
        $pdo->prepare('DELETE FROM staff_prefs WHERE user_id = ? AND pref_key = ?')->execute([$uid, $key]);
        return true;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 4000) return false;
    $pdo->prepare('INSERT INTO staff_prefs (user_id, pref_key, pref_value, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = VALUES(updated_at)')
        ->execute([$uid, $key, $json]);
    return true;
}

function bcrf_nav_shortcuts_clean($list): array {
    $out = [];
    foreach (is_array($list) ? $list : [] as $id) {
        $id = (string)$id;
        if ($id === '' || strlen($id) > 60 || !preg_match('/^[a-z0-9.-]+$/', $id) || in_array($id, $out, true)) continue;
        $out[] = $id;
        if (count($out) >= 4) break;
    }
    return $out;
}

}
