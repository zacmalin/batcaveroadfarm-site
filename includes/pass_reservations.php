<?php
// Founding Member pass reservations. Deploy only with the coordinated checkout/webhook changes.
// All callers must use the same pass_types row lock when allocating a limited slot.
declare(strict_types=1);

function bcrf_pass_reserve(PDO $pdo, int $typeId, string $name, string $email, ?string $phone, string $token): array {
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM pass_types WHERE id=? AND is_active=1 FOR UPDATE');
        $q->execute([$typeId]);
        $type = $q->fetch(PDO::FETCH_ASSOC);
        if (!$type) { $pdo->rollBack(); throw new DomainException('type'); }

        $limited = !empty($type['is_founding']) && (int)($type['founding_qty_limit'] ?? 0) > 0;
        if ($limited) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM passes WHERE pass_type_id=?
                AND ((payment_status IN ('paid','waived'))
                  OR (status='pending_payment' AND payment_status='pending'
                      AND (reservation_expires_at > NOW()
                           OR (reservation_expires_at IS NULL AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)))))");
            $q->execute([$typeId]);
            if ((int)$q->fetchColumn() >= (int)$type['founding_qty_limit']) {
                $pdo->rollBack();
                throw new DomainException('sold_out');
            }
        }
        $q = $pdo->prepare("INSERT INTO passes
            (pass_type_id, holder_name, email, phone, token, status, payment_status, amount_paid, reservation_expires_at)
            VALUES (?,?,?,?,?,'pending_payment','pending',?,IF(?=1, DATE_ADD(NOW(), INTERVAL 30 MINUTE), NULL))");
        $q->execute([$typeId, $name, $email, $phone, $token, (float)$type['price'], $limited ? 1 : 0]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['id'=>$id, 'type'=>$type, 'limited'=>$limited];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Must run while holding the same pass_types FOR UPDATE lock used by reservation creation.
// Return 'active', 'late', or 'already'. Late payments require manual reconciliation,
// not silent activation or an automatic refund.
function bcrf_pass_confirm_payment(PDO $pdo, int $passId, string $sessionId): string {
    $q = $pdo->prepare('SELECT pass_type_id FROM passes WHERE id=?');
    $q->execute([$passId]);
    $typeId = (int)$q->fetchColumn();
    if (!$typeId) return 'already';
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id FROM pass_types WHERE id=? FOR UPDATE');
        $q->execute([$typeId]);
        if (!$q->fetchColumn()) throw new RuntimeException('Pass type missing');
        $q = $pdo->prepare('SELECT p.*, t.is_founding, t.founding_qty_limit, t.duration_months FROM passes p JOIN pass_types t ON t.id=p.pass_type_id WHERE p.id=? FOR UPDATE');
        $q->execute([$passId]);
        $pass = $q->fetch(PDO::FETCH_ASSOC);
        if (!$pass || $pass['status'] !== 'pending_payment') {
            $pdo->commit();
            return 'already';
        }
        $limited = !empty($pass['is_founding']) && (int)$pass['founding_qty_limit'] > 0;
        $late = $limited && ($pass['reservation_expires_at'] === null ||
            strtotime($pass['reservation_expires_at'] . ' UTC') <= time());
        if ($late) {
            $q = $pdo->prepare("UPDATE passes SET payment_status='paid', payment_provider='stripe', payment_reference=? WHERE id=? AND status='pending_payment'");
            $q->execute([$sessionId, $passId]);
            $pdo->commit();
            error_log('[BCRF founding pass] LATE PAID reservation requires manual reconciliation, pass #'.$passId);
            return 'late';
        }
        $q = $pdo->prepare("UPDATE passes SET payment_status='paid', status='active',
            valid_from=COALESCE(valid_from,CURDATE()),
            valid_until=DATE_ADD(COALESCE(valid_from,CURDATE()), INTERVAL ? MONTH),
            payment_provider='stripe', payment_reference=? WHERE id=? AND status='pending_payment'");
        $q->execute([max(1,(int)$pass['duration_months']), $sessionId, $passId]);
        $pdo->commit();
        return 'active';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
