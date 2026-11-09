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
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT id,household_id,customer_id,tier_points,benefit_code,status
            FROM reward_redemption_requests WHERE id=? FOR UPDATE');
        $q->execute([$requestId]);
        $request=$q->fetch(PDO::FETCH_ASSOC);
        if (!$request) throw new BcrfRewardError('Redemption request not found.');
        if ($request['status']===$decision) {
            $pdo->commit();
            return ['id'=>$requestId,'status'=>$decision,'replayed'=>true];
        }
        if ($request['status']!=='requested')
            throw new BcrfRewardError('Redemption request is no longer reviewable.');
        if ($decision==='approved') {
            $auth=bcrf_roost_redemption_authorization(
                $pdo,(int)$request['household_id'],(int)$request['customer_id'],
                (int)$request['tier_points']
            );
            if (!$auth['authorized'])
                throw new BcrfRewardError('Redemption authorization no longer valid.');
            $benefit=$pdo->prepare("SELECT 1 FROM reward_redemption_benefits
                WHERE code=? AND tier_points=? AND status='active' LIMIT 1");
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
