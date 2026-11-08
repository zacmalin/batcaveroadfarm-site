<?php
// Internal, manually invoked expiration processor. Not a web route or scheduled job.
// Each household is locked and rechecked before any deduction.
require_once __DIR__ . '/roost-expiration-preview.php';

function bcrf_roost_expire_household(PDO $pdo, int $householdId): array {
    if ($householdId < 1) throw new BcrfRewardError('Invalid household.');
    if ($pdo->inTransaction()) throw new BcrfRewardError('Expiration requires its own transaction.');
    $pdo->beginTransaction();
    try {
        // Account row is the same serialization lock used by the ledger writer.
        $lock = $pdo->prepare("SELECT a.balance_points, h.status, h.last_qualifying_activity_at,
            UTC_TIMESTAMP(3) AS now_utc
            FROM reward_accounts a JOIN reward_households h ON h.id=a.household_id
            WHERE a.household_id=? FOR UPDATE");
        $lock->execute([$householdId]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['status'] !== 'active') throw new BcrfRewardError('Reward account unavailable.');
        $balance = (int)$row['balance_points'];
        $activity = $row['last_qualifying_activity_at'];
        if ($balance <= 0 || $activity === null ||
            !bcrf_roost_is_inactive_at((string)$activity, (string)$row['now_utc'])) {
            $pdo->commit();
            return ['expired'=>false,'balance'=>$balance,'points'=>0];
        }
        $pol = $pdo->query("SELECT id FROM reward_policies WHERE status='active'
            AND (effective_at IS NULL OR effective_at <= UTC_TIMESTAMP(3))
            ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
        if (count($pol) !== 1) throw new BcrfRewardError('Exactly one active policy required.');
        // Deterministic event identity prevents a second expiration for the same
        // household/activity epoch, even if a caller retries with a different request.
        $key = 'roost:expire:'.$householdId.':'.str_replace([' ',':','.'],['T','',''],(string)$activity);
        $existing = $pdo->prepare("SELECT id FROM reward_ledger WHERE idempotency_key=?");
        $existing->execute([$key]);
        if ($existing->fetchColumn()) {
            $pdo->commit();
            return ['expired'=>false,'balance'=>$balance,'points'=>0];
        }
        $entry = $pdo->prepare("INSERT INTO reward_ledger
            (household_id,customer_id,policy_id,entry_type,points_delta,source_type,source_id,
             idempotency_key,metadata_json,created_at)
            VALUES (?,NULL,?,'expire',?,'inactivity',?,?,?,UTC_TIMESTAMP(3))");
        $entry->execute([$householdId,(int)$pol[0],-$balance,(string)$householdId,$key,
            json_encode(['last_qualifying_activity_at'=>$activity],JSON_THROW_ON_ERROR)]);
        $update = $pdo->prepare("UPDATE reward_accounts SET balance_points=0,updated_at=UTC_TIMESTAMP(3)
            WHERE household_id=?");
        $update->execute([$householdId]);
        $pdo->commit();
        return ['expired'=>true,'balance'=>0,'points'=>$balance];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
