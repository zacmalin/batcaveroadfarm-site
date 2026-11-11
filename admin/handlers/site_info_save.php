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
        // Farm OS Phase 0 (C-06): Site Info changes go to audit_events with before/after values.
        // (The old site_audit table was removed on Nov 11, 2026, migration 0024.)
        require_once __DIR__ . '/../includes/audit.php';
        [$type, $uid, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, [
            'actor_type' => $type, 'actor_id' => $uid, 'actor_label' => $name, 'ip' => $ip,
            'action' => 'update', 'object_type' => 'site_info', 'object_label' => $area . ': ' . $key,
            'before' => [$key => $old === null ? null : (string)$old], 'after' => [$key => $new === null ? null : (string)$new],
        ]);
    }
    function _si_set(PDO $pdo, string $key, string $val): void {
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$key, $val]);
    }
    // Cloudflare purge — only fires if a token + zone are configured; silent no-op otherwise.
    // Purges right away so the change shows at once; if Cloudflare doesn't answer, the job queue retries
    // (includes/queue.php). Also records a site_info.changed event (Farm OS Phase 0).
    function _si_cf_purge(): void {
        // Cloudflare adapter (Farm OS Phase 0): includes/adapter_cloudflare.php; no-op without credentials
        require_once __DIR__ . '/../../includes/adapter_cloudflare.php';
        require_once __DIR__ . '/../../includes/queue.php';
        bcrf_event('site_info.changed', 'site_settings', (string)($GLOBALS['action'] ?? ''));
        if (!bcrf_cdn_purge()) bcrf_queue_push('cdn.purge', [], ['dedupe' => 'cdn.purge:all']);
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
            $keys = ['business_name', 'address_street', 'address_city', 'address_state', 'address_zip', 'address_country', 'timezone', 'geo_lat', 'geo_lng', 'map_embed_url', 'google_rating', 'google_review_count',
                     'site_url', 'email_support', 'email_noreply', 'email_jobs', 'email_newsletter', 'meta_app_id'];   // last six: farm values (Farm OS Phase 0)
            $cur = [];
            foreach ($pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $cur[$k] = $v;
            foreach ($keys as $k) {
                if (!array_key_exists($k, $_POST['settings'] ?? [])) continue;
                $new = _si_clean($_POST['settings'][$k]);
                $old = (string)($cur[$k] ?? '');
                // The farm time zone drives every date in the system (Farm OS Phase 0, C-03): only real zone names.
                if ($k === 'timezone' && $new !== '' && !in_array($new, timezone_identifiers_list(), true)) {
                    $si_warn = '"' . $new . '" is not a time zone name. Use one like America/New_York. The time zone was not changed.';
                    continue;
                }
                // Website and sender addresses go into every email link and payment page: only valid ones.
                if ($k === 'site_url' && $new !== '') {
                    $new = rtrim($new, '/');
                    if (!preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}$#i', $new)) { $si_warn = '"' . $new . '" is not a website address like https://example.com. It was not changed.'; continue; }
                }
                if (str_starts_with($k, 'email_') && $new !== '' && !filter_var($new, FILTER_VALIDATE_EMAIL)) {
                    $si_warn = '"' . $new . '" is not an email address. It was not changed.'; continue;
                }
                if ($k === 'meta_app_id' && $new !== '' && !ctype_digit($new)) { $si_warn = 'The Facebook app ID is digits only. It was not changed.'; continue; }
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

    if (!empty($si_warn)) {
        if ($changed) _si_cf_purge();
        _si_done(($changed ? 'Other changes saved. ' : '') . $si_warn, 'error');
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
