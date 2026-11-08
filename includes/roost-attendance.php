<?php
// Roost Rewards staff-only attendance verification. Does NOT award points.
// The caller must use a CSRF-protected POST endpoint; no public route is exposed here.
require_once __DIR__ . '/pass_staff.php';
if (!class_exists('BcrfRewardError')) {
    class BcrfRewardError extends RuntimeException {}
}
function bcrf_roost_verify_attendee(PDO $pdo, int $checkInId, int $customerId): int {
    if ($checkInId < 1 || $customerId < 1) throw new BcrfRewardError('Invalid check-in or customer.');
    if (!bcrf_pass_can_log()) throw new BcrfRewardError('Staff permission required.');
    $staffId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$staffId) throw new BcrfRewardError('Authenticated staff required.');
    if ($pdo->inTransaction()) throw new BcrfRewardError('Attendance verification requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, business_date, party_size FROM check_ins WHERE id = ? FOR UPDATE");
        $st->execute([$checkInId]);
        $checkIn = $st->fetch(PDO::FETCH_ASSOC);
        if (!$checkIn || (int)$checkIn['party_size'] < 1) throw new BcrfRewardError('Check-in unavailable.');
        $member = $pdo->prepare("SELECT m.id FROM reward_household_members m JOIN reward_households h ON h.id = m.household_id
            WHERE m.customer_id = ? AND m.revoked_at IS NULL AND m.verification_status = 'verified'
              AND h.status = 'active' FOR UPDATE");
        $member->execute([$customerId]);
        if (count($member->fetchAll(PDO::FETCH_COLUMN)) !== 1) throw new BcrfRewardError('Customer is not a verified active reward member.');
        $count = $pdo->prepare("SELECT COUNT(*) FROM reward_verified_attendance WHERE check_in_id = ?");
        $count->execute([$checkInId]);
        if ((int)$count->fetchColumn() >= (int)$checkIn['party_size']) throw new BcrfRewardError('All check-in attendee slots are already verified.');
        $ins = $pdo->prepare("INSERT INTO reward_verified_attendance
            (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at)
            VALUES (?,?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $ins->execute([$checkInId,$customerId,$checkIn['business_date'],$staffId]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') throw new BcrfRewardError('Customer already verified for this operating day.', 0, $e);
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
