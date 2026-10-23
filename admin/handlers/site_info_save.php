<?php
/**
 * admin/handlers/site_info_save.php
 * Processes the Site Info accordion forms. Included by admin.php's POST
 * dispatcher when $_POST['site_info_action'] is set, so it runs in admin.php's
 * scope ($pdo, $_SESSION available).
 *
 * Follows the house Post/Redirect/Get pattern: set $_SESSION['toast_msg'] /
 * ['toast_type'], then redirect back to the view. admin.php reconstitutes those
 * into $toastMessage/$toastType for layout/toast.php. (Setting $toastMessage
 * directly does NOT work — admin.php overwrites it from the session flash.)
 *
 * Actions: address | phones | emails | hours | season
 */

if (!function_exists('_si_done')) {
    function _si_done(string $msg, string $type = 'success'): void {
        $_SESSION['toast_msg']  = $msg;
        $_SESSION['toast_type'] = $type;
        header('Location: /admin/admin.php?view=site_info');
        exit;
    }
    // Trim + guarantee valid UTF-8 so downstream json_encode() never returns false.
    function _si_clean($v): string {
        $v = is_string($v) ? trim($v) : '';
        if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        }
        return $v;
    }
    function _si_audit(PDO $pdo, string $area, string $key, $old, $new): void {
        if ((string)$old === (string)$new) return;
        try {
            $pdo->prepare("INSERT INTO site_audit (admin_user_id, admin_label, area, change_key, old_value, new_value) VALUES (?,?,?,?,?,?)")
                ->execute([
                    $_SESSION['user_id'] ?? null,
                    (string)($_SESSION['user_name'] ?? $_SESSION['user_role'] ?? 'system'),
                    $area, $key, ($old === null ? null : (string)$old), ($new === null ? null : (string)$new),
                ]);
        } catch (Throwable $e) { error_log('[BCRF site_audit] ' . $e->getMessage()); }
    }
    function _si_set(PDO $pdo, string $key, string $val): void {
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$key, $val]);
    }
    // Cloudflare purge — only fires if a token + zone are configured; silent no-op otherwise.
    function _si_cf_purge(): void {
        $token = defined('BCRF_CF_TOKEN') ? BCRF_CF_TOKEN : (getenv('BCRF_CF_TOKEN') ?: '');
        $zone  = defined('BCRF_CF_ZONE')  ? BCRF_CF_ZONE  : (getenv('BCRF_CF_ZONE')  ?: '');
        if (!$token || !$zone || !function_exists('curl_init')) return;
        try {
            $ch = curl_init("https://api.cloudflare.com/client/v4/zones/" . rawurlencode($zone) . "/purge_cache");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => json_encode(['purge_everything' => true]),
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable $e) { error_log('[BCRF CF purge] ' . $e->getMessage()); }
    }
    // One (scope,day) row from posted day data; incomplete times collapse to closed.
    function _si_write_day(PDO $pdo, string $scope, int $day, array $row): void {
        $closed = !empty($row['closed']) ? 1 : 0;
        $open   = $closed ? null : (preg_match('/^\d{1,2}:\d{2}/', (string)($row['open']  ?? '')) ? substr((string)$row['open'], 0, 5)  . ':00' : null);
        $close  = $closed ? null : (preg_match('/^\d{1,2}:\d{2}/', (string)($row['close'] ?? '')) ? substr((string)$row['close'], 0, 5) . ':00' : null);
        if (!$closed && (!$open || !$close)) { $closed = 1; $open = $close = null; }
        $pdo->prepare("INSERT INTO site_hours (scope, day_of_week, block_order, open_time, close_time, is_closed) VALUES (?,?,0,?,?,?)")
            ->execute([$scope, $day, $open, $close, $closed]);
    }
}

// ── guards: auth + role + CSRF ────────────────────────────────────────────────
$__si_roles = ['administrator', 'moderator', 'Shopkeep'];
if (empty($_SESSION['user_id']) || empty($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $__si_roles, true)) {
    _si_done('Not authorized to edit Site Info.', 'error');
}
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    _si_done('Security token mismatch — please reload and try again.', 'error');
}

$action  = (string)($_POST['site_info_action'] ?? '');
$changed = false;

try {
    switch ($action) {

        case 'announcement': {
            $keys = ['announcement_text', 'announcement_until'];
            $cur = [];
            foreach ($pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $cur[$k] = $v;
            foreach ($keys as $k) {
                if (!array_key_exists($k, $_POST['settings'] ?? [])) continue;
                $new = _si_clean($_POST['settings'][$k]);
                if ($k === 'announcement_until' && $new !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new)) $new = '';
                $old = (string)($cur[$k] ?? '');
                if ($old !== $new) { _si_set($pdo, $k, $new); _si_audit($pdo, 'announcement', $k, $old, $new); $changed = true; }
            }
            break;
        }

        case 'address': {
            $keys = ['business_name', 'address_street', 'address_city', 'address_state', 'address_zip', 'address_country', 'timezone', 'geo_lat', 'geo_lng', 'map_embed_url', 'google_rating', 'google_review_count'];
            $cur = [];
            foreach ($pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $cur[$k] = $v;
            foreach ($keys as $k) {
                if (!array_key_exists($k, $_POST['settings'] ?? [])) continue;
                $new = _si_clean($_POST['settings'][$k]);
                $old = (string)($cur[$k] ?? '');
                if ($old !== $new) { _si_set($pdo, $k, $new); _si_audit($pdo, 'address', $k, $old, $new); $changed = true; }
            }
            break;
        }

        case 'phones':
        case 'emails': {
            foreach (($_POST['contact'] ?? []) as $id => $c) {
                $id = (int)$id;
                $st = $pdo->prepare("SELECT contact_type, slot, value, inherit_general FROM site_contacts WHERE id = ?");
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;
                $val     = _si_clean($c['value'] ?? '');
                $inherit = !empty($c['inherit']) ? 1 : 0;
                if ($row['slot'] === 'general') $inherit = 0; // general can't inherit itself
                $ck = $row['contact_type'] . '.' . $row['slot'];
                if ((string)$row['value'] !== $val || (int)$row['inherit_general'] !== $inherit) {
                    $pdo->prepare("UPDATE site_contacts SET value = ?, inherit_general = ? WHERE id = ?")->execute([$val, $inherit, $id]);
                    _si_audit($pdo, 'contacts', $ck . '.value',   $row['value'], $val);
                    _si_audit($pdo, 'contacts', $ck . '.inherit', $row['inherit_general'], $inherit);
                    $changed = true;
                }
            }
            break;
        }

        case 'hours': {
            // General scope: always rewrite all 7 days.
            $pdo->prepare("DELETE FROM site_hours WHERE scope = 'general'")->execute();
            for ($d = 0; $d <= 6; $d++) {
                _si_write_day($pdo, 'general', $d, (array)($_POST['hours']['general'][$d] ?? []));
            }
            _si_audit($pdo, 'hours', 'hours.general', '', 'updated');
            // Area scopes: set inherit flag; rewrite only when NOT inheriting.
            foreach ($pdo->query("SELECT scope, inherit_general FROM site_hours_scopes WHERE scope <> 'general'")->fetchAll(PDO::FETCH_ASSOC) as $sc) {
                $scope   = $sc['scope'];
                $inherit = !empty($_POST['scope_inherit'][$scope]) ? 1 : 0;
                if ((int)$sc['inherit_general'] !== $inherit) {
                    _si_audit($pdo, 'hours', 'hours.' . $scope . '.inherit', $sc['inherit_general'], $inherit);
                }
                $pdo->prepare("UPDATE site_hours_scopes SET inherit_general = ? WHERE scope = ?")->execute([$inherit, $scope]);
                $pdo->prepare("DELETE FROM site_hours WHERE scope = ?")->execute([$scope]);
                if (!$inherit) {
                    for ($d = 0; $d <= 6; $d++) {
                        _si_write_day($pdo, $scope, $d, (array)($_POST['hours'][$scope][$d] ?? []));
                    }
                }
            }
            $changed = true;
            break;
        }

        case 'season': {
            $new = (($_POST['season_status'] ?? 'open') === 'closed') ? 'closed' : 'open';
            $old = (string)($pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'season_status'")->fetchColumn() ?: 'open');
            if ($old !== $new) { _si_set($pdo, 'season_status', $new); _si_audit($pdo, 'season', 'season_status', $old, $new); $changed = true; }
            break;
        }

        case 'socials': {
            $rows  = is_array($_POST['social'] ?? null) ? $_POST['social'] : [];
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM site_socials")->execute();
            $ins = $pdo->prepare("INSERT INTO site_socials (name, image, url, sort_order, is_active) VALUES (?,?,?,?,1)");
            $order = 0;
            foreach ($rows as $r) {
                $name  = _si_clean($r['name']  ?? '');
                $image = _si_clean($r['image'] ?? '');
                $url   = _si_clean($r['url']   ?? '');
                if ($name === '' && $image === '' && $url === '') continue; // skip blank rows
                $ins->execute([$name, $image, $url, $order++]);
            }
            $pdo->commit();
            _si_audit($pdo, 'socials', 'socials', '', 'updated (' . $order . ' link' . ($order === 1 ? '' : 's') . ')');
            $changed = true;
            break;
        }

        default:
            _si_done('Unknown Site Info action.', 'error');
    }

    if ($changed) {
        _si_cf_purge(); // best-effort; no-op without CF creds
        // NOTE: static-file regen (llms.txt / agents.json) wired in step 3.
        _si_done('Site Info saved.', 'success');
    }
    _si_done('No changes to save.', 'success');

} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
    error_log('[BCRF site_info_save] ' . $e->getMessage());
    _si_done('Save failed — check the error log.', 'error');
}
