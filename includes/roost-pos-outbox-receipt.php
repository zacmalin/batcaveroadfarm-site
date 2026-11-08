<?php
// Read-only verification of a committed POS ledger event after an outbox
// worker loses its acknowledgment. Never treats a mismatched event as replay.
function bcrf_roost_outbox_ledger_receipt(PDO $pdo, array $event): ?array {
    if ($pdo->inTransaction()) throw new LogicException('Receipt verification requires autocommit.');
    foreach (['idempotency_key','household_id','policy_id','entry_type','points_delta','source_type','source_id'] as $key)
        if (!array_key_exists($key,$event)) throw new InvalidArgumentException('Incomplete ledger event.');
    $st=$pdo->prepare('SELECT id,household_id,customer_id,policy_id,entry_type,points_delta,source_type,source_id,related_entry_id
        FROM reward_ledger WHERE idempotency_key=?');
    $st->execute([$event['idempotency_key']]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    foreach (['household_id','customer_id','policy_id','entry_type','points_delta','source_type','source_id','related_entry_id'] as $key) {
        if ((string)($row[$key]??'') !== (string)($event[$key]??'')) {
            throw new RuntimeException('Committed reward receipt conflicts with expected event.');
        }
    }
    if ($event['entry_type']==='refund_reversal') {
        $check=$pdo->prepare('SELECT household_id,original_earning_entry_id,points_reversed,reversal_entry_id
            FROM reward_refund_processing WHERE refund_id=?');
        $check->execute([$event['source_id']]);
        $refund=$check->fetch(PDO::FETCH_ASSOC);
        if (!$refund || (int)$refund['household_id']!==(int)$event['household_id'] ||
            (int)$refund['original_earning_entry_id']!==(int)$event['related_entry_id'] ||
            (int)$refund['points_reversed']!==-(int)$event['points_delta'] ||
            (int)$refund['reversal_entry_id']!==(int)$row['id']) {
            throw new RuntimeException('Committed refund reversal lacks matching processing receipt.');
        }
    }
    return ['ledger_entry_id'=>(int)$row['id'],'replayed'=>true];
}
