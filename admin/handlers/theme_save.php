<?php
// admin/handlers/theme_save.php — invoked from admin.php when $_POST['theme_action'] is set.
// Runs in admin.php scope ($pdo, $_SESSION available).
require_once __DIR__ . '/../../includes/themes.php';
require_once __DIR__ . '/../includes/audit.php';   // fix pack O: audit log

try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=theme_scheduler'); exit;
    }

    $action  = $_POST['theme_action'] ?? '';
    $catalog = bcrf_theme_catalog();

    if ($action === 'add') {
        $slug     = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['theme_slug'] ?? '')));
        $mode     = (($_POST['mode'] ?? 'annual') === 'range') ? 'range' : 'annual';
        $priority = (int)($_POST['priority'] ?? 0);
        if (!isset($catalog[$slug])) throw new Exception('Please pick a valid theme.');

        if ($mode === 'annual') {
            $sm = trim((string)($_POST['start_md'] ?? ''));
            $em = trim((string)($_POST['end_md'] ?? ''));
            if (!preg_match('/^\d{2}-\d{2}$/', $sm) || !preg_match('/^\d{2}-\d{2}$/', $em)) {
                throw new Exception('Annual dates must be in MM-DD format.');
            }
            $pdo->prepare("INSERT INTO theme_schedules (theme_slug, mode, start_md, end_md, priority, is_active)
                           VALUES (?, 'annual', ?, ?, ?, 1)")
                ->execute([$slug, $sm, $em, $priority]);
            erp_audit($pdo, 'create', 'theme_schedules', (int)$pdo->lastInsertId(), "Theme {$slug}: every year {$sm} to {$em}");
        } else {
            $sd = (string)($_POST['start_date'] ?? '');
            $ed = (string)($_POST['end_date'] ?? '');
            $d1 = DateTime::createFromFormat('Y-m-d', $sd);
            $d2 = DateTime::createFromFormat('Y-m-d', $ed);
            if (!$d1 || !$d2) throw new Exception('A one-off range needs valid start and end dates.');
            if ($ed < $sd)   throw new Exception('End date must be on or after the start date.');
            $pdo->prepare("INSERT INTO theme_schedules (theme_slug, mode, start_date, end_date, priority, is_active)
                           VALUES (?, 'range', ?, ?, ?, 1)")
                ->execute([$slug, $sd, $ed, $priority]);
            erp_audit($pdo, 'create', 'theme_schedules', (int)$pdo->lastInsertId(), "Theme {$slug}: {$sd} to {$ed}");
        }
        $_SESSION['toast_msg'] = 'Schedule added.'; $_SESSION['toast_type'] = 'success';

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE theme_schedules SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        $t = $pdo->prepare("SELECT theme_slug, is_active FROM theme_schedules WHERE id = ?"); $t->execute([$id]);
        if ($r = $t->fetch(PDO::FETCH_ASSOC)) {
            erp_audit($pdo, 'update', 'theme_schedules', $id, "Theme {$r['theme_slug']} schedule " . ($r['is_active'] ? 'turned on' : 'turned off'));
        }
        $_SESSION['toast_msg'] = 'Schedule updated.'; $_SESSION['toast_type'] = 'success';

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $t = $pdo->prepare("SELECT theme_slug FROM theme_schedules WHERE id = ?"); $t->execute([$id]);
        $gone = (string)($t->fetchColumn() ?: '');
        $pdo->prepare("DELETE FROM theme_schedules WHERE id = ?")->execute([$id]);
        if ($gone !== '') erp_audit($pdo, 'delete', 'theme_schedules', $id, "Theme {$gone} schedule");
        $_SESSION['toast_msg'] = 'Schedule deleted.'; $_SESSION['toast_type'] = 'success';

    } elseif ($action === 'settings') {
        $override = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['override'] ?? '')));
        $default  = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['default'] ?? '')));
        if ($override !== '' && !isset($catalog[$override])) $override = '';
        if ($default  !== '' && !isset($catalog[$default]))  $default  = '';
        $up = $pdo->prepare("INSERT INTO theme_settings (skey, sval) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE sval = VALUES(sval)");
        $up->execute(['override', $override]);
        $up->execute(['default',  $default]);
        erp_audit($pdo, 'update', 'theme_settings', null,
            'Theme override: ' . ($override !== '' ? $override : 'none') . ', default: ' . ($default !== '' ? $default : 'none'));
        $_SESSION['toast_msg'] = 'Theme settings saved.'; $_SESSION['toast_type'] = 'success';

    } else {
        $_SESSION['toast_msg'] = 'Unknown theme action.'; $_SESSION['toast_type'] = 'error';
    }

} catch (Throwable $e) {
    error_log('[ERP] theme_save failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = 'Theme error: ' . $e->getMessage();
    $_SESSION['toast_type'] = 'error';
}

header('Location: /admin/admin.php?view=theme_scheduler');
exit;
