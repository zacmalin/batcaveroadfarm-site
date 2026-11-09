<?php
// Internal staff review of redemption requests. This changes request status
// only; it never issues a benefit or deducts points.
require_once __DIR__.'/roost-redemption-authorization.php';

function bcrf_roost_review_redemption(
    PDO $pdo,int $requestId,int $staffId,string $decision
): array {
    if ($pdo->inTransaction()) throw new LogicException('Redemption review requires autocommit.');
    if ($requestId<=0 || $staffId<=0 || !in_array($decision,['approved','rejected'],true))
        throw new InvalidArgumentException('Invalid redemption review.');
    // Match ledger and issuance lock order: account before request.
    $lookup=$pdo->prepare('SELECT household_id FROM reward_redemption_requests WHERE id=?');
    $lookup->execute([$requestId]);
    $householdId=(int)$lookup->fetchColumn();
    if (!$householdId) throw new BcrfRewardError('Redemption request not found.');
    $pdo->beginTransaction();
    try {
        $accountLock=$pdo->prepare('SELECT household_id FROM reward_accounts WHERE household_id=? FOR UPDATE');
        $accountLock->execute([$householdId]);
        if (!$accountLock->fetchColumn()) throw new BcrfRewardError('Reward account unavailable.');
        $q=$pdo->prepare('SELECT id,household_id,customer_id,tier_points,benefit_code,status
            FROM reward_redemption_requests WHERE id=? FOR UPDATE');
        $q->execute([$requestId]);
        $request=$q->fetch(PDO::FETCH_ASSOC);
        if (!$request || (int)$request['household_id']!==$householdId)
            throw new BcrfRewardError('Redemption request changed.');
        if ($request['status']===$decision) {
            $pdo->commit();
            return ['id'=>$requestId,'status'=>$decision,'replayed'=>true];
        }
        if ($request['status']!=='requested')
            throw new BcrfRewardError('Redemption request is no longer reviewable.');
        if ($decision==='approved') {
            $auth=$pdo->prepare("SELECT a.balance_points,h.status AS household_status,
                m.role,m.can_redeem,m.verification_status,m.revoked_at,
                c.account_status,c.email_verified_at
                FROM reward_accounts a JOIN reward_households h ON h.id=a.household_id
                JOIN reward_household_members m ON m.household_id=h.id AND m.customer_id=?
                JOIN customers c ON c.id=m.customer_id
                WHERE a.household_id=? FOR UPDATE");
            $auth->execute([(int)$request['customer_id'],(int)$request['household_id']]);
            $eligibility=$auth->fetch(PDO::FETCH_ASSOC);
            if (!$eligibility || $eligibility['household_status']!=='active' ||
                $eligibility['account_status']!=='active' || $eligibility['email_verified_at']===null ||
                $eligibility['verification_status']!=='verified' || $eligibility['revoked_at']!==null ||
                !in_array($eligibility['role'],['manager','adult'],true) ||
                (int)$eligibility['can_redeem']!==1 ||
                (int)$eligibility['balance_points']<(int)$request['tier_points'])
                throw new BcrfRewardError('Redemption authorization no longer valid.');
            $benefit=$pdo->prepare("SELECT 1 FROM reward_redemption_benefits
                WHERE code=? AND tier_points=? AND status='active'
                  AND approved_at IS NOT NULL AND fulfillment_type='internal_voucher' LIMIT 1");
            $benefit->execute([$request['benefit_code'],$request['tier_points']]);
            if (!$benefit->fetchColumn())
                throw new BcrfRewardError('Reward benefit is no longer available.');
        }
        $u=$pdo->prepare('UPDATE reward_redemption_requests SET status=?,
            reviewed_by_staff_id=?,reviewed_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
            WHERE id=? AND status=\'requested\'');
        $u->execute([$decision,$staffId,$requestId]);
        if ($u->rowCount()!==1) throw new BcrfRewardError('Redemption review conflict.');
        $pdo->commit();
        return ['id'=>$requestId,'status'=>$decision,'replayed'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
