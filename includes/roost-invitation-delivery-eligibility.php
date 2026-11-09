<?php
// Read-only, fail-closed pre-dispatch authorization for a claimed invitation.
// Never sends email, decrypts payloads, or returns recipient addresses.
function bcrf_roost_invitation_delivery_eligible(PDO $pdo,int $jobId,string $claimToken): bool {
    if ($jobId<1 || !preg_match('/^[a-f0-9]{64}$/D',$claimToken)) return false;
    $q=$pdo->prepare("SELECT i.status AS invitation_status,i.expires_at,
        m.role,m.verification_status,m.revoked_at,
        h.status AS household_status,c.account_status,c.email_verified_at,
        UTC_TIMESTAMP(3) AS now_utc
        FROM reward_invitation_delivery_outbox o
        JOIN reward_household_invitations i ON i.id=o.invitation_id
        JOIN reward_households h ON h.id=i.household_id
        JOIN reward_household_members m ON m.customer_id=i.invited_by_customer_id
            AND m.household_id=i.household_id
        JOIN customers c ON c.id=m.customer_id
        WHERE o.id=? AND o.claim_token=? AND o.status='processing'
          AND o.attempts BETWEEN 1 AND 3");
    $q->execute([$jobId,$claimToken]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return $row['invitation_status']==='pending' &&
        strcmp((string)$row['expires_at'],(string)$row['now_utc'])>0 &&
        $row['household_status']==='active' &&
        $row['role']==='manager' &&
        $row['verification_status']==='verified' &&
        $row['revoked_at']===null &&
        $row['account_status']==='active' &&
        $row['email_verified_at']!==null;
}
