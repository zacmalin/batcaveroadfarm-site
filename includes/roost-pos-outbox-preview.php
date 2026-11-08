<?php
// Read-only trusted preview for a claimed POS outbox event.
// No automatic points are posted here; caller must validate policy and
// original ledger provenance before any separate mutation.
require_once __DIR__.'/rewards-pos-preview.php';
require_once __DIR__.'/roost-refund-preview.php';
function bcrf_roost_outbox_event_preview(PDO $pdo, array $event): array {
    if ($pdo->inTransaction()) throw new LogicException('Outbox preview requires autocommit.');
    $kind=$event['event_type']??null;
    $id=filter_var($event['source_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id || !in_array($kind,['sale.completed','sale.refunded'],true))
        throw new InvalidArgumentException('Invalid POS outbox event.');
    if ($kind==='sale.completed') {
        $owner=bcrf_rewards_sale_verified_household($pdo,(int)$id);
        $eligible=bcrf_rewards_sale_eligible_cents($pdo,(int)$id);
        $ownerAgain=bcrf_rewards_sale_verified_household($pdo,(int)$id);
        if ($owner!==$ownerAgain) throw new BcrfRewardError('Sale household changed during preview.');
        return ['kind'=>$kind,'sale_id'=>(int)$id,'household_id'=>$owner['household_id'],
            'customer_id'=>$owner['customer_id'],'eligible_cents'=>$eligible,
            'points'=>bcrf_rewards_points_for_eligible_cents($eligible)];
    }
    $refund=$pdo->prepare('SELECT sale_id FROM refunds WHERE id=?');
    $refund->execute([(int)$id]);
    $saleId=$refund->fetchColumn();
    if ($saleId===false) throw new BcrfRewardError('Refund not found.');
    $origin=$pdo->prepare("SELECT id,household_id,customer_id,points_delta
        FROM reward_ledger WHERE entry_type='earn_spend' AND source_type='sale' AND source_id=?");
    $origin->execute([(string)$saleId]);
    $rows=$origin->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1) throw new BcrfRewardError('Refund has no unique original rewarded sale.');
    $earning=$rows[0];
    $preview=bcrf_roost_refund_reversal_preview($pdo,(int)$saleId);
    $expectedPoints=bcrf_rewards_points_for_eligible_cents((int)$preview['original_eligible_cents']);
    if ((int)$earning['points_delta'] !== $expectedPoints || $expectedPoints < 0)
        throw new BcrfRewardError('Original earning does not match verified eligible sale points.');
    $saleOwner=bcrf_rewards_sale_verified_household($pdo,(int)$saleId);
    if ($saleOwner['household_id'] !== (int)$earning['household_id'] ||
        $saleOwner['customer_id'] !== (int)$earning['customer_id'])
        throw new BcrfRewardError('Original earning household or customer no longer matches sale.');
    $processed=$pdo->prepare('SELECT COALESCE(SUM(points_reversed),0) FROM reward_refund_processing WHERE original_earning_entry_id=?');
    $processed->execute([(int)$earning['id']]);
    $already=(int)$processed->fetchColumn();
    $due=bcrf_roost_refund_points_for_id($preview,(int)$id,$already);
    $refundAgain=$pdo->prepare('SELECT sale_id FROM refunds WHERE id=?');
    $refundAgain->execute([(int)$id]);
    if ((string)$refundAgain->fetchColumn() !== (string)$saleId)
        throw new BcrfRewardError('Refund sale association changed during preview.');
    $saleOwnerAgain=bcrf_rewards_sale_verified_household($pdo,(int)$saleId);
    if ($saleOwnerAgain !== $saleOwner)
        throw new BcrfRewardError('Sale household changed during refund preview.');
    return ['kind'=>$kind,'refund_id'=>(int)$id,'sale_id'=>(int)$saleId,
        'original_earning_entry_id'=>(int)$earning['id'],
        'household_id'=>(int)$earning['household_id'],
        'customer_id'=>(int)$earning['customer_id'],'points_to_reverse'=>$due];
}
