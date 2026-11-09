<?php
// Atomic issuance of an approved internal redemption receipt and points debit.
// Not a physical-goods fulfillment adapter. Never call for an external benefit
// until that benefit's delivery is independently guaranteed by this transaction.
require_once __DIR__.'/rewards.php';

function bcrf_roost_issue_redemption_receipt(PDO $pdo,int $requestId,int $staffId): array {
    if ($requestId<=0 || $staffId<=0) throw new InvalidArgumentException('Invalid redemption issuance.');
    if ($pdo->inTransaction()) throw new LogicException('Redemption issuance requires autocommit.');
    // Fetch household identity, then lock the household account first to
    // preserve the same lock ordering as ordinary ledger posting.
    $lookup=$pdo->prepare('SELECT household_id FROM reward_redemption_requests WHERE id=?');
    $lookup->execute([$requestId]);
    $householdId=(int)$lookup->fetchColumn();
    if (!$householdId) throw new BcrfRewardError('Redemption request not found.');
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare("SELECT a.balance_points,h.status
            FROM reward_accounts a JOIN reward_households h ON h.id=a.household_id
            WHERE a.household_id=? FOR UPDATE");
        $lock->execute([$householdId]);
        $account=$lock->fetch(PDO::FETCH_ASSOC);
        if (!$account || $account['status']!=='active')
            throw new BcrfRewardError('Rewards household unavailable.');
        $q=$pdo->prepare('SELECT * FROM reward_redemption_requests WHERE id=? FOR UPDATE');
        $q->execute([$requestId]);
        $request=$q->fetch(PDO::FETCH_ASSOC);
        if (!$request || (int)$request['household_id']!==$householdId)
            throw new BcrfRewardError('Redemption request changed.');
        if ($request['status']==='fulfilled') {
            if (!$request['ledger_entry_id'] || !$request['fulfillment_receipt'])
                throw new BcrfRewardError('Fulfillment receipt is incomplete.');
            $pdo->commit();
            return ['id'=>$requestId,'ledger_entry_id'=>(int)$request['ledger_entry_id'],
                'receipt'=>$request['fulfillment_receipt'],'replayed'=>true];
        }
        if ($request['status']!=='approved')
            throw new BcrfRewardError('Redemption request has not been approved.');
        $tier=(int)$request['tier_points'];
        if (!in_array($tier,[250,500,1000,2000],true) ||
            (int)$account['balance_points']<$tier)
            throw new BcrfRewardError('Insufficient points for redemption.');
        $member=$pdo->prepare("SELECT m.id FROM reward_household_members m
            JOIN customers c ON c.id=m.customer_id
            WHERE m.household_id=? AND m.customer_id=? AND m.role IN ('manager','adult')
              AND m.can_redeem=1 AND m.verification_status='verified' AND m.revoked_at IS NULL
              AND c.account_status='active' AND c.email_verified_at IS NOT NULL FOR UPDATE");
        $member->execute([$householdId,(int)$request['customer_id']]);
        if (!$member->fetchColumn()) throw new BcrfRewardError('Redeemer is no longer eligible.');
        $policy=$pdo->prepare("SELECT 1 FROM reward_policies WHERE id=? AND status='active'
            AND (effective_at IS NULL OR effective_at<=UTC_TIMESTAMP(3))");
        $policy->execute([(int)$request['policy_id']]);
        if (!$policy->fetchColumn()) throw new BcrfRewardError('Redemption policy inactive.');
        $benefit=$pdo->prepare("SELECT 1 FROM reward_redemption_benefits
            WHERE code=? AND tier_points=? AND status='active' AND approved_at IS NOT NULL
              AND fulfillment_type='internal_voucher'");
        $benefit->execute([$request['benefit_code'],$tier]);
        if (!$benefit->fetchColumn()) throw new BcrfRewardError('Benefit is not approved for internal voucher issuance.');
        // The receipt is a durable internal entitlement, created in the SAME
        // database transaction as the ledger debit and request completion.
        $receipt='roost_voucher_'.str_pad((string)$requestId,12,'0',STR_PAD_LEFT);
        $source=$pdo->prepare("SELECT id FROM reward_ledger WHERE entry_type='redeem'
            AND source_type='reward_fulfillment' AND source_id=?");
        $source->execute([$receipt]);
        if ($source->fetchColumn()) throw new BcrfRewardError('Fulfillment receipt already consumed.');
        $ledger=$pdo->prepare("INSERT INTO reward_ledger
            (household_id,customer_id,policy_id,entry_type,points_delta,
             source_type,source_id,idempotency_key,metadata_json,created_at,created_by)
            VALUES (?,?,?,'redeem',-?,'reward_fulfillment',?,?,?,UTC_TIMESTAMP(3),?)");
        $ledger->execute([$householdId,(int)$request['customer_id'],(int)$request['policy_id'],
            $tier,$receipt,'roost:redeem:'.$receipt,
            json_encode(['request_id'=>$requestId,'benefit_code'=>$request['benefit_code']],JSON_THROW_ON_ERROR),
            $staffId]);
        $ledgerId=(int)$pdo->lastInsertId();
        $upd=$pdo->prepare("UPDATE reward_accounts
            SET balance_points=balance_points-?,lifetime_redeemed=lifetime_redeemed+?,
                updated_at=UTC_TIMESTAMP(3) WHERE household_id=?");
        $upd->execute([$tier,$tier,$householdId]);
        $done=$pdo->prepare("UPDATE reward_redemption_requests
            SET status='fulfilled',fulfilled_at=UTC_TIMESTAMP(3),
                fulfillment_receipt=?,ledger_entry_id=?,updated_at=UTC_TIMESTAMP(3)
            WHERE id=? AND status='approved'");
        $done->execute([$receipt,$ledgerId,$requestId]);
        if ($done->rowCount()!==1) throw new BcrfRewardError('Redemption completion conflict.');
        $pdo->commit();
        return ['id'=>$requestId,'ledger_entry_id'=>$ledgerId,'receipt'=>$receipt,'replayed'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
