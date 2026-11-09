<?php
// Read-only customer Rewards dashboard. No enrollment or points mutation.
// Unavailable until the separately approved Rewards migrations are installed.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customer_auth.php';
require_once __DIR__.'/../includes/farm.php';
if (!is_logged_in()) {
    header('Location: /account/login.php?next=%2Froost-rewards%2Fbalance.php');
    exit;
}
$customer = bcrf_customer_find($pdo, current_user_id());
$verified = $customer && bcrf_customer_active($customer) && !empty($customer['email_verified_at']);
$balance = null;
$unavailable = false;
$history = [];
$householdSummary = null;
$redemptionTiers = [];
$invitationSummary = [];
if ($verified) {
    try {
        require_once __DIR__.'/../includes/roost-customer-balance.php';
        $balance = bcrf_roost_customer_balance($pdo, (int)$customer['id']);
        require_once __DIR__.'/../includes/roost-customer-history.php';
        if ($balance !== null) {
            $history = bcrf_roost_customer_history($pdo, (int)$customer['id']);
            require_once __DIR__.'/../includes/roost-customer-household-summary.php';
            $householdSummary = bcrf_roost_customer_household_summary($pdo, (int)$customer['id']);
            if ($householdSummary !== null && $householdSummary['role'] === 'manager') {
                require_once __DIR__.'/../includes/roost-manager-invitation-summary.php';
                $invitationSummary = bcrf_roost_manager_invitation_summary($pdo, (int)$customer['id']);
            }
            require_once __DIR__.'/../includes/roost-redemption-tier-preview.php';
            $redemptionTiers = bcrf_roost_redemption_tier_preview($balance['balance_points']);
        }
    } catch (Throwable $e) {
        // No Rewards tables on production yet; do not expose SQL errors or user data.
        error_log('[Roost customer balance unavailable] '.get_class($e));
        $unavailable = true;
    }
}
$pageTitle='Roost Rewards Balance | Bat Cave Road Farm';
$metaDesc='Your private Roost Rewards household points summary.';
$canonicalUrl=bcrf_farm_url().'/roost-rewards/balance.php';
require __DIR__.'/../includes/head.php';
include __DIR__.'/../includes/header.php';
?>
<main class="content-wrap1" style="max-width:48rem;margin:2rem auto;padding:1rem">
<h1>My Roost Rewards</h1>
<?php if (!$verified): ?>
<p>Verify your customer email address to view your Rewards household.</p>
<?php elseif ($unavailable): ?>
<p>Rewards balances are not available yet. No points have been changed.</p>
<?php elseif ($balance === null): ?>
<p>You do not have an active, verified adult Rewards household membership yet. Enrollment is not open.</p>
<?php else: ?>
<section aria-label="Household points summary">
<h2>Household points</h2>
<p><strong><?= number_format($balance['balance_points']) ?></strong> points available</p>
<p>Lifetime earned: <?= number_format($balance['lifetime_earned']) ?> points</p>
<p>Lifetime redeemed: <?= number_format($balance['lifetime_redeemed']) ?> points</p>
<p>Last qualifying activity: <?= $balance['last_qualifying_activity_at'] === null ? 'Not yet recorded' : htmlspecialchars(substr((string)$balance['last_qualifying_activity_at'],0,10),ENT_QUOTES,'UTF-8').' (UTC)' ?></p>
<p>Redemption and enrollment are not available yet.</p>
<?php if ($householdSummary !== null): ?>
<h2>Household membership</h2>
<p>Your role: <?= $householdSummary['role'] === 'manager' ? 'Household manager' : 'Verified adult' ?></p>
<p>Verified members: <?= number_format($householdSummary['verified_members']) ?></p>
<p>Pending verifications: <?= number_format($householdSummary['pending_members']) ?></p>
<p>Sending invitations and changing membership are not available yet.</p>
<?php if ($householdSummary['role'] === 'manager'): ?>
<h3>Recent household invitations</h3>
<?php if (!$invitationSummary): ?><p>No invitations recorded.</p>
<?php else: ?><ul><?php foreach ($invitationSummary as $invite): ?><li>Invitation #<?= (int)$invite['id'] ?>: <?= htmlspecialchars(ucfirst($invite['display_status']),ENT_QUOTES,'UTF-8') ?> (created <?= htmlspecialchars(substr($invite['created_at'],0,10),ENT_QUOTES,'UTF-8') ?> UTC)</li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<h2>Planned redemption tiers</h2>
<p>Tier benefits are still being finalized. No redemptions can be submitted yet.</p>
<ul>
<?php foreach ($redemptionTiers as $tier): ?>
<li><strong><?= number_format($tier['points']) ?> points:</strong> <?= $tier['available'] ? 'Points threshold met' : number_format($tier['points_needed']).' more points needed' ?></li>
<?php endforeach; ?>
</ul>
<h2>Recent points activity</h2>
<?php if (!$history): ?><p>No points activity recorded yet.</p>
<?php else: ?><table><caption>Most recent household points entries</caption><thead><tr><th scope="col">Date (UTC)</th><th scope="col">Activity</th><th scope="col">Points</th></tr></thead><tbody>
<?php foreach ($history as $entry): ?><tr><td><?= htmlspecialchars(substr($entry['created_at'],0,10),ENT_QUOTES,'UTF-8') ?></td><td><?= htmlspecialchars(ucwords(str_replace('_',' ',$entry['entry_type'])),ENT_QUOTES,'UTF-8') ?></td><td><?= $entry['points_delta'] > 0 ? '+' : '' ?><?= number_format($entry['points_delta']) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
</section>
<?php endif; ?>
<p><a href="/roost-rewards/">Program information</a></p>
</main>
<?php include __DIR__.'/../includes/footer.php'; ?>
