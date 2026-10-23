<?php
// admin/handlers/event_save.php — create / update / delete / toggle farm events.

try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=events'); exit;
    }

    require_once __DIR__ . '/../../includes/events.php';
    require_once __DIR__ . '/../includes/audit.php';
    bcrf_events_ensure_schema($pdo);

    $action = $_POST['event_action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $norm_dt = function (?string $v, bool $allDay): ?string {
        $v = trim((string)$v);
        if ($v === '') return null;
        $v = str_replace('T', ' ', $v);
        if ($allDay && strlen($v) <= 10) return $v . ' 00:00:00';
        if (strlen($v) === 16) return $v . ':00';   // YYYY-MM-DD HH:MM
        return $v;
    };

    if ($action === 'delete') {
        if ($id > 0) {
            $evLabel = erp_row_label($pdo, 'events', $id);
            $evDel = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $evDel->execute([$id]);
            if ($evDel->rowCount() > 0) erp_audit($pdo, 'delete', 'events', $id, $evLabel);
            $_SESSION['toast_msg']  = 'Event deleted.';
            $_SESSION['toast_type'] = 'success';
        }
        header('Location: /admin/admin.php?view=events'); exit;
    }

    if ($action === 'toggle') {
        if ($id > 0) {
            $pdo->prepare("UPDATE events SET status = IF(status='published','draft','published') WHERE id = ?")->execute([$id]);
            $evNow = '';
            try { $es = $pdo->prepare("SELECT status FROM events WHERE id=?"); $es->execute([$id]); $evNow = (string)$es->fetchColumn(); } catch (Throwable $e) {}
            erp_audit($pdo, 'update', 'events', $id, erp_row_label($pdo, 'events', $id) . ' - now ' . $evNow);
            $_SESSION['toast_msg']  = 'Event status updated.';
            $_SESSION['toast_type'] = 'success';
        }
        header('Location: /admin/admin.php?view=events'); exit;
    }

    if ($action === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        $allDay = !empty($_POST['all_day']) ? 1 : 0;
        $start  = $norm_dt($_POST['start_datetime'] ?? '', (bool)$allDay);
        if ($title === '' || $start === null) {
            $_SESSION['toast_msg']  = 'Title and start date are required.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=events' . ($id ? '&edit=' . $id : '')); exit;
        }
        $end    = $norm_dt($_POST['end_datetime'] ?? '', (bool)$allDay);
        $rec    = in_array(($_POST['recurrence'] ?? 'none'), ['none','range','weekly','annual'], true) ? $_POST['recurrence'] : 'none';
        $days   = '';
        if ($rec === 'weekly' && !empty($_POST['recur_days']) && is_array($_POST['recur_days'])) {
            $days = implode(',', array_map('intval', array_filter($_POST['recur_days'], fn($d) => $d !== '' && (int)$d >= 0 && (int)$d <= 6)));
        }
        $until  = trim((string)($_POST['recur_until'] ?? '')) ?: null;
        $note   = trim((string)($_POST['recurrence_note'] ?? '')) ?: null;
        $isFree = !empty($_POST['is_free']) ? 1 : 0;
        $price  = ($isFree || trim((string)($_POST['price'] ?? '')) === '') ? null : (float)$_POST['price'];
        $desc   = trim((string)($_POST['description'] ?? '')) ?: null;
        $img    = trim((string)($_POST['image_url'] ?? '')) ?: null;
        $locn   = trim((string)($_POST['location_note'] ?? '')) ?: null;
        $status = (($_POST['status'] ?? 'published') === 'draft') ? 'draft' : 'published';
        $slug   = createSlug($title) ?: ('event-' . time());

        if ($id > 0) {
            $sql = "UPDATE events SET title=?, slug=?, description=?, image_url=?, start_datetime=?, end_datetime=?,
                        all_day=?, recurrence=?, recur_days=?, recur_until=?, recurrence_note=?, is_free=?, price=?,
                        location_note=?, status=? WHERE id=?";
            $pdo->prepare($sql)->execute([$title,$slug,$desc,$img,$start,$end,$allDay,$rec,$days ?: null,$until,$note,$isFree,$price,$locn,$status,$id]);
            erp_audit($pdo, 'update', 'events', $id, $title . ' (' . $status . ')');
            $_SESSION['toast_msg'] = 'Event updated.';
        } else {
            $sql = "INSERT INTO events (title,slug,description,image_url,start_datetime,end_datetime,all_day,recurrence,recur_days,recur_until,recurrence_note,is_free,price,location_note,status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([$title,$slug,$desc,$img,$start,$end,$allDay,$rec,$days ?: null,$until,$note,$isFree,$price,$locn,$status]);
            erp_audit($pdo, 'create', 'events', (int)$pdo->lastInsertId(), $title . ' (' . $status . ')');
            $_SESSION['toast_msg'] = 'Event created.';
        }
        $_SESSION['toast_type'] = 'success';
        header('Location: /admin/admin.php?view=events'); exit;
    }

    header('Location: /admin/admin.php?view=events'); exit;

} catch (Throwable $e) {
    error_log('[ERP] event_save failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = 'Event save error: ' . $e->getMessage();
    $_SESSION['toast_type'] = 'error';
    header('Location: /admin/admin.php?view=events'); exit;
}
