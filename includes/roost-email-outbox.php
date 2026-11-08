<?php
// Dormant Rewards service-email outbox writer. No sending or scheduler.
require_once __DIR__ . '/roost-email-events.php';
require_once __DIR__ . '/roost-email-recipient.php';
require_once __DIR__ . '/roost-email-suppressions.php';

/**
 * Caller must verify the committed membership and recipient before enqueue.
 * Database constraints provide one durable row per event across retries.
 * This method never sends or activates a service-email worker.
 */
function bcrf_roost_email_activity_epoch(string $value): string {
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,3})?$/D', $value)) {
        throw new BcrfRewardError('Invalid activity epoch.');
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', str_contains($value, '.') ? $value : $value.'.000', new DateTimeZone('UTC'));
    if ($parsed === false) throw new BcrfRewardError('Invalid activity epoch.');
    return $parsed->format('Y-m-d H:i:s.v');
}

function bcrf_roost_email_enqueue(
    PDO $pdo,
    int $householdId,
    int $memberId,
    string $eventType,
    ?int $milestoneDays,
    ?string $activityUtc,
    string $eventKey
): bool {
    if ($householdId < 1 || $memberId < 1 || strlen($eventKey) < 8 || strlen($eventKey) > 160) {
        throw new BcrfRewardError('Invalid service email event.');
    }
    if (($eventType === 'welcome' && ($milestoneDays !== null || $activityUtc !== null)) ||
        ($eventType === 'expiration_reminder' &&
            (!in_array($milestoneDays, [60, 30, 7], true) || $activityUtc === null)) ||
        !in_array($eventType, ['welcome', 'expiration_reminder'], true)) {
        throw new BcrfRewardError('Invalid service email type.');
    }
    if ($eventType === 'welcome' &&
        !preg_match('/^roost:welcome:v1:'.preg_quote((string)$memberId, '/').':[a-f0-9]{64}$/D', $eventKey)) {
        throw new BcrfRewardError('Invalid welcome email event key.');
    }
    // Validate the supplied key matches the canonical identity to prevent
    // accidental collisions across recipients, milestones or activity epochs.
    if ($eventType === 'expiration_reminder' &&
        $eventKey !== bcrf_roost_expiration_event_key($householdId, $memberId, (string)$activityUtc, (int)$milestoneDays)) {
        throw new BcrfRewardError('Mismatched expiration email event key.');
    }
    $check = $pdo->prepare("SELECT m.id,m.role,c.email,c.email_verified_at,c.account_status FROM reward_household_members m
        JOIN customers c ON c.id=m.customer_id
        JOIN reward_households h ON h.id=m.household_id
        WHERE m.id=? AND m.household_id=? AND m.revoked_at IS NULL
          AND m.verification_status='verified' AND h.status='active'");
    $check->execute([$memberId,$householdId]);
    $recipient = $check->fetch(PDO::FETCH_ASSOC);
    if (!$recipient || $recipient['account_status'] !== 'active' ||
        bcrf_roost_verified_service_recipient((string)$recipient['email'],
            $recipient['email_verified_at'] !== null, false, (string)$recipient['role']) === null) {
        throw new BcrfRewardError('Verified active adult recipient required.');
    }
    if (bcrf_roost_email_service_suppressed($pdo, (string)$recipient['email'])) {
        throw new BcrfRewardError('Service email recipient suppressed.');
    }

    // Recheck the exact millisecond activity epoch before queueing.
    if ($eventType === 'expiration_reminder') {
        $activityUtc = bcrf_roost_email_activity_epoch($activityUtc);
    }
    // Recheck that the activity snapshot is still current before queueing.
    if ($eventType === 'expiration_reminder') {
        $activityCheck = $pdo->prepare("SELECT DATE_FORMAT(h.last_qualifying_activity_at, '%Y-%m-%d %H:%i:%s.%f') AS last_qualifying_activity_at,
            a.balance_points, h.status, UTC_TIMESTAMP(3) AS now_utc
            FROM reward_households h JOIN reward_accounts a ON a.household_id=h.id
            WHERE h.id=?");
        $activityCheck->execute([$householdId]);
        $current = $activityCheck->fetch(PDO::FETCH_ASSOC);
        if (!$current ||
            substr((string)$current['last_qualifying_activity_at'], 0, 23) !== (string)$activityUtc ||
            !bcrf_roost_reminder_eligible((string)$current['status'], (int)$current['balance_points'],
                (string)$current['last_qualifying_activity_at'], (string)$current['now_utc'], (int)$milestoneDays)) {
            return false;
        }
    }
    // The unique event key makes retries idempotent; other SQL errors surface.
    $stmt = $pdo->prepare("INSERT INTO reward_email_outbox
        (household_id,member_id,event_type,milestone_days,activity_at,event_key,status,
         available_at,created_at,updated_at)
        VALUES (?,?,?,?,?,?,'pending',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE id=id");
    $stmt->execute([$householdId,$memberId,$eventType,$milestoneDays,$activityUtc,$eventKey]);
    return $stmt->rowCount() === 1;
}
