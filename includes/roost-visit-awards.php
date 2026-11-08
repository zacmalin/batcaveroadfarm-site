<?php
// Trusted Roost Rewards visit-award orchestrator. Not a public route.
// Requires a previously staff-verified individual attendance record.
// The ledger performs a second locked provenance check and deduplicates the source.
require_once __DIR__ . '/rewards.php';

function bcrf_roost_award_verified_visit(PDO $pdo, int $attendanceId): array {
    if ($attendanceId < 1) throw new BcrfRewardError('Verified attendance ID required.');
    if ($pdo->inTransaction()) throw new BcrfRewardError('Visit awards require their own transaction.');
    $st = $pdo->prepare("SELECT a.customer_id, m.household_id
        FROM reward_verified_attendance a
        JOIN reward_household_members m ON m.customer_id = a.customer_id
          AND m.revoked_at IS NULL AND m.verification_status = 'verified'
        JOIN reward_households h ON h.id = m.household_id AND h.status = 'active'
        WHERE a.id = ?");
    $st->execute([$attendanceId]);
    $memberships = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($memberships) !== 1) throw new BcrfRewardError('Attendance has no unique verified active household.');
    $pol = $pdo->query("SELECT id FROM reward_policies WHERE status='active'
        AND (effective_at IS NULL OR effective_at <= UTC_TIMESTAMP(3)) ORDER BY id DESC LIMIT 2");
    $policies = $pol->fetchAll(PDO::FETCH_COLUMN);
    if (count($policies) !== 1) throw new BcrfRewardError('Exactly one active reward policy required.');
    return bcrf_reward_post($pdo, [
        'household_id'=>(int)$memberships[0]['household_id'],
        'customer_id'=>(int)$memberships[0]['customer_id'],
        'policy_id'=>(int)$policies[0],
        'entry_type'=>'earn_visit',
        'points_delta'=>25,
        'source_type'=>'visit',
        'source_id'=>(string)$attendanceId,
        'idempotency_key'=>'roost:visit:'.(string)$attendanceId,
        'metadata'=>['origin'=>'staff_verified_attendance']
    ]);
}
