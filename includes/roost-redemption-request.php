<?php
// Internal redemption request intake. This records intent only; no points are
// deducted, reserved, or exchanged until a separately approved fulfillment flow.
require_once __DIR__.'/roost-redemption-authorization.php';

function bcrf_roost_request_redemption(
    PDO $pdo,int $householdId,int $customerId,int $policyId,
    int $tier,string $benefitCode,string $requestToken
): array {
    if ($pdo->inTransaction()) throw new LogicException('Redemption request requires autocommit.');
    if ($householdId<=0 || $customerId<=0 || $policyId<=0 ||
        !in_array($tier,[250,500,1000,2000],true) ||
        !preg_match('/^[a-z][a-z0-9_-]{2,63}$/D',$benefitCode) ||
        !preg_match('/^[A-Za-z0-9_-]{12,64}$/D',$requestToken))
        throw new InvalidArgumentException('Invalid redemption request.');
    $pdo->beginTransaction();
    try {
        // Serialize with the household balance and redemption ledger. This
        // is a request, not a reservation: balance may change before approval.
        $account=$pdo->prepare('SELECT balance_points FROM reward_accounts WHERE household_id=? FOR UPDATE');
        $account->execute([$householdId]);
        $balance=$account->fetchColumn();
        if ($balance===false) throw new BcrfRewardError('Rewards household unavailable.');
        $existing=$pdo->prepare('SELECT id,household_id,customer_id,policy_id,tier_points,benefit_code,status
            FROM reward_redemption_requests WHERE request_token=? FOR UPDATE');
        $existing->execute([$requestToken]);
        $prior=$existing->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if ((int)$prior['household_id']!==$householdId || (int)$prior['customer_id']!==$customerId ||
                (int)$prior['policy_id']!==$policyId || (int)$prior['tier_points']!==$tier ||
                $prior['benefit_code']!==$benefitCode)
                throw new BcrfRewardError('Conflicting redemption request token.');
            $pdo->commit();
            return ['id'=>(int)$prior['id'],'status'=>$prior['status'],'replayed'=>true];
        }
        $auth=bcrf_roost_redemption_authorization($pdo,$householdId,$customerId,$tier);
        if (!$auth['authorized']) throw new BcrfRewardError('Redemption request is not authorized.');
        // No benefit catalog has been approved; reject unconfigured benefits.
        $catalog=$pdo->prepare("SELECT 1 FROM reward_redemption_benefits
            WHERE code=? AND tier_points=? AND status='active' LIMIT 1");
        $catalog->execute([$benefitCode,$tier]);
        if (!$catalog->fetchColumn()) throw new BcrfRewardError('Reward benefit is not available.');
        $insert=$pdo->prepare("INSERT INTO reward_redemption_requests
            (request_token,household_id,customer_id,policy_id,tier_points,benefit_code,status,requested_at,updated_at)
            VALUES (?,?,?,?,?,?,'requested',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
        $insert->execute([$requestToken,$householdId,$customerId,$policyId,$tier,$benefitCode]);
        $id=(int)$pdo->lastInsertId();
        $pdo->commit();
        return ['id'=>$id,'status'=>'requested','replayed'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
