<?php
// admin/handlers/bookings_action.php
// confirm / confirm_force(override) / cancel / complete / no_show / note / blackout_add / blackout_remove.
// Runs in admin.php global scope ($pdo, $_SESSION available). PRG: set toast + redirect.

try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=bookings'); exit;
    }

    require_once __DIR__ . '/../../includes/booking.php';
    require_once __DIR__ . '/../includes/audit.php';

    $action = $_POST['bookings_action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $day    = trim((string)($_POST['day'] ?? ''));
    $back   = '/admin/admin.php?view=bookings' . ($day !== '' && strtotime($day) ? '&day=' . urlencode(date('Y-m-d', strtotime($day))) : '');

    // ---- Booking type management ----
    if ($action === 'booking_type_save' || $action === 'booking_type_new') {
        $bt = $_POST['bt'] ?? [];
        $lbl  = trim(strip_tags((string)($bt['label']    ?? '')));
        $key  = trim(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($bt['type_key'] ?? ''))));
        if ($lbl === '' || $key === '') {
            $_SESSION['toast_msg']  = 'Label and type key are required.';
            $_SESSION['toast_type'] = 'error';
            header('Location: ' . $back); exit;
        }
        $fields = [
            'label'           => $lbl,
            'type_key'        => $key,
            'sort_order'      => (int)($bt['sort_order']      ?? 0),
            'pricing_model'   => in_array($bt['pricing_model'] ?? '', ['flat','per_person'], true) ? $bt['pricing_model'] : 'flat',
            'unit_price'      => round((float)($bt['unit_price']      ?? 0), 2),
            'requires_payment'=> !empty($bt['requires_payment'])  ? 1 : 0,
            'min_party'       => max(1, (int)($bt['min_party']       ?? 1)),
            'max_party'       => max(1, (int)($bt['max_party']       ?? 25)),
            'lead_time_hours' => max(0, (int)($bt['lead_time_hours'] ?? 0)),
            'duration_min'    => max(15, (int)($bt['duration_min']   ?? 60)),
            'is_exclusive'    => !empty($bt['is_exclusive']) ? 1 : 0,
            'self_serve'      => !empty($bt['self_serve'])   ? 1 : 0,
            'is_active'       => !empty($bt['is_active'])    ? 1 : 0,
        ];

        if ($action === 'booking_type_save') {
            $tid = (int)($_POST['bt_id'] ?? 0);
            if ($tid <= 0) { $_SESSION['toast_msg'] = 'Invalid type.'; $_SESSION['toast_type'] = 'error'; header('Location: ' . $back); exit; }
            $sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
            $pdo->prepare("UPDATE booking_types SET {$sets} WHERE id = ?")->execute([...array_values($fields), $tid]);
            erp_audit($pdo, 'update', 'booking_types', $tid, 'Booking type: ' . $lbl);
            $_SESSION['toast_msg']  = 'Booking type updated.';
        } else {
            $cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
            $vals = implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO booking_types ({$cols}) VALUES ({$vals})")->execute(array_values($fields));
            erp_audit($pdo, 'create', 'booking_types', (int)$pdo->lastInsertId(), 'Booking type: ' . $lbl);
            $_SESSION['toast_msg']  = 'Booking type created.';
        }
        $_SESSION['toast_type'] = 'success';
        header('Location: ' . $back); exit;
    }

    // ── PAYMENT: WAIVE (cash/in-person paid) ─────────────────────────────────
    if ($action === 'waive') {
        $pdo->prepare("UPDATE bookings SET payment_status='waived', paid_at=COALESCE(paid_at,NOW()) WHERE id=?")
            ->execute([$id]);
        erp_audit($pdo, 'update', 'bookings', $id, erp_row_label($pdo, 'bookings', $id) . ' - marked paid (cash/in person)');
        $_SESSION['toast_msg']  = 'Booking marked as paid (cash/in-person).';
        $_SESSION['toast_type'] = 'success';
        header('Location: ' . $back); exit;
    }

    // ── PAYMENT: STRIPE REFUND ────────────────────────────────────────────────
    if ($action === 'refund') {
        $st = $pdo->prepare("SELECT payment_reference, payment_status, payment_provider, amount_total FROM bookings WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['payment_status'] !== 'paid' || $row['payment_provider'] !== 'stripe') {
            $_SESSION['toast_msg']  = 'Cannot refund — not a paid Stripe booking.';
            $_SESSION['toast_type'] = 'error';
            header('Location: ' . $back); exit;
        }
        if (empty($row['payment_reference'])) {
            $_SESSION['toast_msg']  = 'No Stripe session ID found — refund manually in the Stripe dashboard.';
            $_SESSION['toast_type'] = 'error';
            header('Location: ' . $back); exit;
        }
        try {
            require_once dirname(__DIR__, 2) . '/includes/stripe.php';
            stripe_refund_session((string)$row['payment_reference']);
            $pdo->prepare("UPDATE bookings SET payment_status='refunded' WHERE id=?")->execute([$id]);
            erp_audit($pdo, 'update', 'bookings', $id, erp_row_label($pdo, 'bookings', $id) . ' - refunded'
                . (!empty($row['amount_total']) ? ' $' . number_format((float)$row['amount_total'], 2) : ''));
            $_SESSION['toast_msg']  = 'Refund issued'
                . (!empty($row['amount_total']) ? ' ($' . number_format((float)$row['amount_total'], 2) . ')' : '') . '.';
            $_SESSION['toast_type'] = 'success';
        } catch (Throwable $e) {
            error_log('[BCRF refund] ' . $e->getMessage());
            $_SESSION['toast_msg']  = 'Stripe refund failed: ' . $e->getMessage();
            $_SESSION['toast_type'] = 'error';
        }
        header('Location: ' . $back); exit;
    }

    // ---- Blackout dates (no booking id) ----
    if ($action === 'blackout_add') {
        $bd = trim((string)($_POST['d_date'] ?? ''));
        $br = trim((string)($_POST['reason'] ?? ''));
        if ($bd !== '' && strtotime($bd)) {
            $pdo->prepare("INSERT INTO booking_blackouts (d_date, reason) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE reason = VALUES(reason)")
                ->execute([date('Y-m-d', strtotime($bd)), $br]);
            erp_audit($pdo, 'create', 'booking_blackouts', (int)$pdo->lastInsertId() ?: null, 'Blackout ' . date('Y-m-d', strtotime($bd)) . ($br !== '' ? ': ' . $br : ''));
            $_SESSION['toast_msg'] = 'Blackout date saved.'; $_SESSION['toast_type'] = 'success';
        } else {
            $_SESSION['toast_msg'] = 'Enter a valid blackout date.'; $_SESSION['toast_type'] = 'error';
        }
        header('Location: ' . $back); exit;
    }
    if ($action === 'blackout_remove') {
        $blLabel = erp_row_label($pdo, 'booking_blackouts', $id);
        $blDel = $pdo->prepare("DELETE FROM booking_blackouts WHERE id = ?");
        $blDel->execute([$id]);
        if ($blDel->rowCount() > 0) erp_audit($pdo, 'delete', 'booking_blackouts', $id, 'Blackout ' . $blLabel);
        $_SESSION['toast_msg'] = 'Blackout removed.'; $_SESSION['toast_type'] = 'success';
        header('Location: ' . $back); exit;
    }

    // ---- Per-booking actions ----
    if ($id <= 0) { header('Location: ' . $back); exit; }
    $st = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
    $st->execute([$id]);
    $bk = $st->fetch(PDO::FETCH_ASSOC);
    if (!$bk) {
        $_SESSION['toast_msg'] = 'Booking not found.'; $_SESSION['toast_type'] = 'error';
        header('Location: ' . $back); exit;
    }
    $ts = $pdo->prepare("SELECT * FROM booking_types WHERE id = ?");
    $ts->execute([(int)$bk['type_id']]);
    $type = $ts->fetch(PDO::FETCH_ASSOC) ?: null;

    // simple status transitions
    $simple = ['cancel' => 'cancelled', 'complete' => 'completed', 'no_show' => 'no_show'];
    if (isset($simple[$action])) {
        $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?")->execute([$simple[$action], $id]);
        erp_audit($pdo, 'update', 'bookings', $id, trim(($bk['customer_name'] ?? '') . ' (' . ($bk['booking_date'] ?? '') . ')') . ' - ' . ($bk['status'] ?? '?') . ' -> ' . $simple[$action]);
        $_SESSION['toast_msg']  = 'Booking marked ' . str_replace('_', '-', $simple[$action]) . '.';
        $_SESSION['toast_type'] = 'success';
        header('Location: ' . $back); exit;
    }

    if ($action === 'note') {
        $note = trim((string)($_POST['admin_note'] ?? '')) ?: null;
        $pdo->prepare("UPDATE bookings SET admin_note = ? WHERE id = ?")->execute([$note, $id]);
        erp_audit($pdo, 'update', 'bookings', $id, trim(($bk['customer_name'] ?? '') . ' (' . ($bk['booking_date'] ?? '') . ')') . ' - note ' . ($note ? 'saved' : 'cleared'));
        $_SESSION['toast_msg'] = 'Note saved.'; $_SESSION['toast_type'] = 'success';
        header('Location: ' . $back); exit;
    }

    if ($action === 'confirm' || $action === 'confirm_force') {
        $force = ($action === 'confirm_force');
        $pdo->beginTransaction();
        try {
            if (!$force && function_exists('booking_capacity_ok')) {
                $res = booking_capacity_ok($pdo, [
                    'date'         => $bk['booking_date'],
                    'start'        => $bk['start_time'],
                    'end'          => $bk['end_time'],
                    'party_size'   => (int)$bk['party_size'],
                    'is_exclusive' => (int)$bk['is_exclusive'],
                    'exclude_id'   => $id,
                ]);
                if (!$res['ok']) {
                    $pdo->rollBack();
                    $_SESSION['toast_msg']  = '⚠ ' . $res['reason'] . ' Use “Confirm anyway” to override.';
                    $_SESSION['toast_type'] = 'error';
                    header('Location: ' . $back . '&warn=' . $id); exit;
                }
            }
            $pdo->prepare("UPDATE bookings SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        erp_audit($pdo, 'update', 'bookings', $id, trim(($bk['customer_name'] ?? '') . ' (' . ($bk['booking_date'] ?? '') . ')') . ' - ' . ($bk['status'] ?? '?') . ' -> confirmed' . ($force ? ' (capacity override)' : ''));
        $bk['status'] = 'confirmed';
        if (function_exists('booking_send_confirmation')) { @booking_send_confirmation($bk, $type); }

        $_SESSION['toast_msg']  = $force ? 'Confirmed (capacity override) — email sent.' : 'Booking confirmed — email sent.';
        $_SESSION['toast_type'] = 'success';
        header('Location: ' . $back); exit;
    }

    header('Location: ' . $back); exit;

} catch (Throwable $e) {
    error_log('[ERP] bookings_action failed: ' . $e->getMessage());
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['toast_msg']  = 'Booking action error: ' . $e->getMessage();
    $_SESSION['toast_type'] = 'error';
    header('Location: /admin/admin.php?view=bookings'); exit;
}
