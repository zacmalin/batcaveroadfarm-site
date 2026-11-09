<?php
// Farm Rewards internal ledger service. NOT wired to checkout or public routes.
// All mutations must be called with an explicit verified household and approved policy.
// InnoDB row locks serialize balance changes; caller supplies stable event idempotency keys.

if (!class_exists('BcrfRewardError')) {
    class BcrfRewardError extends RuntimeException {}
}

/**
 * Post a signed ledger delta and update the pooled balance in ONE transaction.
 * Duplicate event keys return the original entry without posting again.
 * A reversal may make the balance negative; redemptions may never do so.
 * Caller is responsible for trusted source verification, eligibility, and permission checks.
 *
 * @return array{id:int,balance:int,replayed:bool}
 */
function bcrf_reward_post(PDO $pdo, array $event): array {
    $required = ['household_id','policy_id','entry_type','points_delta','source_type','source_id','idempotency_key'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $event) || $event[$key] === '' || $event[$key] === null) {
            throw new BcrfRewardError('Missing reward event field: ' . $key);
        }
    }
    $household = filter_var($event['household_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $policy = filter_var($event['policy_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $delta = filter_var($event['points_delta'], FILTER_VALIDATE_INT);
    $type = (string)$event['entry_type'];
    if (!$household || !$policy || $delta === false || $delta === 0) throw new BcrfRewardError('Invalid reward event values.');
    if (!in_array($type, ['earn_spend','earn_visit','redeem','refund_reversal','expire','adjustment'], true)) {
        throw new BcrfRewardError('Unknown reward event type.');
    }
    if (in_array($type, ['earn_spend','earn_visit'], true) && $delta <= 0) throw new BcrfRewardError('Earnings must be positive.');
    // Approved visit policy: a verified visit earns exactly 25 points.
    // This does not establish visit provenance; trusted check-in validation remains a release gate.
    if ($type === 'earn_visit' && $delta !== 25) throw new BcrfRewardError('Visit award must equal 25 points.');
    if (in_array($type, ['redeem','refund_reversal','expire'], true) && $delta >= 0) throw new BcrfRewardError('Deductions must be negative.');
    foreach (['source_type'=>40,'source_id'=>100,'idempotency_key'=>160] as $key=>$max) {
        if (!is_string($event[$key]) || strlen($event[$key]) > $max || trim($event[$key]) === '') {
            throw new BcrfRewardError('Invalid reward source or event key.');
        }
    }
    // Never let a generic caller disguise a visit as a purchase (or vice versa).
    if (($type === 'earn_spend' && $event['source_type'] !== 'sale') ||
        ($type === 'earn_visit' && $event['source_type'] !== 'visit')) {
        throw new BcrfRewardError('Earning source type does not match reward event.');
    }
    // Purchase source IDs must have positive integer syntax; existence and ownership are checked inside the transaction.
    if ($type === 'earn_spend' &&
        (!ctype_digit($event['source_id']) ||
         !filter_var($event['source_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]))) {
        throw new BcrfRewardError('Purchase earning requires a positive sale ID.');
    }
    $customer = $event['customer_id'] ?? null;
    if ($customer !== null && !filter_var($customer, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) {
        throw new BcrfRewardError('Invalid customer.');
    }
    if ($type === 'redeem' && $customer === null) throw new BcrfRewardError('Redeemer required.');
    if ($delta === PHP_INT_MIN) throw new BcrfRewardError('Points outside safe range.');
    if ($type === 'refund_reversal' && empty($event['related_entry_id'])) throw new BcrfRewardError('Refund reversal requires original earning entry.');
    if ($pdo->inTransaction()) throw new BcrfRewardError('Reward posting requires its own transaction.');

    $pdo->beginTransaction();
    try {
        // This lock serializes every mutation for one household.
        $lock = $pdo->prepare("SELECT a.balance_points, h.status FROM reward_accounts a JOIN reward_households h ON h.id = a.household_id WHERE a.household_id = ? FOR UPDATE");
        $lock->execute([$household]);
        $account = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$account || $account['status'] !== 'active') throw new BcrfRewardError('Reward account unavailable.');

        $existing = $pdo->prepare("SELECT id, household_id FROM reward_ledger WHERE idempotency_key = ?");
        $existing->execute([$event['idempotency_key']]);
        if ($old = $existing->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$old['household_id'] !== (int)$household) throw new BcrfRewardError('Event key belongs to another household.');
            $check = $pdo->prepare("SELECT policy_id, customer_id, entry_type, points_delta, source_type, source_id, related_entry_id FROM reward_ledger WHERE id = ?");
            $check->execute([$old['id']]);
            $saved = $check->fetch(PDO::FETCH_ASSOC);
            foreach (['policy_id'=>$policy,'customer_id'=>$customer,'entry_type'=>$type,'points_delta'=>$delta,'source_type'=>$event['source_type'],'source_id'=>$event['source_id'],'related_entry_id'=>$event['related_entry_id'] ?? null] as $field=>$expected) {
                if ((string)($saved[$field] ?? '') !== (string)($expected ?? '')) throw new BcrfRewardError('Idempotency key reused with different event.');
            }
            $pdo->commit();
            return ['id'=>(int)$old['id'],'balance'=>(int)$account['balance_points'],'replayed'=>true];
        }

        // A different event key must not award the same purchase or visit twice.
        // The household account lock serializes this check within a household.
        if (in_array($type, ['earn_spend','earn_visit'], true)) {
            $sourceCheck = $pdo->prepare("SELECT id FROM reward_ledger WHERE entry_type = ? AND source_type = ? AND source_id = ? LIMIT 1");
            $sourceCheck->execute([$type, $event['source_type'], $event['source_id']]);
            if ($sourceCheck->fetchColumn()) throw new BcrfRewardError('Reward source already awarded.');
        }
        $active = $pdo->prepare("SELECT 1 FROM reward_policies WHERE id = ? AND status = 'active' AND (effective_at IS NULL OR effective_at <= UTC_TIMESTAMP(3)) LIMIT 1");
        $active->execute([$policy]);
        if (!$active->fetchColumn()) {
            // Historical refunds must reverse under the immutable original
            // earning policy, even after that policy is retired. This exception
            // is never available to new earnings, redemptions, or adjustments.
            if ($type === 'earn_spend') {
                // A delayed sale may earn under a retired policy only when
                // the immutable, committed POS outbox event pinned that exact
                // policy. Legacy null snapshots fail closed.
                // Lock only the queued event, not the policy row: policy
                // maintenance must not invert account/outbox lock ordering.
                $pinned = $pdo->prepare("SELECT policy_id,created_at FROM reward_pos_outbox
                    WHERE event_type='sale.completed' AND source_id=? FOR UPDATE");
                $pinned->execute([(int)$event['source_id']]);
                $snapshot=$pinned->fetch(PDO::FETCH_ASSOC);
                if (!$snapshot || $snapshot['policy_id']===null ||
                    (int)$snapshot['policy_id']!==$policy)
                    throw new BcrfRewardError('Retired sale policy requires matching immutable outbox snapshot.');
                $historicalPolicy=$pdo->prepare("SELECT 1 FROM reward_policies
                    WHERE id=? AND created_at<=?
                      AND (effective_at IS NULL OR effective_at<=?) LIMIT 1");
                $historicalPolicy->execute([$policy,$snapshot['created_at'],$snapshot['created_at']]);
                if (!$historicalPolicy->fetchColumn())
                    throw new BcrfRewardError('Pinned sale policy was not valid when queued.');
            } elseif ($type === 'refund_reversal' && !empty($event['related_entry_id'])) {
                $historical = $pdo->prepare("SELECT 1 FROM reward_ledger l
                JOIN reward_policies p ON p.id=l.policy_id
                WHERE l.id=? AND l.policy_id=? AND l.household_id=?
                  AND l.entry_type='earn_spend' AND l.source_type='sale'
                LIMIT 1");
            $historical->execute([$event['related_entry_id'],$policy,$household]);
            if (!$historical->fetchColumn())
                throw new BcrfRewardError('Refund policy must match the original sale earning.');
            } else {
                throw new BcrfRewardError('Reward policy is not active.');
            }
        }

        $balance = (int)$account['balance_points'];
        if (($delta > 0 && $balance > PHP_INT_MAX - $delta) || ($delta < 0 && $balance < PHP_INT_MIN - $delta)) throw new BcrfRewardError('Reward balance overflow.');
        // Revalidate earning membership while holding the account lock.
        // FOR UPDATE prevents a concurrent membership revocation from
        // completing between this check and the ledger commit.
        if (in_array($type, ['earn_spend','earn_visit'], true)) {
            if ($customer === null) throw new BcrfRewardError('Verified earning customer required.');
            $earningMember = $pdo->prepare("SELECT id FROM reward_household_members WHERE household_id = ? AND customer_id = ? AND revoked_at IS NULL AND verification_status = 'verified' FOR UPDATE");
            $earningMember->execute([$household, $customer]);
            if (!$earningMember->fetchColumn()) throw new BcrfRewardError('Earning customer has no verified active membership.');
        }
        // A recorded sale ID alone is not sufficient: confirm the sale exists,
        // remains completed, and is still assigned to the earning customer.
        // Locking the row prevents concurrent reassignment until commit.
        if ($type === 'earn_spend') {
            $saleCheck = $pdo->prepare("SELECT customer_id, status FROM sales WHERE id = ? FOR UPDATE");
            $saleCheck->execute([(int)$event['source_id']]);
            $sourceSale = $saleCheck->fetch(PDO::FETCH_ASSOC);
            if (!$sourceSale || !in_array($sourceSale['status'], ['completed','partially_refunded'], true) ||
                (int)$sourceSale['customer_id'] !== (int)$customer) {
                throw new BcrfRewardError('Earning sale is not completed or assigned to this customer.');
            }
        }
        if ($type === 'earn_spend') {
            // Reject an award routed to a different household, or an ambiguous
            // household assignment, even if the supplied customer is a member.
            $householdCheck = $pdo->prepare("SELECT m.household_id
                FROM reward_household_members m
                JOIN reward_households h ON h.id = m.household_id
                WHERE m.customer_id = ? AND m.revoked_at IS NULL
                  AND m.verification_status = 'verified' AND h.status = 'active'
                FOR UPDATE");
            $householdCheck->execute([$customer]);
            $assigned = $householdCheck->fetchAll(PDO::FETCH_COLUMN);
            if (count($assigned) !== 1 || (int)$assigned[0] !== (int)$household) {
                throw new BcrfRewardError('Purchase customer has no unique matching verified household.');
            }
        }
        if ($type === 'earn_visit') {
            // The source is a verified attendance row, not a user-provided date,
            // pass scan, purchaser, or unchecked group headcount.
            if (!ctype_digit($event['source_id']) ||
                !filter_var($event['source_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) {
                throw new BcrfRewardError('Visit award requires a verified attendance ID.');
            }
            $visitCheck = $pdo->prepare("SELECT a.customer_id, a.business_date, c.business_date AS checkin_date
                FROM reward_verified_attendance a
                JOIN check_ins c ON c.id = a.check_in_id
                WHERE a.id = ? FOR UPDATE");
            $visitCheck->execute([(int)$event['source_id']]);
            $visit = $visitCheck->fetch(PDO::FETCH_ASSOC);
            if (!$visit || (int)$visit['customer_id'] !== (int)$customer ||
                $visit['business_date'] !== $visit['checkin_date']) {
                throw new BcrfRewardError('Visit has no matching verified attendee and operating date.');
            }
        }
        if ($type === 'earn_spend') {
            // Recalculate from sale lines and payment records under the same
            // transaction; a caller cannot choose an arbitrary points award.
            require_once __DIR__ . '/rewards-pos-preview.php';
            $eligibleCents = bcrf_rewards_sale_eligible_cents($pdo, (int)$event['source_id']);
            $expectedPoints = bcrf_rewards_points_for_eligible_cents($eligibleCents);
            if ($expectedPoints <= 0 || $delta !== $expectedPoints) {
                throw new BcrfRewardError('Purchase points do not match verified eligible sale value.');
            }
        }
        if ($type === 'redeem') {
            // Recheck permission inside the same transaction, after balance lock.
            $member = $pdo->prepare("SELECT m.id FROM reward_household_members m JOIN customers c ON c.id=m.customer_id WHERE m.household_id = ? AND m.customer_id = ? AND m.revoked_at IS NULL AND m.verification_status = 'verified' AND m.role IN ('manager','adult') AND m.can_redeem = 1 AND c.account_status='active' AND c.email_verified_at IS NOT NULL FOR UPDATE");
            $member->execute([$household, $customer]);
            if (!$member->fetchColumn()) throw new BcrfRewardError('Not authorized to redeem household rewards.');
            if ($balance <= 0 || $balance < -$delta) throw new BcrfRewardError('Insufficient reward points.');
        }
        if ($type === 'refund_reversal') {
            if ($event['source_type'] !== 'refund' ||
                !ctype_digit($event['source_id']) ||
                !filter_var($event['source_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) {
                throw new BcrfRewardError('Refund reversal requires a positive refund ID.');
            }
            // A single refund may not be reversed twice under different event keys.
            $duplicateRefund = $pdo->prepare("SELECT id FROM reward_ledger
                WHERE entry_type = 'refund_reversal' AND source_type = 'refund'
                  AND source_id = ? LIMIT 1");
            $duplicateRefund->execute([$event['source_id']]);
            if ($duplicateRefund->fetchColumn()) {
                throw new BcrfRewardError('Refund already reversed in rewards ledger.');
            }
            $refundCheck = $pdo->prepare("SELECT sale_id FROM refunds WHERE id = ? FOR UPDATE");
            $refundCheck->execute([(int)$event['source_id']]);
            $refundSaleId = $refundCheck->fetchColumn();
            if ($refundSaleId === false) throw new BcrfRewardError('Referenced refund does not exist.');
            $sourceCheck = $pdo->prepare("SELECT source_type, source_id FROM reward_ledger WHERE id = ?");
            $sourceCheck->execute([$event['related_entry_id']]);
            $originalSource = $sourceCheck->fetch(PDO::FETCH_ASSOC);
            if (!$originalSource || $originalSource['source_type'] !== 'sale' ||
                (string)$originalSource['source_id'] !== (string)$refundSaleId) {
                throw new BcrfRewardError('Refund does not belong to original rewarded sale.');
            }
        }
        if ($type === 'refund_reversal') {
            $origin = $pdo->prepare("SELECT household_id, entry_type, points_delta FROM reward_ledger WHERE id = ? FOR UPDATE");
            $origin->execute([$event['related_entry_id']]);
            $earned = $origin->fetch(PDO::FETCH_ASSOC);
            if (!$earned || (int)$earned['household_id'] !== (int)$household || $earned['entry_type'] !== 'earn_spend') throw new BcrfRewardError('Invalid earning to reverse.');
            // Reconcile the requested debit with the actual refunded sale lines
            // while the account and refund rows are locked. Never trust a
            // caller-supplied reversal amount.
            require_once __DIR__ . '/roost-refund-preview.php';
            $refundPreview = bcrf_roost_refund_reversal_preview($pdo, (int)$refundSaleId);
            $prior = $pdo->prepare("SELECT COALESCE(SUM(points_reversed),0) FROM reward_refund_processing WHERE original_earning_entry_id = ?");
            $prior->execute([$event['related_entry_id']]);
            $alreadyReversed = (int)$prior->fetchColumn();
            $due = bcrf_roost_refund_points_for_id(
                $refundPreview,
                (int)$event['source_id'],
                $alreadyReversed
            );
            if ($due <= 0 || -$delta !== $due) {
                throw new BcrfRewardError('Refund reversal does not match verified incremental points.');
            }
        }
        if ($type === 'refund_reversal') {
            // The refund receipt is unique even when a different event key is supplied.
            $receiptCheck = $pdo->prepare("SELECT refund_id FROM reward_refund_processing WHERE refund_id = ?");
            $receiptCheck->execute([(int)$event['source_id']]);
            if ($receiptCheck->fetchColumn()) throw new BcrfRewardError('Refund already processed.');
        }
        $newBalance = $balance + $delta;
        $metadata = isset($event['metadata']) ? json_encode($event['metadata'], JSON_THROW_ON_ERROR) : null;
        $ins = $pdo->prepare("INSERT INTO reward_ledger (household_id, customer_id, policy_id, entry_type, points_delta, source_type, source_id, idempotency_key, related_entry_id, metadata_json, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), ?)");
        $ins->execute([$household, $customer, $policy, $type, $delta, $event['source_type'], $event['source_id'], $event['idempotency_key'], $event['related_entry_id'] ?? null, $metadata, $event['created_by'] ?? null]);
        $id = (int)$pdo->lastInsertId();
        if ($type === 'refund_reversal') {
            $receipt = $pdo->prepare("INSERT INTO reward_refund_processing
                (refund_id, household_id, original_earning_entry_id, points_reversed, reversal_entry_id, processed_at)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3))");
            $receipt->execute([(int)$event['source_id'], (int)$household,
                (int)$event['related_entry_id'], -$delta, $id]);
        }
        $upd = $pdo->prepare("UPDATE reward_accounts SET balance_points = ?, lifetime_earned = lifetime_earned + ?, lifetime_redeemed = lifetime_redeemed + ?, updated_at = UTC_TIMESTAMP(3) WHERE household_id = ?");
        $upd->execute([$newBalance, in_array($type,['earn_spend','earn_visit'],true) ? $delta : 0, $type === 'redeem' ? -$delta : 0, $household]);
        // Only confirmed earning events reset household inactivity. A replay
        // exits earlier, so it cannot refresh activity a second time.
        if (in_array($type, ['earn_spend','earn_visit'], true)) {
            $activity = $pdo->prepare("UPDATE reward_households
                SET last_qualifying_activity_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3)
                WHERE id = ?");
            $activity->execute([$household]);
        }
        $pdo->commit();
        return ['id'=>$id,'balance'=>$newBalance,'replayed'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // A simultaneous first insert of the same key may hit the unique constraint.
        // Let the caller retry the whole operation; it will then see the existing row.
        throw $e;
    }
}
