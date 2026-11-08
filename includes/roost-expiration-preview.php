<?php
// Roost Rewards inactivity eligibility preview; no mutation, no scheduled execution.
// Uses UTC calendar months, not a fixed 365-day approximation.
if (!class_exists('BcrfRewardError')) {
    class BcrfRewardError extends RuntimeException {}
}

/** Returns whether 12 complete calendar months have elapsed since activity. */
function bcrf_roost_is_inactive_at(string $lastActivityUtc, string $nowUtc): bool {
    $tz = new DateTimeZone('UTC');
    $parse = static function (string $value) use ($tz): DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, $tz);
        if ($date === false) $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz);
        if ($date === false) throw new BcrfRewardError('Invalid UTC activity timestamp.');
        return $date;
    };
    $last = $parse($lastActivityUtc);
    $now = $parse($nowUtc);
    if ($now < $last) return false;
    // Clamp month-end anniversaries: Jan 31 -> Jan 31 next year,
    // Feb 29 -> Feb 28 on non-leap years.
    $anniversaryYear = (int)$last->format('Y') + 1;
    $month = (int)$last->format('m');
    $day = min((int)$last->format('d'), cal_days_in_month(CAL_GREGORIAN, $month, $anniversaryYear));
    $anniversary = $last->setDate($anniversaryYear, $month, $day);
    return $now >= $anniversary;
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
