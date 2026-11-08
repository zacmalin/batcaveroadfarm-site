<?php
// Trusted internal operation; never expose directly to customer requests.
require_once __DIR__ . '/roost-refund-preview.php';
function bcrf_roost_record_zero_point_refund(PDO $pdo, int $refundId, int $earningEntryId): array {
    if ($refundId <= 0 || $earningEntryId <= 0 || $pdo->inTransaction()) {
        throw new BcrfRewardError('Invalid refund processing request.');
    }
    $pdo->beginTransaction();
    try {
        $origin = $pdo->prepare("SELECT household_id, entry_type, source_type, source_id, points_delta
            FROM reward_ledger WHERE id=? FOR UPDATE");
        $origin->execute([$earningEntryId]);
        $earned = $origin->fetch(PDO::FETCH_ASSOC);
        if (!$earned || $earned['entry_type'] !== 'earn_spend' || $earned['source_type'] !== 'sale')
            throw new BcrfRewardError('Invalid original sale earning.');
        $household = (int)$earned['household_id'];
        $lock = $pdo->prepare("SELECT balance_points FROM reward_accounts WHERE household_id=? FOR UPDATE");
        $lock->execute([$household]);
        if ($lock->fetchColumn() === false) throw new BcrfRewardError('Reward account unavailable.');
        $refund = $pdo->prepare("SELECT sale_id FROM refunds WHERE id=? FOR UPDATE");
        $refund->execute([$refundId]);
        $saleId = $refund->fetchColumn();
        if ($saleId === false || (string)$saleId !== (string)$earned['source_id'])
            throw new BcrfRewardError('Refund does not match original sale.');
        $existing = $pdo->prepare("SELECT original_earning_entry_id, points_reversed FROM reward_refund_processing WHERE refund_id=?");
        $existing->execute([$refundId]);
        if ($saved = $existing->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$saved['original_earning_entry_id'] !== $earningEntryId || (int)$saved['points_reversed'] !== 0)
                throw new BcrfRewardError('Refund was processed with different details.');
            $pdo->commit();
            return ['replayed'=>true,'points_reversed'=>0];
        }
        $preview = bcrf_roost_refund_reversal_preview($pdo, (int)$saleId);
        $prior = $pdo->prepare("SELECT COALESCE(SUM(points_reversed),0) FROM reward_refund_processing
            WHERE original_earning_entry_id=?");
        $prior->execute([$earningEntryId]);
        $already = (int)$prior->fetchColumn();
        $due = bcrf_roost_refund_points_for_id($preview, $refundId, $already);
        if ($due !== 0) throw new BcrfRewardError('Refund requires a point reversal.');
        $insert = $pdo->prepare("INSERT INTO reward_refund_processing
            (refund_id, household_id, original_earning_entry_id, points_reversed, reversal_entry_id, processed_at)
            VALUES (?, ?, ?, 0, NULL, UTC_TIMESTAMP(3))");
        $insert->execute([$refundId,$household,$earningEntryId]);
        $pdo->commit();
        return ['replayed'=>false,'points_reversed'=>0];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
