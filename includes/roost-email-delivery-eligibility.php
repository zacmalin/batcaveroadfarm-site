<?php
// Read-only pre-delivery eligibility check for claimed Roost service emails.
// Resolves the current verified recipient and checks service suppression; never sends mail.
require_once __DIR__ . '/roost-email-claim.php';
require_once __DIR__ . '/roost-email-recipient.php';
require_once __DIR__ . '/roost-email-suppressions.php';

/**
 * Re-read live membership and household state after a claim. Fail closed.
 * This is a prerequisite, not sufficient authorization to deliver: a future
 * sender must recheck eligibility immediately before dispatch and apply any
 * additional transport-level suppression state.
 */
function bcrf_roost_email_claim_eligible(PDO $pdo, int $id, string $token): bool {
    if ($id < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $stmt = $pdo->prepare("SELECT o.event_type,o.milestone_days,DATE_FORMAT(o.activity_at, '%Y-%m-%d %H:%i:%s.%f') AS activity_at,
        m.id AS active_member,m.role AS member_role,c.email AS recipient_email,
        c.email_verified_at,c.account_status,h.status AS household_status,
        DATE_FORMAT(h.last_qualifying_activity_at, '%Y-%m-%d %H:%i:%s.%f') AS last_qualifying_activity_at,a.balance_points,UTC_TIMESTAMP(3) AS now_utc
        FROM reward_email_outbox o
        JOIN reward_household_members m ON m.id=o.member_id
            AND m.household_id=o.household_id
        JOIN customers c ON c.id=m.customer_id
        JOIN reward_households h ON h.id=o.household_id
        LEFT JOIN reward_accounts a ON a.household_id=h.id
        WHERE o.id=? AND o.claim_token=? AND o.status='processing'
          AND m.revoked_at IS NULL AND m.verification_status='verified'");
    $stmt->execute([$id,$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['household_status'] !== 'active' ||
        $row['account_status'] !== 'active' ||
        bcrf_roost_verified_service_recipient((string)$row['recipient_email'],
            $row['email_verified_at'] !== null, false, (string)$row['member_role']) === null) return false;
    if (bcrf_roost_email_service_suppressed($pdo, (string)$row['recipient_email'])) return false;
    if ($row['event_type'] === 'welcome') return true;
    if ($row['event_type'] !== 'expiration_reminder' ||
        $row['last_qualifying_activity_at'] === null ||
        substr((string)$row['last_qualifying_activity_at'], 0, 23) !== substr((string)$row['activity_at'], 0, 23)) return false;
    return bcrf_roost_reminder_eligible((string)$row['household_status'],
        (int)$row['balance_points'], (string)$row['last_qualifying_activity_at'],
        (string)$row['now_utc'], (int)$row['milestone_days']);
}
