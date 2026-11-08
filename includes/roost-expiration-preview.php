<?php
// Roost Rewards inactivity eligibility preview; no mutation, no scheduled execution.
// Uses UTC calendar months, not a fixed 365-day approximation.
if (!class_exists('BcrfRewardError')) {
    class BcrfRewardError extends RuntimeException {}
}

/** Returns whether 12 complete calendar months have elapsed since activity. */
function bcrf_roost_is_inactive_at(string $lastActivityUtc, string $nowUtc): bool {
    $tz = new DateTimeZone('UTC');
    $now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $nowUtc, $tz);
    if ($now === false) $now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $nowUtc, $tz);
    if ($now === false) throw new BcrfRewardError('Invalid UTC activity timestamp.');
    return $now >= bcrf_roost_expiration_at($lastActivityUtc);
}

/**
 * Shared UTC anniversary for expiration and future service-email reminders.
 * Pure calculation only: does not schedule, send, or expire points.
 */
function bcrf_roost_expiration_at(string $lastActivityUtc): DateTimeImmutable {
    $tz = new DateTimeZone('UTC');
    $last = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $lastActivityUtc, $tz);
    if ($last === false) $last = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastActivityUtc, $tz);
    if ($last === false) throw new BcrfRewardError('Invalid UTC activity timestamp.');
    $year = (int)$last->format('Y') + 1;
    $month = (int)$last->format('m');
    $day = min((int)$last->format('d'), cal_days_in_month(CAL_GREGORIAN, $month, $year));
    return $last->setDate($year, $month, $day);
}

/**
 * Returns the exact reminder milestone (60, 30 or 7 calendar days remaining),
 * or null outside a milestone day. Callers must independently recheck household
 * status, balance, recipient authorization and activity before any future send.
 */
function bcrf_roost_expiration_reminder_day(string $lastActivityUtc, string $nowUtc): ?int {
    $expiration = bcrf_roost_expiration_at($lastActivityUtc);
    $tz = new DateTimeZone('UTC');
    $now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $nowUtc, $tz);
    if ($now === false) $now = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $nowUtc, $tz);
    if ($now === false) throw new BcrfRewardError('Invalid UTC reminder timestamp.');
    if ($now >= $expiration) return null;
    foreach ([60, 30, 7] as $days) {
        $start = $expiration->modify("-$days days");
        // Calendar-day milestones are anchored to the exact UTC anniversary
        // instant, not midnight; never send after the 24-hour window.
        if ($now >= $start && $now < $start->modify('+1 day')) return $days;
    }
    return null;
}

/**
 * Read-only candidate listing. Missing activity dates require manual reconciliation,
 * not automatic expiration. No points are removed by this function.
 */
function bcrf_roost_expiration_candidates(PDO $pdo, int $limit = 100): array {
    if ($limit < 1 || $limit > 500) throw new BcrfRewardError('Invalid preview limit.');
    $query = $pdo->prepare("SELECT h.id AS household_id, h.last_qualifying_activity_at, a.balance_points
        FROM reward_households h JOIN reward_accounts a ON a.household_id = h.id
        WHERE h.status = 'active' AND h.last_qualifying_activity_at IS NOT NULL
          AND a.balance_points > 0
          AND h.last_qualifying_activity_at <= UTC_TIMESTAMP(3) - INTERVAL 1 YEAR
        ORDER BY h.last_qualifying_activity_at ASC, h.id ASC LIMIT ?");
    $query->bindValue(1, $limit, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
