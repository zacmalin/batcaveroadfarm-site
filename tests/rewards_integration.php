<?php
// Run only against a disposable MySQL 8 database:
// BCRF_REWARDS_TEST_DSN='mysql:host=127.0.0.1;dbname=...' BCRF_REWARDS_TEST_USER=... php tests/rewards_integration.php
$dsn = getenv('BCRF_REWARDS_TEST_DSN');
if (!$dsn) { fwrite(STDERR, "Set BCRF_REWARDS_TEST_DSN for a disposable database.\n"); exit(2); }
$pdo = new PDO($dsn, getenv('BCRF_REWARDS_TEST_USER') ?: '', getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE customers (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$pdo->exec("INSERT INTO customers VALUES (1),(2)");
$pdo->exec("ALTER TABLE customers ADD COLUMN email VARCHAR(100), ADD COLUMN email_verified_at DATETIME NULL, ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'active'");
$pdo->exec("UPDATE customers SET email=CONCAT('customer',id,'@example.test'), email_verified_at=UTC_TIMESTAMP()");

$pdo->exec("CREATE TABLE sales (id BIGINT UNSIGNED PRIMARY KEY, customer_id INT NULL, status VARCHAR(24) NOT NULL, subtotal_cents BIGINT NOT NULL, discount_cents BIGINT NOT NULL DEFAULT 0, total_cents BIGINT NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO sales VALUES (100,1,'completed',4000,0,4000),(101,1,'completed',5500,0,5500),(102,2,'completed',500,0,500),(103,1,'voided',500,0,500)");
$pdo->exec("CREATE TABLE sale_lines (id BIGINT AUTO_INCREMENT PRIMARY KEY, sale_id BIGINT, product_type VARCHAR(30), gross_cents BIGINT, discount_cents BIGINT, refunded_net_cents BIGINT) ENGINE=InnoDB");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents) VALUES (100,'retail',4000,0,0),(101,'retail',5500,0,0),(102,'retail',500,0,0),(103,'retail',500,0,0)");
$pdo->exec("CREATE TABLE payments (sale_id BIGINT,tender VARCHAR(30),amount_cents BIGINT,status VARCHAR(20),reconciled_at DATETIME NULL,reconcile_flag VARCHAR(30) NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO payments (sale_id,tender,amount_cents,status) VALUES (100,'cash',4000,'completed'),(101,'cash',5500,'completed'),(102,'cash',500,'completed'),(103,'cash',500,'completed')");
$pdo->exec("CREATE TABLE refunds (id BIGINT UNSIGNED PRIMARY KEY, sale_id BIGINT UNSIGNED NOT NULL, net_cents BIGINT NOT NULL, total_cents BIGINT NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO refunds VALUES (1,100,4000,4000),(2,101,0,0)");
$pdo->exec("CREATE TABLE refund_lines (refund_id BIGINT, sale_line_id BIGINT, net_cents BIGINT) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE refund_payments (refund_id BIGINT, tender VARCHAR(20), amount_cents BIGINT, reference VARCHAR(80)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO refund_payments VALUES (1,'cash',4000,NULL)");
$schema = require __DIR__ . '/../admin/migrations/0016_farm_rewards_foundation.php';
$schema($pdo);
$schema($pdo); // rerunnable
$refundProcessingSchema = require __DIR__ . '/../admin/migrations/0018_roost_refund_processing.php';
$refundProcessingSchema($pdo);
$refundProcessingSchema($pdo); // rerunnable
require_once __DIR__ . '/../includes/roost-refund-processing.php';

$pdo->exec("CREATE TABLE check_ins (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, business_date DATE NOT NULL, party_size INT NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO check_ins VALUES (1,'2026-11-08',1),(2,'2026-11-08',2),(3,'2026-11-09',1),(4,'2026-11-10',1),(5,'2026-11-11',1)");
$preflightEnv = array_merge(getenv(), [
    'BCRF_REWARDS_PREFLIGHT_DSN'=>$dsn,
    'BCRF_REWARDS_PREFLIGHT_USER'=>getenv('BCRF_REWARDS_TEST_USER') ?: '',
    'BCRF_REWARDS_PREFLIGHT_PASSWORD'=>getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '',
    'BCRF_REWARDS_PREFLIGHT_ALLOW_EXISTING'=>'1',
]);
$preflightPipes = [];
$preflight = proc_open([PHP_BINARY, __DIR__.'/rewards_production_preflight.php'],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $preflightPipes, null, $preflightEnv);
if (!is_resource($preflight)) throw new RuntimeException('Unable to launch read-only schema preflight.');
fclose($preflightPipes[0]);
$preflightOutput = stream_get_contents($preflightPipes[1]);
$preflightError = stream_get_contents($preflightPipes[2]);
fclose($preflightPipes[1]);
fclose($preflightPipes[2]);
if (proc_close($preflight)!==0 || !str_contains($preflightOutput,'Preflight structural checks passed')) {
    throw new RuntimeException('Read-only schema preflight failed: '.$preflightOutput.' '.$preflightError);
}

$attendanceSchema = require __DIR__ . '/../admin/migrations/0017_roost_rewards_attendance.php';
$attendanceSchema($pdo);
$attendanceSchema($pdo); // rerunnable
$pdo->exec("INSERT INTO reward_verified_attendance (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at) VALUES (1,1,'2026-11-08',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
try {
    $pdo->exec("INSERT INTO reward_verified_attendance (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at) VALUES (2,1,'2026-11-08',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    throw new RuntimeException('Duplicate attendee on same operating day accepted');
} catch (PDOException $e) {
    if ($e->getCode() !== '23000') throw $e;
}
$pdo->exec("INSERT INTO reward_verified_attendance (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at) VALUES (2,2,'2026-11-08',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_verified_attendance (check_in_id,customer_id,business_date,verified_by_staff_id,verified_at,created_at) VALUES (3,1,'2026-11-09',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");

$pdo->exec("INSERT INTO reward_households (id, status, created_at) VALUES (1,'active',UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_accounts (household_id, updated_at) VALUES (1,UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_household_members (household_id,customer_id,role,verification_status,can_redeem,joined_at) VALUES (1,1,'manager','verified',1,UTC_TIMESTAMP(3)),(1,2,'adult','verified',1,UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_policies (id,version,config_json,status,created_at) VALUES (1,'test-1','{}','active',UTC_TIMESTAMP(3))");
// Reserve fixture household IDs 2 onward for existing ledger tests.
$pdo->exec('ALTER TABLE reward_households AUTO_INCREMENT=100');
(require __DIR__ . '/../admin/migrations/0022_roost_household_invitations.php')($pdo);
(require __DIR__ . '/../admin/migrations/0024_roost_invitation_delivery_outbox.php')($pdo);
require_once __DIR__ . '/../includes/roost-household-invitations.php';
(require __DIR__ . '/../admin/migrations/0023_roost_verification_consent.php')($pdo);
require_once __DIR__ . '/../includes/roost-verification-consent.php';
require_once __DIR__ . '/../includes/roost-household-enrollment.php';
require_once __DIR__ . '/../includes/roost-staff-adult-verification.php';
require_once __DIR__ . '/../includes/roost-staff-verification-access.php';
foreach ([['administrator',true,true],['administrator',false,false],['Staff',true,false],
    ['Shopkeep',true,false],['moderator',true,false],['user',true,false],['',true,false]] as $case) {
    if (bcrf_roost_staff_verification_access($case[0],$case[1])!==$case[2])
        throw new RuntimeException('Adult verification staff authorization mismatch.');
}

try {
    bcrf_roost_record_staff_adult_verification($pdo,40,7,'self_attested','bad');
    throw new RuntimeException('Unapproved adult verification method accepted.');
} catch (InvalidArgumentException $expected) {}
try {
    bcrf_roost_record_staff_adult_verification($pdo,40,7,'staff_review','unverified-email');
    throw new RuntimeException('Unverified customer email accepted for adult verification.');
} catch (DomainException $expected) {}
$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (41,'staff-reviewed@example.test',UTC_TIMESTAMP(),'active')");
$staffVerificationId=bcrf_roost_record_staff_adult_verification($pdo,41,7,'staff_review','test-staff-review');
if ($staffVerificationId<=0) throw new RuntimeException('Staff verification evidence not recorded.');
try {
    bcrf_roost_record_staff_adult_verification($pdo,41,7,'staff_review','duplicate');
    throw new RuntimeException('Duplicate active adult verification accepted.');
} catch (DomainException $expected) {}

require_once __DIR__ . '/../includes/roost-redemption-tier-preview.php';
foreach ([[-5,[false,false,false,false]],[0,[false,false,false,false]],[249,[false,false,false,false]],[250,[true,false,false,false]],[500,[true,true,false,false]],[2000,[true,true,true,true]]] as $case) {
    $tiers=bcrf_roost_redemption_tier_preview($case[0]);
    if (array_column($tiers,'available')!==$case[1]) throw new RuntimeException('Redemption tier preview mismatch.');
    foreach ($tiers as $tier) {
        if ($tier['points_needed']!==max(0,$tier['points']-$case[0]))
            throw new RuntimeException('Redemption tier points-needed mismatch.');
    }
}

$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (30,'new-adult@example.test',UTC_TIMESTAMP(),'active'),(40,'unverified@example.test',NULL,'active')");
$pdo->exec("INSERT INTO reward_adult_verifications
    (customer_id,status,method,evidence_reference,verified_by_staff_id,verified_at,created_at)
    VALUES (30,'verified','staff_review','test-enroll',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
           (40,'verified','staff_review','test-unverified-email',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_consent_events
    (customer_id,purpose,policy_version,status,source,created_at)
    VALUES (30,'rewards_enrollment','test-1','granted','authenticated_account',UTC_TIMESTAMP(3)),
           (40,'rewards_enrollment','test-1','granted','authenticated_account',UTC_TIMESTAMP(3))");
foreach ([[30,false,true],[30,true,false],[40,true,true]] as $attempt) {
    try {
        bcrf_roost_enroll_verified_adult($pdo,$attempt[0],$attempt[1],$attempt[2]);
        throw new RuntimeException('Invalid enrollment unexpectedly succeeded.');
    } catch (InvalidArgumentException|DomainException $expected) {}
}
$enrolled=bcrf_roost_enroll_verified_adult($pdo,30,true,true);
if ($enrolled['balance_points']!==0 || $enrolled['household_id']<=1)
    throw new RuntimeException('Verified adult enrollment did not create a zero-balance household.');
$check=$pdo->prepare("SELECT m.role,m.verification_status,m.can_redeem,a.balance_points
    FROM reward_household_members m JOIN reward_accounts a ON a.household_id=m.household_id
    WHERE m.customer_id=?");
$check->execute([30]);
$enrollmentRow=$check->fetch(PDO::FETCH_ASSOC);
if (!$enrollmentRow || $enrollmentRow['role']!=='manager' || $enrollmentRow['verification_status']!=='verified'
    || (int)$enrollmentRow['can_redeem']!==1 || (int)$enrollmentRow['balance_points']!==0)
    throw new RuntimeException('Enrollment record mismatch.');
try {
    bcrf_roost_enroll_verified_adult($pdo,30,true,true);
    throw new RuntimeException('Duplicate enrollment unexpectedly succeeded.');
} catch (DomainException $expected) {}
// Encrypted delivery intent must be committed with invitation creation.
$deliveryKey=random_bytes(32);
$queuedInvitation=bcrf_roost_create_household_invitation($pdo,30,'queued.invitee@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
if ($queuedInvitation['token']!==null || $queuedInvitation['delivery_queued']!==true)
    throw new RuntimeException('Queued invitation exposed a bearer token.');
$queuedRow=$pdo->prepare("SELECT encryption_key_id,payload_version,payload_nonce,payload_ciphertext,payload_tag
    FROM reward_invitation_delivery_outbox WHERE invitation_id=?");
$queuedRow->execute([$queuedInvitation['invitation_id']]);
$queuedPayload=$queuedRow->fetch(PDO::FETCH_ASSOC);
if (!$queuedPayload) throw new RuntimeException('Invitation delivery intent was not committed.');
require_once __DIR__.'/../includes/roost-invitation-delivery-crypto.php';
$decrypted=json_decode(bcrf_roost_invite_decrypt([
    'version'=>(int)$queuedPayload['payload_version'],
    'key_id'=>$queuedPayload['encryption_key_id'],'nonce'=>$queuedPayload['payload_nonce'],
    'ciphertext'=>$queuedPayload['payload_ciphertext'],'tag'=>$queuedPayload['payload_tag']
],$deliveryKey,'integration-v1'),true,512,JSON_THROW_ON_ERROR);
if ($decrypted['recipient']!=='queued.invitee@example.test' ||
    !preg_match('/^[a-f0-9]{64}$/D',$decrypted['token']))
    throw new RuntimeException('Queued invitation delivery payload was invalid.');
require_once __DIR__.'/../includes/roost-invitation-delivery-claim.php';
$claimedDelivery=bcrf_roost_claim_invitation_delivery($pdo);
if (!$claimedDelivery || !preg_match('/^[a-f0-9]{64}$/D',(string)$claimedDelivery['claim_token']))
    throw new RuntimeException('Invitation delivery was not claimed.');
require_once __DIR__.'/../includes/roost-invitation-delivery-eligibility.php';
if (!bcrf_roost_invitation_delivery_eligible($pdo,$claimedDelivery['id'],$claimedDelivery['claim_token']))
    throw new RuntimeException('Valid claimed invitation was not delivery-eligible.');
if (bcrf_roost_invitation_delivery_eligible($pdo,$claimedDelivery['id'],str_repeat('0',64)))
    throw new RuntimeException('Incorrect claim token bypassed delivery authorization.');
if (!bcrf_roost_invitation_delivery_payload_matches($pdo,$claimedDelivery['id'],
    $claimedDelivery['claim_token'],$decrypted['recipient'],$decrypted['token']))
    throw new RuntimeException('Valid decrypted invitation delivery payload did not match.');
if (bcrf_roost_invitation_delivery_payload_matches($pdo,$claimedDelivery['id'],
    $claimedDelivery['claim_token'],'wrong@example.test',$decrypted['token']))
    throw new RuntimeException('Mismatched invitation recipient was accepted.');
if (bcrf_roost_invitation_delivery_payload_matches($pdo,$claimedDelivery['id'],
    $claimedDelivery['claim_token'],$decrypted['recipient'],str_repeat('0',64)))
    throw new RuntimeException('Mismatched invitation bearer code was accepted.');
$secondClaim=bcrf_roost_claim_invitation_delivery($pdo);
if ($secondClaim!==null) throw new RuntimeException('Processing delivery was claimed twice.');
if (bcrf_roost_complete_invitation_delivery($pdo,$claimedDelivery['id'],str_repeat('0',64),'sent'))
    throw new RuntimeException('Incorrect claim token completed delivery.');
if (!bcrf_roost_complete_invitation_delivery($pdo,$claimedDelivery['id'],$claimedDelivery['claim_token'],'suppressed'))
    throw new RuntimeException('Claimed invitation delivery could not be completed.');
if (bcrf_roost_complete_invitation_delivery($pdo,$claimedDelivery['id'],$claimedDelivery['claim_token'],'sent'))
    throw new RuntimeException('Completed invitation delivery was completed twice.');

$staleInvite=bcrf_roost_create_household_invitation($pdo,30,'stale.delivery@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$staleClaim=bcrf_roost_claim_invitation_delivery($pdo);
if (!$staleClaim) throw new RuntimeException('Stale delivery fixture was not claimed.');
$expireInvite=$pdo->prepare("UPDATE reward_household_invitations
    SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?");
$expireInvite->execute([$staleInvite['invitation_id']]);
if (bcrf_roost_invitation_delivery_eligible($pdo,$staleClaim['id'],$staleClaim['claim_token']))
    throw new RuntimeException('Expired invitation remained delivery-eligible.');
if (!bcrf_roost_suppress_ineligible_invitation_delivery($pdo,$staleClaim['id'],$staleClaim['claim_token']))
    throw new RuntimeException('Expired claimed invitation was not suppressed.');
if (bcrf_roost_complete_invitation_delivery($pdo,$staleClaim['id'],$staleClaim['claim_token'],'sent'))
    throw new RuntimeException('Suppressed invitation was incorrectly marked sent.');

// Restore fixture expiry so later batch-expiration assertions remain isolated.
$restoreExpiry=$pdo->prepare("UPDATE reward_household_invitations
    SET expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 7 DAY) WHERE id=?");
$restoreExpiry->execute([$staleInvite['invitation_id']]);
$staleMark=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
    SET status='processing',claimed_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 20 MINUTE),
        claim_token=? WHERE id=?");
$staleMark->execute([str_repeat('a',64),$staleClaim['id']]);
if (bcrf_roost_recover_invitation_delivery($pdo)!==1)
    throw new RuntimeException('Stale invitation delivery was not recovered.');
if (bcrf_roost_complete_invitation_delivery($pdo,$staleClaim['id'],$staleClaim['claim_token'],'sent'))
    throw new RuntimeException('Recovered stale claim token remained valid.');
$retryMark=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
    SET status='processing',attempts=3,claimed_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 20 MINUTE),
        claim_token=? WHERE id=?");
$retryMark->execute([str_repeat('b',64),$staleClaim['id']]);
if (bcrf_roost_recover_invitation_delivery($pdo)!==1)
    throw new RuntimeException('Exhausted invitation delivery was not recovered.');
$failedState=$pdo->prepare("SELECT status,claim_token FROM reward_invitation_delivery_outbox WHERE id=?");
$failedState->execute([$staleClaim['id']]);
$failedRow=$failedState->fetch(PDO::FETCH_ASSOC);
if ($failedRow['status']!=='failed' || $failedRow['claim_token']!==null)
    throw new RuntimeException('Exhausted delivery did not fail closed.');

$failureInvite=bcrf_roost_create_household_invitation($pdo,30,'transient.delivery@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$failureClaim=bcrf_roost_claim_invitation_delivery($pdo);
if (!$failureClaim) throw new RuntimeException('Transient delivery fixture was not claimed.');
if (!bcrf_roost_fail_invitation_delivery($pdo,$failureClaim['id'],
    $failureClaim['claim_token'],'smtp_timeout',true))
    throw new RuntimeException('Transient delivery failure not recorded.');
if (bcrf_roost_complete_invitation_delivery($pdo,$failureClaim['id'],
    $failureClaim['claim_token'],'sent'))
    throw new RuntimeException('Failed delivery retained old claim token.');
$failureState=$pdo->prepare("SELECT status,attempts,last_error_code FROM reward_invitation_delivery_outbox WHERE id=?");
$failureState->execute([$failureClaim['id']]);
$failureRow=$failureState->fetch(PDO::FETCH_ASSOC);
if ($failureRow['status']!=='pending' || (int)$failureRow['attempts']!==1 ||
    $failureRow['last_error_code']!=='smtp_timeout')
    throw new RuntimeException('Transient delivery was not queued for retry.');
$pdo->prepare("UPDATE reward_invitation_delivery_outbox SET available_at=UTC_TIMESTAMP(3) WHERE id=?")
    ->execute([$failureClaim['id']]);
$secondFailureClaim=bcrf_roost_claim_invitation_delivery($pdo);
if (!$secondFailureClaim || $secondFailureClaim['id']!==$failureClaim['id'])
    throw new RuntimeException('Transient delivery retry was not claimed.');
if (!bcrf_roost_fail_invitation_delivery($pdo,$secondFailureClaim['id'],
    $secondFailureClaim['claim_token'],'smtp_rejected',false))
    throw new RuntimeException('Permanent delivery failure not recorded.');
$failureState->execute([$failureClaim['id']]);
if ($failureState->fetch(PDO::FETCH_ASSOC)['status']!=='failed')
    throw new RuntimeException('Permanent delivery failure was retried.');
require_once __DIR__.'/../includes/roost-invitation-delivery-smtp.php';
try {
    bcrf_roost_invitation_smtp_transport(['smtp_enabled'=>false]);
    throw new RuntimeException('Disabled SMTP adapter was accepted.');
} catch (LogicException $expected) {
    // Disabled by default; never load or invoke the live sender.
}
require_once __DIR__.'/../includes/roost-invitation-delivery-worker.php';
$transportCalled=false;
if (bcrf_roost_run_invitation_delivery_once($pdo,['enabled'=>false],
    function () use (&$transportCalled) { $transportCalled=true; return true; })!=='disabled'
    || $transportCalled)
    throw new RuntimeException('Disabled invitation worker invoked transport.');
$workerInvite=bcrf_roost_create_household_invitation($pdo,30,'worker.delivery@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$rotatedKey=random_bytes(32);
$rotatedEnvelope=bcrf_roost_invite_encrypt('rotation-check',$deliveryKey,'integration-v1');
if (bcrf_roost_invite_decrypt_keyring($rotatedEnvelope,
    ['integration-v1'=>$deliveryKey,'integration-v2'=>$rotatedKey])!=='rotation-check')
    throw new RuntimeException('Old invitation key could not decrypt during rotation.');
try {
    bcrf_roost_invite_decrypt_keyring($rotatedEnvelope,['integration-v2'=>$rotatedKey]);
    throw new RuntimeException('Missing historical invitation key was accepted.');
} catch (InvalidArgumentException $expected) {
    // Missing old key must fail closed.
}
try {
    bcrf_roost_invite_decrypt_keyring($rotatedEnvelope,['integration-v1'=>$rotatedKey]);
    throw new RuntimeException('Wrong historical invitation key was accepted.');
} catch (DomainException $expected) {
    // Authenticated decryption must reject an incorrect key.
}
$workerConfig=['enabled'=>true,'key'=>$deliveryKey,'key_id'=>'integration-v1',
    'base_url'=>'https://batcaveroadfarm.com'];
try {
    bcrf_roost_run_invitation_delivery_once($pdo,
        array_merge($workerConfig,['base_url'=>'https://attacker.example']),
        function (): bool { throw new RuntimeException('Transport must not be called.'); });
    throw new RuntimeException('Invalid base URL was accepted before claim.');
} catch (InvalidArgumentException $expected) {
    // Queued invitation remains pending, with zero attempts.
}
$preflightStatus=$pdo->prepare("SELECT status,attempts FROM reward_invitation_delivery_outbox
    WHERE invitation_id=?");
$preflightStatus->execute([$workerInvite['invitation_id']]);
$preflightRow=$preflightStatus->fetch(PDO::FETCH_ASSOC);
if (!$preflightRow || $preflightRow['status']!=='pending' || (int)$preflightRow['attempts']!==0)
    throw new RuntimeException('Invalid worker configuration consumed an invitation claim.');
$workerOutcome=bcrf_roost_run_invitation_delivery_once($pdo,$workerConfig,
    function (string $recipient,array $message) use (&$transportCalled): bool {
        $transportCalled=true;
        return $recipient==='worker.delivery@example.test' &&
            str_contains($message['subject'],'Roost Rewards');
    });
if ($workerOutcome!=='sent' || !$transportCalled)
    throw new RuntimeException('Opt-in worker failed to dispatch test callback.');
$workerStatus=$pdo->prepare("SELECT status FROM reward_invitation_delivery_outbox WHERE invitation_id=?");
$workerStatus->execute([$workerInvite['invitation_id']]);
if ($workerStatus->fetchColumn()!=='sent')
    throw new RuntimeException('Worker did not acknowledge delivered invitation.');
$unknownInvite=bcrf_roost_create_household_invitation($pdo,30,'unknown.delivery@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$unknownCalls=0;
$unknownOutcome=bcrf_roost_run_invitation_delivery_once($pdo,$workerConfig,
    function () use (&$unknownCalls): bool {
        $unknownCalls++;
        throw new RuntimeException('Simulated ambiguous SMTP outcome');
    });
if ($unknownOutcome!=='failed' || $unknownCalls!==1)
    throw new RuntimeException('Ambiguous transport failure not handled safely.');
$workerStatus->execute([$unknownInvite['invitation_id']]);
if ($workerStatus->fetchColumn()!=='failed')
    throw new RuntimeException('Ambiguous delivery was automatically retried.');
if (bcrf_roost_run_invitation_delivery_once($pdo,$workerConfig,
    function () use (&$unknownCalls): bool { $unknownCalls++; return true; })!=='empty'
    || $unknownCalls!==1)
    throw new RuntimeException('Ambiguous delivery was resent.');
$crashInvite=bcrf_roost_create_household_invitation($pdo,30,'crash.delivery@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$crashClaim=bcrf_roost_claim_invitation_delivery($pdo);
if (!$crashClaim || !bcrf_roost_mark_invitation_dispatch_started($pdo,
    $crashClaim['id'],$crashClaim['claim_token']))
    throw new RuntimeException('Crash simulation could not start dispatch.');
$pdo->prepare("UPDATE reward_invitation_delivery_outbox
    SET claimed_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 20 MINUTE) WHERE id=?")
    ->execute([$crashClaim['id']]);
if (bcrf_roost_recover_invitation_delivery($pdo)!==1)
    throw new RuntimeException('Ambiguous crashed dispatch not recovered.');
$crashState=$pdo->prepare("SELECT status,last_error_code FROM reward_invitation_delivery_outbox WHERE id=?");
$crashState->execute([$crashClaim['id']]);
$crashRow=$crashState->fetch(PDO::FETCH_ASSOC);
if (!$crashRow || $crashRow['status']!=='failed' ||
    $crashRow['last_error_code']!=='delivery_unknown')
    throw new RuntimeException('Ambiguous crashed dispatch was retried or misclassified.');
$timeoutInvite=bcrf_roost_create_household_invitation($pdo,30,'timeout.after.send@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$timeoutClaim=bcrf_roost_claim_invitation_delivery($pdo);
if (!$timeoutClaim || !bcrf_roost_mark_invitation_dispatch_started($pdo,
    $timeoutClaim['id'],$timeoutClaim['claim_token']))
    throw new RuntimeException('Timeout after dispatch fixture failed.');
if (!bcrf_roost_fail_invitation_delivery($pdo,$timeoutClaim['id'],
    $timeoutClaim['claim_token'],'smtp_timeout',true))
    throw new RuntimeException('Post-dispatch timeout was not recorded.');
$crashState->execute([$timeoutClaim['id']]);
if ($crashState->fetch(PDO::FETCH_ASSOC)['status']!=='failed')
    throw new RuntimeException('Post-dispatch timeout was incorrectly retried.');
require_once __DIR__.'/../includes/roost-invitation-delivery-monitor.php';
$deliveryMetrics=bcrf_roost_invitation_delivery_metrics($pdo);
foreach (['pending','processing','sent','suppressed','failed'] as $status) {
    if (!isset($deliveryMetrics['status_counts'][$status]) ||
        !is_int($deliveryMetrics['status_counts'][$status]))
        throw new RuntimeException('Invitation queue metrics omitted a status.');
}
if ($deliveryMetrics['status_counts']['sent']<1 ||
    $deliveryMetrics['status_counts']['suppressed']<1 ||
    $deliveryMetrics['status_counts']['failed']<1)
    throw new RuntimeException('Invitation queue metrics missed delivery outcomes.');
foreach (['ready','stale','delayed','ambiguous'] as $metric) {
    if (!isset($deliveryMetrics[$metric]) || !is_int($deliveryMetrics[$metric]))
        throw new RuntimeException('Invitation queue metrics omitted operational count.');
}
if ($deliveryMetrics['ambiguous']<2)
    throw new RuntimeException('Ambiguous post-dispatch failures were not reported.');
$queuedHash=$pdo->prepare("SELECT token_hash FROM reward_household_invitations WHERE id=?");
$queuedHash->execute([$queuedInvitation['invitation_id']]);
if ($queuedHash->fetchColumn()!==hash('sha256',$decrypted['token']))
    throw new RuntimeException('Queued token does not match persisted invitation digest.');
try {
    bcrf_roost_create_household_invitation($pdo,30,'failed.queue@example.test',
        ['key'=>'invalid','key_id'=>'integration-v1']);
    throw new RuntimeException('Invalid encryption key unexpectedly issued invitation.');
} catch (InvalidArgumentException $expected) {}
$failedQueue=$pdo->prepare("SELECT COUNT(*) FROM reward_household_invitations
    WHERE invitee_email_hash=?");
$failedQueue->execute([hash('sha256','failed.queue@example.test')]);
if ((int)$failedQueue->fetchColumn()!==0)
    throw new RuntimeException('Failed encrypted queue left an orphan invitation.');
$invitation=bcrf_roost_create_household_invitation($pdo,30,' Other.Adult@Example.Test ');
if (strlen($invitation['token'])!==64 || $invitation['expires_in_days']!==7)
    throw new RuntimeException('Invitation token format invalid.');
$tokenCheck=$pdo->prepare("SELECT token_hash,invitee_email_hash,status FROM reward_household_invitations WHERE id=?");
$tokenCheck->execute([$invitation['invitation_id']]);
$stored=$tokenCheck->fetch(PDO::FETCH_ASSOC);
if (!$stored || $stored['token_hash']!==hash('sha256',$invitation['token'])
    || $stored['invitee_email_hash']!==hash('sha256','other.adult@example.test')
    || $stored['status']!=='pending') throw new RuntimeException('Invitation digest or state mismatch.');
try {
    bcrf_roost_create_household_invitation($pdo,30,'other.adult@example.test');
    throw new RuntimeException('Duplicate invitation unexpectedly succeeded.');
} catch (DomainException $expected) {}
try {
    bcrf_roost_create_household_invitation($pdo,2,'someone@example.test');
    throw new RuntimeException('Non-manager invitation unexpectedly succeeded.');
} catch (DomainException $expected) {}

require_once __DIR__ . '/../includes/roost-household-invitation-acceptance.php';
$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (50,'other.adult@example.test',UTC_TIMESTAMP(),'active'),
           (51,'wrong.recipient@example.test',UTC_TIMESTAMP(),'active')");
$pdo->exec("INSERT INTO reward_adult_verifications
    (customer_id,status,method,evidence_reference,verified_by_staff_id,verified_at,created_at)
    VALUES (50,'verified','staff_review','test-join',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
           (51,'verified','staff_review','test-other',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_consent_events
    (customer_id,purpose,policy_version,status,source,created_at)
    VALUES (50,'household_join','test-1','granted','authenticated_account',UTC_TIMESTAMP(3)),
           (51,'household_join','test-1','granted','authenticated_account',UTC_TIMESTAMP(3))");
foreach ([[50,false,true],[50,true,false],[51,true,true]] as $attempt) {
    try {
        bcrf_roost_accept_household_invitation($pdo,$invitation['token'],$attempt[0],$attempt[1],$attempt[2]);
        throw new RuntimeException('Unauthorized invitation acceptance succeeded.');
    } catch (InvalidArgumentException|DomainException $expected) {}
}
$accepted=bcrf_roost_accept_household_invitation($pdo,$invitation['token'],50,true,true);
if ($accepted!==['accepted'=>true]) throw new RuntimeException('Invitation acceptance failed.');
$acceptedCheck=$pdo->prepare("SELECT m.role,m.verification_status,m.can_redeem,i.status
    FROM reward_household_members m JOIN reward_household_invitations i
      ON i.household_id=m.household_id AND i.accepted_by_customer_id=m.customer_id
    WHERE m.customer_id=?");
$acceptedCheck->execute([50]);
$acceptedRow=$acceptedCheck->fetch(PDO::FETCH_ASSOC);
if (!$acceptedRow || $acceptedRow['role']!=='adult' || $acceptedRow['verification_status']!=='verified'
    || (int)$acceptedRow['can_redeem']!==0 || $acceptedRow['status']!=='accepted')
    throw new RuntimeException('Invitation acceptance membership mismatch.');
try {
    bcrf_roost_accept_household_invitation($pdo,$invitation['token'],50,true,true);
    throw new RuntimeException('Replay of accepted invitation succeeded.');
} catch (DomainException $expected) {}

require_once __DIR__ . '/../includes/roost-household-invitation-revocation.php';
$revokeInvite=bcrf_roost_create_household_invitation($pdo,30,'revoke.me@example.test');
try {
    bcrf_roost_revoke_household_invitation($pdo,2,$revokeInvite['invitation_id']);
    throw new RuntimeException('Non-manager revoked invitation.');
} catch (DomainException $expected) {}
if (!bcrf_roost_revoke_household_invitation($pdo,30,$revokeInvite['invitation_id']))
    throw new RuntimeException('Manager revocation failed.');
if (bcrf_roost_revoke_household_invitation($pdo,30,$revokeInvite['invitation_id']))
    throw new RuntimeException('Revoked invitation was revoked again.');
if (bcrf_roost_revoke_household_invitation($pdo,30,$invitation['invitation_id']))
    throw new RuntimeException('Accepted invitation was revoked.');
try {
    bcrf_roost_accept_household_invitation($pdo,$revokeInvite['token'],51,true,true);
    throw new RuntimeException('Revoked invitation was accepted.');
} catch (DomainException $expected) {}

require_once __DIR__ . '/../includes/roost-manager-invitation-summary.php';
$managerInvites=bcrf_roost_manager_invitation_summary($pdo,30);
$managerStates=[];
foreach ($managerInvites as $row) $managerStates[(int)$row['id']]=$row['display_status'];
if (($managerStates[(int)$invitation['invitation_id']]??null)!=='accepted'
    || ($managerStates[(int)$revokeInvite['invitation_id']]??null)!=='revoked')
    throw new RuntimeException('Manager invitation summary did not preserve accepted/revoked states.');
if (bcrf_roost_manager_invitation_summary($pdo,2)!==[])
    throw new RuntimeException('Non-manager could list household invitations.');
$pendingForManager=bcrf_roost_manager_pending_invitations($pdo,30);
$pendingIds=array_map(static fn(array $row): int => (int)$row['id'],$pendingForManager);
if (in_array((int)$invitation['invitation_id'],$pendingIds,true)
    || in_array((int)$revokeInvite['invitation_id'],$pendingIds,true))
    throw new RuntimeException('Accepted or revoked invitation shown as actionable.');
if (bcrf_roost_manager_pending_invitations($pdo,2)!==[])
    throw new RuntimeException('Non-manager could list pending invitations.');
$paginationInviteA=bcrf_roost_create_household_invitation($pdo,30,'pagination.a@example.test');
$paginationInviteB=bcrf_roost_create_household_invitation($pdo,30,'pagination.b@example.test');
$firstPendingPage=bcrf_roost_manager_pending_invitations($pdo,30,1,0);
$secondPendingPage=bcrf_roost_manager_pending_invitations($pdo,30,1,1);
if (count($firstPendingPage)!==1 || count($secondPendingPage)!==1
    || (int)$firstPendingPage[0]['id']===(int)$secondPendingPage[0]['id'])
    throw new RuntimeException('Pending invitation pagination overlapped or failed.');
$allPendingIds=array_map(static fn(array $row): int => (int)$row['id'],bcrf_roost_manager_pending_invitations($pdo,30,100,0));
if (!in_array((int)$paginationInviteA['invitation_id'],$allPendingIds,true)
    || !in_array((int)$paginationInviteB['invitation_id'],$allPendingIds,true))
    throw new RuntimeException('Pending invitation pagination omitted actionable rows.');
if (bcrf_roost_manager_pending_invitations($pdo,2,1,1)!==[])
    throw new RuntimeException('Pagination bypassed manager authorization.');

foreach ($pendingForManager as $row) {
    if (array_key_exists('token_hash',$row) || array_key_exists('invitee_email_hash',$row))
        throw new RuntimeException('Pending invitation selector leaked secret or recipient digest.');
}

require_once __DIR__ . '/../includes/roost-household-invitation-expiration.php';
$expiredInvite=bcrf_roost_create_household_invitation($pdo,30,'expire.me@example.test');
$setExpired=$pdo->prepare("UPDATE reward_household_invitations SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?");
$setExpired->execute([$expiredInvite['invitation_id']]);
if (bcrf_roost_revoke_household_invitation($pdo,30,$expiredInvite['invitation_id']))
    throw new RuntimeException('Expired pending invitation was revoked.');
if (bcrf_roost_expire_household_invitations($pdo,1)!==1)
    throw new RuntimeException('Expired invitation not marked.');
if (bcrf_roost_expire_household_invitations($pdo,1)!==0)
    throw new RuntimeException('Invitation expiration not idempotent.');
$state=$pdo->prepare("SELECT status FROM reward_household_invitations WHERE id=?");
$state->execute([$expiredInvite['invitation_id']]);
if ($state->fetchColumn()!=='expired') throw new RuntimeException('Expiration state missing.');
$state->execute([$invitation['invitation_id']]);
if ($state->fetchColumn()!=='accepted') throw new RuntimeException('Accepted invitation altered by expiration.');
$state->execute([$revokeInvite['invitation_id']]);
if ($state->fetchColumn()!=='revoked') throw new RuntimeException('Revoked invitation altered by expiration.');
try {
    bcrf_roost_accept_household_invitation($pdo,$expiredInvite['token'],51,true,true);
    throw new RuntimeException('Expired invitation accepted.');
} catch (DomainException $expected) {}

if (bcrf_roost_verified_adult_with_consent($pdo,40,'household_join'))
    throw new RuntimeException('Unverified email or missing join consent was accepted.');
$pdo->exec("UPDATE reward_adult_verifications SET status='revoked',revoked_at=UTC_TIMESTAMP(3) WHERE customer_id=50");
if (bcrf_roost_verified_adult_with_consent($pdo,50,'household_join'))
    throw new RuntimeException('Revoked adult verification was accepted.');
$pdo->exec("UPDATE reward_adult_verifications SET status='verified',revoked_at=NULL WHERE customer_id=50");
if (!bcrf_roost_verified_adult_with_consent($pdo,50,'household_join'))
    throw new RuntimeException('Restored verified adult with existing consent rejected.');
$pdo->exec("INSERT INTO reward_consent_events
    (customer_id,purpose,policy_version,status,source,created_at)
    VALUES (50,'household_join','test-1','granted','authenticated_account',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 SECOND))");
if (!bcrf_roost_verified_adult_with_consent($pdo,50,'household_join'))
    throw new RuntimeException('Verified adult with consent rejected.');
$pdo->exec("INSERT INTO reward_consent_events
    (customer_id,purpose,policy_version,status,source,created_at)
    VALUES (50,'household_join','test-1','withdrawn','authenticated_account',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 3 SECOND))");
if (bcrf_roost_verified_adult_with_consent($pdo,50,'household_join'))
    throw new RuntimeException('Withdrawn consent was ignored.');

require_once __DIR__ . '/../includes/roost-manager-invitation-summary.php';
$managerInvites=bcrf_roost_manager_invitation_summary($pdo,30);
if (count($managerInvites)<3) throw new RuntimeException('Manager invitation summary incomplete.');
foreach ($managerInvites as $row) {
    if (isset($row['token_hash']) || isset($row['invitee_email_hash']))
        throw new RuntimeException('Invitation summary leaked secret digest.');
}
if (bcrf_roost_manager_invitation_summary($pdo,50)!==[])
    throw new RuntimeException('Non-manager invitation summary exposed.');

require_once __DIR__ . '/../includes/roost-customer-consent-recording.php';
require_once __DIR__ . '/../includes/roost-customer-consent-withdrawal.php';
$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (60,'consent-test@example.test',UTC_TIMESTAMP(),'active'),
           (61,'unverified-consent@example.test',NULL,'active')");
if (bcrf_roost_verified_adult_with_consent($pdo,60,'rewards_enrollment'))
    throw new RuntimeException('Consent without adult evidence was accepted.');
try {
    bcrf_roost_record_customer_consent($pdo,60,'rewards_enrollment','test-1',false);
    throw new RuntimeException('Unchecked consent was recorded.');
} catch (DomainException $expected) {}
try {
    bcrf_roost_record_customer_consent($pdo,61,'rewards_enrollment','test-1',true);
    throw new RuntimeException('Unverified email recorded consent.');
} catch (DomainException $expected) {}
$consentId=bcrf_roost_record_customer_consent($pdo,60,'rewards_enrollment','test-1',true);
if ($consentId<=0) throw new RuntimeException('Consent event missing.');
$pdo->exec("INSERT INTO reward_adult_verifications
    (customer_id,status,method,evidence_reference,verified_by_staff_id,verified_at,created_at)
    VALUES (60,'verified','staff_review','test-consent',7,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
if (!bcrf_roost_verified_adult_with_consent($pdo,60,'rewards_enrollment'))
    throw new RuntimeException('Persisted consent not recognized.');
$withdrawnId=bcrf_roost_withdraw_customer_consent($pdo,60,'rewards_enrollment','test-1');
if ($withdrawnId<=$consentId || bcrf_roost_verified_adult_with_consent($pdo,60,'rewards_enrollment'))
    throw new RuntimeException('Consent withdrawal not enforced.');

require_once __DIR__ . '/../includes/roost-customer-invitation-preview.php';
$previewInvite=bcrf_roost_create_household_invitation($pdo,30,'preview@example.test');
$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (70,'preview@example.test',UTC_TIMESTAMP(),'active'),
           (71,'different@example.test',UTC_TIMESTAMP(),'active')");
$preview=bcrf_roost_customer_invitation_preview($pdo,70,$previewInvite['token']);
if (!$preview || $preview['invitation_id']!==$previewInvite['invitation_id']
    || isset($preview['token']) || isset($preview['invitee_email_hash']))
    throw new RuntimeException('Recipient invitation preview invalid or leaked secrets.');
if (bcrf_roost_customer_invitation_preview($pdo,71,$previewInvite['token'])!==null
    || bcrf_roost_customer_invitation_preview($pdo,70,'bad-token')!==null
    || bcrf_roost_customer_invitation_preview($pdo,50,$invitation['token'])!==null)
    throw new RuntimeException('Invitation preview recipient/membership gate failed.');
$pdo->prepare("UPDATE reward_household_invitations SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?")
    ->execute([$previewInvite['invitation_id']]);
if (bcrf_roost_customer_invitation_preview($pdo,70,$previewInvite['token'])!==null)
    throw new RuntimeException('Expired invitation preview leaked.');

require_once __DIR__ . '/../includes/roost-customer-consent-status.php';
$consentStatus=bcrf_roost_customer_consent_status($pdo,60,'rewards_enrollment');
if (!$consentStatus || $consentStatus['status']!=='withdrawn'
    || isset($consentStatus['evidence_reference']))
    throw new RuntimeException('Customer consent status failed to reflect withdrawal.');
if (bcrf_roost_customer_consent_status($pdo,61,'household_join')!==null)
    throw new RuntimeException('Absent customer consent incorrectly shown.');

// A newer revoked verification must override an older verified row.
$pdo->exec("INSERT INTO reward_adult_verifications
    (customer_id,status,method,evidence_reference,verified_by_staff_id,verified_at,revoked_at,created_at)
    VALUES (60,'revoked','staff_review','superseding-revocation',7,UTC_TIMESTAMP(3),
            UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 SECOND))");
bcrf_roost_record_customer_consent($pdo,60,'rewards_enrollment','test-1',true);
if (bcrf_roost_verified_adult_with_consent($pdo,60,'rewards_enrollment'))
    throw new RuntimeException('Superseding adult revocation was ignored.');

require_once __DIR__ . '/../includes/roost-consent-form-config.php';
putenv('BCRF_ROOST_CONSENT_FORMS_ENABLED');
putenv('BCRF_ROOST_CONSENT_POLICY_VERSION');
putenv('BCRF_ROOST_CONSENT_POLICY_URL');
if (bcrf_roost_consent_form_config()!==null)
    throw new RuntimeException('Consent form enabled without explicit approval.');
putenv('BCRF_ROOST_CONSENT_FORMS_ENABLED=1');
putenv('BCRF_ROOST_CONSENT_POLICY_VERSION=test-1');
putenv('BCRF_ROOST_CONSENT_POLICY_URL=https://evil.example/policy');
if (bcrf_roost_consent_form_config()!==null)
    throw new RuntimeException('Off-site consent policy was allowed.');
putenv('BCRF_ROOST_CONSENT_POLICY_URL=https://batcaveroadfarm.com/roost-rewards/policy.php');
if (bcrf_roost_consent_form_config()===null)
    throw new RuntimeException('Valid versioned consent policy was rejected.');
putenv('BCRF_ROOST_CONSENT_FORMS_ENABLED');
putenv('BCRF_ROOST_CONSENT_POLICY_VERSION');
putenv('BCRF_ROOST_CONSENT_POLICY_URL');

require_once __DIR__ . '/../includes/roost-customer-balance.php';
require_once __DIR__ . '/../includes/roost-customer-household-summary.php';
// Full customer journey using real services and disposable database fixtures:
// staff evidence -> separate consent -> enrollment -> invitation -> adult join
// -> shared household visibility. No production data or external emails.
require_once __DIR__ . '/../includes/roost-customer-consent-recording.php';
require_once __DIR__ . '/../includes/roost-customer-invitation-preview.php';
require_once __DIR__ . '/../includes/roost-household-invitation-acceptance.php';
$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (80,'journey-manager@example.test',UTC_TIMESTAMP(),'active'),
           (81,'journey-invitee@example.test',UTC_TIMESTAMP(),'active')");
$journeyManagerVerification=bcrf_roost_record_staff_adult_verification(
    $pdo,80,7,'staff_review','journey-manager-80');
if ($journeyManagerVerification<=0) throw new RuntimeException('Journey manager adult verification failed.');
$journeyManagerConsent=bcrf_roost_record_customer_consent($pdo,80,'rewards_enrollment','test-1',true);
if ($journeyManagerConsent<=0) throw new RuntimeException('Journey manager consent not recorded.');
$journeyHousehold=bcrf_roost_enroll_verified_adult($pdo,80,true,true);
$journeyId=(int)$journeyHousehold['household_id'];
if ($journeyId<=0 || $journeyHousehold['balance_points']!==0)
    throw new RuntimeException('Journey household not initialized with zero points.');
$journeyInvite=bcrf_roost_create_household_invitation($pdo,80,'journey-invitee@example.test');
$journeyPreview=bcrf_roost_customer_invitation_preview($pdo,81,$journeyInvite['token']);
if (!$journeyPreview || $journeyPreview['invitation_id']!==$journeyInvite['invitation_id'])
    throw new RuntimeException('Journey invitee cannot preview own invitation.');
if (bcrf_roost_customer_invitation_preview($pdo,80,$journeyInvite['token'])!==null)
    throw new RuntimeException('Journey manager could preview another recipient invitation.');
try {
    bcrf_roost_accept_household_invitation($pdo,$journeyInvite['token'],81,true,true);
    throw new RuntimeException('Journey invitation accepted without verified adult evidence and consent.');
} catch (DomainException $expected) {}
bcrf_roost_record_staff_adult_verification($pdo,81,7,'in_person_id_check','journey-invitee-81');
try {
    bcrf_roost_accept_household_invitation($pdo,$journeyInvite['token'],81,true,true);
    throw new RuntimeException('Journey invitation accepted without household join consent.');
} catch (DomainException $expected) {}
bcrf_roost_record_customer_consent($pdo,81,'household_join','test-1',true);
$journeyAccepted=bcrf_roost_accept_household_invitation($pdo,$journeyInvite['token'],81,true,true);
if ($journeyAccepted!==['accepted'=>true])
    throw new RuntimeException('Journey invitation acceptance failed.');
$journeyBalanceManager=bcrf_roost_customer_balance($pdo,80);
$journeyBalanceInvitee=bcrf_roost_customer_balance($pdo,81);
if (!$journeyBalanceManager || !$journeyBalanceInvitee ||
    $journeyBalanceManager['balance_points']!==0 ||
    $journeyBalanceInvitee['balance_points']!==0)
    throw new RuntimeException('Journey household balance not visible to both verified adults.');
$journeySummaryManager=bcrf_roost_customer_household_summary($pdo,80);
$journeySummaryInvitee=bcrf_roost_customer_household_summary($pdo,81);
if (!$journeySummaryManager || !$journeySummaryInvitee ||
    $journeySummaryManager['role']!=='manager' ||
    $journeySummaryInvitee['role']!=='adult' ||
    $journeySummaryManager['verified_members']!==2 ||
    $journeySummaryInvitee['verified_members']!==2)
    throw new RuntimeException('Journey household roles or shared member count incorrect.');
$journeyAccess=$pdo->prepare("SELECT can_redeem FROM reward_household_members WHERE customer_id=?");
$journeyAccess->execute([81]);
if ((int)$journeyAccess->fetchColumn()!==0)
    throw new RuntimeException('Journey invitee received unauthorized redemption rights.');
try {
    bcrf_roost_accept_household_invitation($pdo,$journeyInvite['token'],81,true,true);
    throw new RuntimeException('Journey invitation replay succeeded.');
} catch (DomainException $expected) {}

// Failure recovery: expired invitations cannot mutate membership or invitation
// state, and a later valid invitation can still be accepted.
$recoveryInvite=bcrf_roost_create_household_invitation($pdo,80,'journey-recovery@example.test');
$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (82,'journey-recovery@example.test',UTC_TIMESTAMP(),'active')");
bcrf_roost_record_staff_adult_verification($pdo,82,7,'staff_review','journey-recovery-82');
bcrf_roost_record_customer_consent($pdo,82,'household_join','test-1',true);
$expire=$pdo->prepare("UPDATE reward_household_invitations
    SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?");
$expire->execute([$recoveryInvite['invitation_id']]);
try {
    bcrf_roost_accept_household_invitation($pdo,$recoveryInvite['token'],82,true,true);
    throw new RuntimeException('Expired invitation was accepted.');
} catch (DomainException $expected) {}
$checkRecovery=$pdo->prepare("SELECT COUNT(*) FROM reward_household_members WHERE customer_id=?");
$checkRecovery->execute([82]);
if ((int)$checkRecovery->fetchColumn()!==0)
    throw new RuntimeException('Failed acceptance left a partial membership.');
$checkRecovery=$pdo->prepare("SELECT status,accepted_by_customer_id FROM reward_household_invitations WHERE id=?");
$checkRecovery->execute([$recoveryInvite['invitation_id']]);
$recoveryState=$checkRecovery->fetch(PDO::FETCH_ASSOC);
if (!$recoveryState || $recoveryState['status']!=='pending' || $recoveryState['accepted_by_customer_id']!==null)
    throw new RuntimeException('Failed acceptance changed invitation state.');
$freshInvite=bcrf_roost_create_household_invitation($pdo,80,'journey-recovery@example.test');
if (bcrf_roost_accept_household_invitation($pdo,$freshInvite['token'],82,true,true)!==['accepted'=>true])
    throw new RuntimeException('Fresh invitation could not recover after expired attempt.');

// Revoked invitations must remain unusable even with verified adult and consent.
$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (85,'revoked-invite@example.test',UTC_TIMESTAMP(),'active')");
bcrf_roost_record_staff_adult_verification($pdo,85,7,'staff_review','revoked-invite-85');
bcrf_roost_record_customer_consent($pdo,85,'household_join','test-1',true);
$revokedInvite=bcrf_roost_create_household_invitation($pdo,80,'revoked-invite@example.test');
$revoke=$pdo->prepare("UPDATE reward_household_invitations
    SET status='revoked',revoked_at=UTC_TIMESTAMP(3) WHERE id=? AND status='pending'");
$revoke->execute([$revokedInvite['invitation_id']]);
if ($revoke->rowCount()!==1) throw new RuntimeException('Cannot prepare revoked invitation fixture.');
try {
    bcrf_roost_accept_household_invitation($pdo,$revokedInvite['token'],85,true,true);
    throw new RuntimeException('Revoked invitation was accepted.');
} catch (DomainException $expected) {}
$check=$pdo->prepare("SELECT COUNT(*) FROM reward_household_members WHERE customer_id=?");
$check->execute([85]);
if ((int)$check->fetchColumn()!==0)
    throw new RuntimeException('Revoked invitation created membership.');
$check=$pdo->prepare("SELECT status,accepted_by_customer_id FROM reward_household_invitations WHERE id=?");
$check->execute([$revokedInvite['invitation_id']]);
$revokedState=$check->fetch(PDO::FETCH_ASSOC);
if (!$revokedState || $revokedState['status']!=='revoked' || $revokedState['accepted_by_customer_id']!==null)
    throw new RuntimeException('Revoked invitation state changed after rejection.');

// Competing requests must not create two memberships or accept one token twice.
$pdo->exec("INSERT INTO customers (id,email,email_verified_at,account_status)
    VALUES (83,'race-enroll@example.test',UTC_TIMESTAMP(),'active'),
           (84,'race-accept@example.test',UTC_TIMESTAMP(),'active')");
bcrf_roost_record_staff_adult_verification($pdo,83,7,'staff_review','race-enroll-83');
bcrf_roost_record_customer_consent($pdo,83,'rewards_enrollment','test-1',true);
bcrf_roost_record_staff_adult_verification($pdo,84,7,'staff_review','race-accept-84');
bcrf_roost_record_customer_consent($pdo,84,'household_join','test-1',true);
$raceInvite=bcrf_roost_create_household_invitation($pdo,80,'race-accept@example.test');
foreach ([['enroll',83,null],['accept',84,$raceInvite['token']]] as $race) {
    $workers=[];
    for ($n=0;$n<4;$n++) {
        $pipes=[];
        $env=array_merge(getenv(),[
            'BCRF_REWARDS_RACE_INVITATION_TOKEN'=>$race[2] ?? ''
        ]);
        $process=proc_open([PHP_BINARY,__DIR__.'/rewards_onboarding_race_worker.php',
            $race[0],(string)$race[1]],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
            $pipes,null,$env);
        if (!is_resource($process)) throw new RuntimeException('Cannot launch onboarding race worker.');
        fclose($pipes[0]);
        $workers[]=[$process,$pipes];
    }
    $created=0;
    foreach ($workers as [$process,$pipes]) {
        $output=trim(stream_get_contents($pipes[1]));
        $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0 || !in_array($output,['created','rejected'],true))
            throw new RuntimeException('Onboarding race worker failed: '.$error.' '.$output);
        if ($output==='created') $created++;
    }
    if ($created!==1) throw new RuntimeException('Concurrent onboarding must have exactly one winner.');
    $check=$pdo->prepare("SELECT COUNT(*) FROM reward_household_members
        WHERE customer_id=? AND revoked_at IS NULL");
    $check->execute([$race[1]]);
    if ((int)$check->fetchColumn()!==1)
        throw new RuntimeException('Concurrent onboarding produced incorrect membership count.');
}
$check=$pdo->prepare("SELECT status,accepted_by_customer_id FROM reward_household_invitations WHERE id=?");
$check->execute([$raceInvite['invitation_id']]);
$raceState=$check->fetch(PDO::FETCH_ASSOC);
if (!$raceState || $raceState['status']!=='accepted' || (int)$raceState['accepted_by_customer_id']!==84)
    throw new RuntimeException('Concurrent invitation acceptance did not settle correctly.');

require_once __DIR__ . '/../includes/roost-customer-balance.php';
require_once __DIR__ . '/../includes/roost-customer-history.php';
require_once __DIR__ . '/../includes/roost-customer-household-summary.php';
$householdSummary=bcrf_roost_customer_household_summary($pdo,1);
if ($householdSummary!==['role'=>'manager','verified_members'=>2,'pending_members'=>0])
    throw new RuntimeException('Household summary must contain only role and aggregate counts.');

if (bcrf_roost_customer_history($pdo,1)!==[]) throw new RuntimeException('New household history must be empty.');

$customerBalance=bcrf_roost_customer_balance($pdo,1);
if (!$customerBalance || $customerBalance['balance_points']!==0 || $customerBalance['lifetime_earned']!==0)
    throw new RuntimeException('Verified adult cannot read own household points.');
$pdo->exec("UPDATE customers SET email_verified_at=NULL WHERE id=2");
if (bcrf_roost_customer_history($pdo,2)!==[]) throw new RuntimeException('Unverified customer could read ledger history.');
if (bcrf_roost_customer_household_summary($pdo,2)!==null)
    throw new RuntimeException('Unverified customer could read household summary.');
if (bcrf_roost_customer_balance($pdo,2)!==null)
    throw new RuntimeException('Unverified customer could read household points.');
$pdo->exec("UPDATE customers SET email_verified_at=UTC_TIMESTAMP() WHERE id=2");
$pdo->exec("UPDATE reward_household_members SET verification_status='pending' WHERE customer_id=2");
if (bcrf_roost_customer_history($pdo,2)!==[]) throw new RuntimeException('Unverified member could read ledger history.');
if (bcrf_roost_customer_household_summary($pdo,2)!==null)
    throw new RuntimeException('Unverified member could read household summary.');
if (bcrf_roost_customer_balance($pdo,2)!==null)
    throw new RuntimeException('Unverified household member could read points.');
$pdo->exec("UPDATE reward_household_members SET verification_status='verified' WHERE customer_id=2");

$emailSchema = require __DIR__ . '/../admin/migrations/0020_roost_email_outbox.php';
$emailSchema($pdo);
$emailSchema($pdo); // additive migration must be rerunnable
$suppressionSchema = require __DIR__ . '/../admin/migrations/0021_roost_email_suppressions.php';
$suppressionSchema($pdo);
$suppressionSchema($pdo); // additive and rerunnable

require_once __DIR__ . '/../includes/roost-email-outbox.php';
$welcomeEvent = bcrf_roost_welcome_event_key(1, '2026-11-08 12:00:00');
expect(bcrf_roost_email_enqueue($pdo,1,1,'welcome',null,null,$welcomeEvent), 'verified welcome queued once');
expect(!bcrf_roost_email_enqueue($pdo,1,1,'welcome',null,null,$welcomeEvent), 'duplicate welcome not queued');
$hash = bcrf_roost_email_address_hash('CUSTOMER1@example.test');
expect($hash === bcrf_roost_email_address_hash(' customer1@example.test '), 'service suppression hash normalizes case and whitespace');
$pdo->prepare("INSERT INTO reward_email_suppressions (email_hash,scope,reason,created_at) VALUES (?,'service','hard_bounce',UTC_TIMESTAMP(3))")->execute([$hash]);
rejected(fn()=>bcrf_roost_email_enqueue($pdo,1,1,'welcome',null,null,$welcomeEvent), 'active hard bounce blocks queueing');
$pdo->prepare("UPDATE reward_email_suppressions SET cleared_at=UTC_TIMESTAMP(3) WHERE email_hash=?")->execute([$hash]);
expect(!bcrf_roost_email_service_suppressed($pdo,'customer1@example.test'), 'cleared bounce does not block service delivery');
require_once __DIR__ . '/../includes/roost-email-suppression-admin.php';
bcrf_roost_email_record_suppression($pdo,'customer1@example.test','service','hard_bounce','provider:fixture');
bcrf_roost_email_record_suppression($pdo,'customer1@example.test','all','admin_block','operator:fixture');
bcrf_roost_email_record_suppression($pdo,'customer1@example.test','service','hard_bounce','provider:repeat');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_email_suppressions")->fetchColumn()===2, 'repeat suppression does not create duplicates');
expect(bcrf_roost_email_service_suppressed($pdo,'customer1@example.test'), 'multiple reasons block service delivery');
expect(bcrf_roost_email_clear_suppression($pdo,'customer1@example.test','service','hard_bounce'), 'explicit bounce clearance succeeds');
expect(bcrf_roost_email_service_suppressed($pdo,'customer1@example.test'), 'independent admin block remains active');
expect(bcrf_roost_email_clear_suppression($pdo,'customer1@example.test','all','admin_block'), 'explicit admin clearance succeeds');
expect(!bcrf_roost_email_service_suppressed($pdo,'customer1@example.test'), 'all active reasons cleared');
expect(!bcrf_roost_email_clear_suppression($pdo,'customer1@example.test','all','admin_block'), 'clearance cannot replay');


$pdo->exec("UPDATE customers SET email_verified_at=NULL WHERE id=1");
rejected(fn()=>bcrf_roost_email_enqueue($pdo,1,1,'welcome',null,null,$welcomeEvent), 'unverified email cannot queue welcome');
$pdo->exec("UPDATE customers SET email_verified_at=UTC_TIMESTAMP(), account_status='disabled' WHERE id=1");
rejected(fn()=>bcrf_roost_email_enqueue($pdo,1,1,'welcome',null,null,$welcomeEvent), 'disabled customer cannot queue welcome');
$pdo->exec("UPDATE customers SET account_status='active' WHERE id=1");
$pdo->exec("UPDATE reward_household_members SET role='dependent' WHERE customer_id=1");
rejected(fn()=>bcrf_roost_email_enqueue($pdo,1,1,'welcome',null,null,$welcomeEvent), 'dependent cannot queue adult service welcome');
$pdo->exec("UPDATE reward_household_members SET role='manager' WHERE customer_id=1");

expect((int)$pdo->query("SELECT COUNT(*) FROM reward_email_outbox WHERE event_type='welcome'")->fetchColumn()===1, 'single durable welcome event');
rejected(fn()=>bcrf_roost_email_enqueue($pdo,1,999,'welcome',null,null,bcrf_roost_welcome_event_key(999,'2026-11-08 12:00:00')), 'unknown member cannot queue welcome');
rejected(fn()=>bcrf_roost_email_enqueue($pdo,1,1,'expiration_reminder',14,'2025-01-31 09:30:00','invalid-event'), 'invalid milestone cannot queue');
// Use database UTC as the clock so this test is independent of runner timezone.
$databaseNow = (string)$pdo->query("SELECT UTC_TIMESTAMP(0)")->fetchColumn();
$reminderActivity = null;
foreach (range(300, 360) as $ageDays) {
    $candidate = (new DateTimeImmutable($databaseNow, new DateTimeZone('UTC')))
        ->modify("-$ageDays days")->format('Y-m-d H:i:s');
    if (bcrf_roost_expiration_reminder_day($candidate, $databaseNow) === 60) {
        $reminderActivity = $candidate;
        break;
    }
}
expect($reminderActivity !== null, 'test fixture finds a real 60-day milestone');
$pdo->prepare("UPDATE reward_households SET last_qualifying_activity_at=? WHERE id=1")->execute([$reminderActivity]);
$pdo->exec("UPDATE reward_accounts SET balance_points=250 WHERE household_id=1");
$reminderEvent = bcrf_roost_expiration_event_key(1,1,$reminderActivity,60);
expect(bcrf_roost_email_enqueue($pdo,1,1,'expiration_reminder',60,$reminderActivity,$reminderEvent), 'eligible reminder queued');
expect(!bcrf_roost_email_enqueue($pdo,1,1,'expiration_reminder',60,$reminderActivity,$reminderEvent), 'duplicate reminder suppressed');
$pdo->prepare("UPDATE reward_households SET last_qualifying_activity_at=? WHERE id=1")
    ->execute([$reminderActivity.'.001']);
expect(!bcrf_roost_email_enqueue($pdo,1,2,'expiration_reminder',60,$reminderActivity,
    bcrf_roost_expiration_event_key(1,2,$reminderActivity,60)), 'millisecond activity change invalidates stale reminder');
$pdo->prepare("UPDATE reward_households SET last_qualifying_activity_at=? WHERE id=1")
    ->execute([$reminderActivity]);

$pdo->exec("UPDATE reward_households SET status='suspended' WHERE id=1");
rejected(fn()=>bcrf_roost_email_enqueue($pdo,1,2,'expiration_reminder',60,$reminderActivity,
    bcrf_roost_expiration_event_key(1,2,$reminderActivity,60)), 'suspended household cannot enqueue');
$pdo->exec("UPDATE reward_households SET status='active' WHERE id=1");
$pdo->exec("UPDATE reward_accounts SET balance_points=0 WHERE household_id=1");
expect(!bcrf_roost_email_enqueue($pdo,1,2,'expiration_reminder',60,$reminderActivity,
    bcrf_roost_expiration_event_key(1,2,$reminderActivity,60)), 'zero-balance reminder not queued');
// Claiming is transactional and never sends email.
require_once __DIR__ . '/../includes/roost-email-claim.php';
$claim = bcrf_roost_email_claim($pdo);
expect($claim !== null && $claim['event_type']==='welcome', 'oldest welcome event claimed');
expect(strlen($claim['claim_token'])===64, 'claim uses random 256-bit token');
require_once __DIR__ . '/../includes/roost-email-delivery-eligibility.php';
require_once __DIR__ . '/../includes/roost-email-recipient.php';
require_once __DIR__ . '/../includes/roost-email-delivery-policy.php';
expect(bcrf_roost_service_delivery_permitted('adult@example.com',true,false,false,'manager'), 'verified service recipient allowed without marketing subscription');
expect(!bcrf_roost_service_delivery_permitted('adult@example.com',true,true,false,'manager'), 'service suppression blocks service mail');
expect(!bcrf_roost_service_delivery_permitted('adult@example.com',true,false,true,'manager'), 'transport suppression blocks service mail');
expect(bcrf_roost_marketing_delivery_permitted(true,true,true,false,false), 'explicit marketing consent allows eligible delivery');
expect(!bcrf_roost_marketing_delivery_permitted(true,false,true,false,false), 'newsletter alone cannot opt into Rewards marketing');
expect(!bcrf_roost_marketing_delivery_permitted(true,true,false,false,false), 'global newsletter unsubscribe blocks marketing');
expect(!bcrf_roost_marketing_delivery_permitted(true,true,true,false,true), 'rolling promotional cap blocks marketing');

expect(bcrf_roost_verified_service_recipient(' Adult@Example.com ',true,false,'adult') === 'adult@example.com', 'independently verified adult address allowed');
expect(bcrf_roost_verified_service_recipient('adult@example.com',false,false,'adult') === null, 'membership alone does not verify email ownership');
expect(bcrf_roost_verified_service_recipient('adult@example.com',true,true,'manager') === null, 'suppressed recipient denied');
expect(bcrf_roost_verified_service_recipient('child@example.com',true,false,'dependent') === null, 'dependent not a service recipient');

expect(bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'verified welcome claim eligible');
$pdo->exec("UPDATE customers SET email='replacement@example.test',email_verified_at=NULL WHERE id=1");
expect(!bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'changed unverified recipient cannot receive queued welcome');
$pdo->exec("UPDATE customers SET email='customer1@example.test',email_verified_at=UTC_TIMESTAMP() WHERE id=1");
expect(bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'restored verified recipient passes preflight');
bcrf_roost_email_record_suppression($pdo,'replacement@example.test','service','provider_block','provider:replacement');
$pdo->exec("UPDATE customers SET email='replacement@example.test',email_verified_at=UTC_TIMESTAMP() WHERE id=1");
expect(!bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'newly verified replacement address must pass its own suppression check');
expect(bcrf_roost_email_clear_suppression($pdo,'replacement@example.test','service','provider_block'), 'replacement address suppression explicitly cleared');
expect(bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'verified replacement address permitted only after its suppression is cleared');
$pdo->exec("UPDATE customers SET email='customer1@example.test',email_verified_at=UTC_TIMESTAMP() WHERE id=1");


$pdo->prepare("UPDATE reward_email_suppressions SET cleared_at=NULL WHERE email_hash=?")->execute([$hash]);
expect(!bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'suppression added after claim blocks delivery');
$pdo->prepare("UPDATE reward_email_suppressions SET cleared_at=UTC_TIMESTAMP(3) WHERE email_hash=?")->execute([$hash]);

$pdo->exec("UPDATE customers SET email_verified_at=NULL WHERE id=1");
expect(!bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'unverified customer email denied');
$pdo->exec("UPDATE customers SET email_verified_at=UTC_TIMESTAMP(), account_status='disabled' WHERE id=1");
expect(!bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'disabled customer account denied');
$pdo->exec("UPDATE customers SET account_status='active' WHERE id=1");

expect(!bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],str_repeat('0',64)), 'incorrect claim token denied');
$pdo->exec("UPDATE reward_households SET status='suspended' WHERE id=1");
expect(!bcrf_roost_email_claim_eligible($pdo,(int)$claim['id'],$claim['claim_token']), 'suspended household denied after claim');
$pdo->exec("UPDATE reward_households SET status='active' WHERE id=1");

expect(bcrf_roost_email_suppress($pdo,(int)$claim['id'],$claim['claim_token'],'test suppression'), 'claimed event can be suppressed');
expect(!bcrf_roost_email_suppress($pdo,(int)$claim['id'],$claim['claim_token'],'duplicate suppression'), 'suppression cannot replay');
expect((string)$pdo->query("SELECT status FROM reward_email_outbox WHERE id=".(int)$claim['id'])->fetchColumn()==='suppressed', 'suppression persisted');
require_once __DIR__ . '/../includes/roost-email-dsn-review.php';
foreach ([
    '5.1.1'=>'mailbox_unknown_review',
    '5.1.10'=>'mailbox_unknown_review',
    '5.7.1'=>'permanent_failure_review',
    '5.2.2'=>'permanent_failure_review',
    '4.2.2'=>'transient_review',
    '4.4.1'=>'transient_review',
    '2.0.0'=>'success',
    '550'=>'unrecognized',
    '5.1.1 forged'=>'unrecognized',
    ''=>'unrecognized',
] as $statusCode=>$category) {
    expect(bcrf_roost_dsn_review_category($statusCode) === $category, 'SMTP delivery status is review-only: '.$statusCode);
}
require_once __DIR__ . '/../includes/roost-email-dsn-review-summary.php';
$review = bcrf_roost_dsn_review_summary('5.1.1', 'outbound:123', true, true);
expect($review['review_state'] === 'manual_hard_bounce_review' && $review['automatic_suppression_allowed'] === false, 'verified mailbox unknown still requires manual review');
expect(bcrf_roost_dsn_review_summary('5.1.1','outbound:123',true,false)['review_state'] === 'evidence_incomplete', 'unverified provenance blocks review decision');
expect(bcrf_roost_dsn_review_summary('5.1.1','outbound:123',false,true)['review_state'] === 'evidence_incomplete', 'uncorrelated recipient blocks review decision');
expect(bcrf_roost_dsn_review_summary('4.2.2','outbound:123',true,true)['review_state'] === 'investigate_transient', 'transient delivery does not become hard bounce');
expect(bcrf_roost_dsn_review_summary('5.7.1','outbound:123',true,true)['review_state'] === 'investigate_permanent', 'policy reject is not mailbox unknown');
expect(bcrf_roost_dsn_review_summary('garbage','outbound:123',true,true)['review_state'] === 'no_suppression_recommended', 'unrecognized code is not suppression evidence');
require_once __DIR__ . '/../includes/roost-email-dsn-review-access.php';
expect(bcrf_roost_delivery_review_access('administrator',true), 'active administrator can review delivery failures');
foreach (['moderator','Shopkeep','Media','Staff','user','Administrator',''] as $role) {
    expect(!bcrf_roost_delivery_review_access($role,true), 'non-administrator cannot review sensitive delivery failures');
}
expect(!bcrf_roost_delivery_review_access('administrator',false), 'disabled administrator cannot review delivery failures');
require_once __DIR__ . '/../includes/roost-email-manual-review-record.php';
$manualReview = bcrf_roost_manual_delivery_review_record([
    'enhanced_status'=>'5.1.1','outbound_reference'=>'mail:123',
    'provenance_verified'=>true,'recipient_correlated'=>true,
    'email'=>'should-not-be-returned@example.test','raw_body'=>'sensitive report'
]);
expect($manualReview['review_state']==='manual_hard_bounce_review', 'verified manual record classified for human review');
expect(!array_key_exists('email',$manualReview) && !array_key_exists('raw_body',$manualReview), 'manual record discards sensitive extra fields');
expect(bcrf_roost_manual_delivery_review_record([
    'enhanced_status'=>'5.1.1','outbound_reference'=>'mail:123',
    'provenance_verified'=>false,'recipient_correlated'=>true
])['review_state']==='evidence_incomplete', 'unverified manual record fails closed');
$invalidManual = false;
try { bcrf_roost_manual_delivery_review_record([
    'enhanced_status'=>'5.1.1','outbound_reference'=>'mail:123',
    'provenance_verified'=>'true','recipient_correlated'=>true
]); } catch (InvalidArgumentException $e) { $invalidManual=true; }
expect($invalidManual, 'manual record rejects non-boolean provenance');
require_once __DIR__ . '/../includes/roost-email-dsn-review-admin-preview.php';
$adminSummary = bcrf_roost_dsn_review_summary('5.1.1','outbound:admin-review',true,true);
expect(str_contains(bcrf_roost_delivery_review_admin_preview('administrator',true,$adminSummary),'Delivery failure review'), 'active admin can render dormant review');
foreach ([['Staff',true],['moderator',true],['administrator',false]] as [$role,$active]) {
    $denied = false;
    try { bcrf_roost_delivery_review_admin_preview($role,$active,$adminSummary); }
    catch (RuntimeException $e) { $denied = true; }
    expect($denied, 'admin review rendering fails closed for unauthorized accounts');
}
require_once __DIR__ . '/../includes/roost-email-dsn-review-preview.php';
$preview = bcrf_roost_dsn_review_preview_html(bcrf_roost_dsn_review_summary('5.1.1','ref:<script>alert(1)</script>',true,true));
expect(str_contains($preview,'&lt;script&gt;') && !str_contains($preview,'<script>'), 'staff delivery review escapes untrusted references');
expect(!str_contains($preview,'<form') && !str_contains($preview,'<button'), 'staff delivery preview has no mutation controls');
require_once __DIR__ . '/../includes/roost-email-provider-feedback.php';
expect(bcrf_roost_verified_provider_feedback_reason('hard_bounce') === 'hard_bounce', 'verified hard bounce maps to permanent suppression');
expect(bcrf_roost_verified_provider_feedback_reason('spam_complaint') === 'complaint', 'verified complaint maps to permanent suppression');
expect(bcrf_roost_verified_provider_feedback_reason('provider_block') === 'provider_block', 'verified provider block maps to suppression');
foreach (['soft_bounce','delivered','opened','clicked','deferred','unknown','Hard_Bounce',''] as $feedback) {
    expect(bcrf_roost_verified_provider_feedback_reason($feedback) === null, 'unrecognized or transient provider feedback is not a permanent suppression instruction');
}
require_once __DIR__ . '/../includes/roost-email-provider-feedback-record.php';
expect(!bcrf_roost_record_verified_provider_feedback($pdo,'customer1@example.test','soft_bounce','provider:soft-1'), 'transient provider feedback does not create suppression');
expect(bcrf_roost_record_verified_provider_feedback($pdo,'customer1@example.test','spam_complaint','provider:complaint-1'), 'verified complaint records service suppression');
expect(bcrf_roost_email_service_suppressed($pdo,'customer1@example.test'), 'verified complaint suppression blocks service delivery');
expect(bcrf_roost_record_verified_provider_feedback($pdo,'customer1@example.test','spam_complaint','provider:complaint-1'), 'duplicate provider feedback is idempotent');
expect(bcrf_roost_email_clear_suppression($pdo,'customer1@example.test','service','complaint'), 'provider feedback test suppression explicitly cleared');
expect(!bcrf_roost_email_service_suppressed($pdo,'customer1@example.test'), 'cleared provider feedback does not block service delivery');
require_once __DIR__ . '/../includes/roost-email-delivery-audit.php';
require_once __DIR__ . '/../includes/roost-email-reconciliation.php';

expect(!bcrf_roost_email_mark_sent($pdo,(int)$claim['id'],$claim['claim_token']), 'suppressed email cannot become sent');
$reminderClaim = bcrf_roost_email_claim($pdo);
expect($reminderClaim !== null && $reminderClaim['event_type']==='expiration_reminder', 'reminder claim available for audit test');
expect(bcrf_roost_email_review_queue($pdo) !== [], 'uncertain claimed deliveries appear in review');
expect(!bcrf_roost_email_mark_failed($pdo,(int)$claim['id'],str_repeat('0',64),'transport failure'), 'wrong token cannot mark failed');

expect(!bcrf_roost_email_mark_sent($pdo,(int)$reminderClaim['id'],str_repeat('0',64)), 'wrong token cannot mark delivery');
expect(bcrf_roost_email_mark_sent($pdo,(int)$reminderClaim['id'],$reminderClaim['claim_token']), 'confirmed delivery can be audited');
expect(!bcrf_roost_email_mark_sent($pdo,(int)$reminderClaim['id'],$reminderClaim['claim_token']), 'delivery confirmation cannot replay');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_email_outbox WHERE status='sent' AND sent_at IS NOT NULL")->fetchColumn()===1, 'sent audit timestamp persisted');
expect(!bcrf_roost_email_mark_failed($pdo,(int)$reminderClaim['id'],$reminderClaim['claim_token'],'late failure'), 'sent email cannot become failed');
expect(bcrf_roost_email_review_queue($pdo) === [], 'completed deliveries removed from review queue');


$pdo->exec("UPDATE reward_households SET last_qualifying_activity_at=NULL WHERE id=1");
$pdo->exec("UPDATE reward_accounts SET balance_points=0 WHERE household_id=1");

require __DIR__ . '/../includes/rewards.php';
// Staff-only verification service: no public endpoint, no implicit point award.
require __DIR__ . '/../includes/roost-attendance.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['user_id'] = 7;
$_SESSION['user_role'] = 'administrator';
$staffAttendance = bcrf_roost_verify_attendee($pdo, 4, 2);
expect($staffAttendance > 0, 'staff can verify an eligible attendee');
rejected(fn()=>bcrf_roost_verify_attendee($pdo,4,1),'full check-in cannot accept another attendee');
rejected(fn()=>bcrf_roost_verify_attendee($pdo,5,40),'unverified household member cannot be verified'); // customer 40 has no membership
$_SESSION['user_role'] = 'visitor';
rejected(fn()=>bcrf_roost_verify_attendee($pdo,5,2),'visitor cannot verify attendance');
$_SESSION['user_role'] = 'administrator';
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE entry_type='earn_visit'")->fetchColumn()===0,'attendance verification does not silently award points');
// Expiration and service-reminder calendar boundaries share one UTC anniversary.
require_once __DIR__ . '/../includes/roost-expiration-preview.php';
expect(bcrf_roost_expiration_at('2024-02-29 12:00:00')->format('Y-m-d H:i:s') === '2025-02-28 12:00:00', 'leap-day expiration clamps to Feb 28');
expect(bcrf_roost_expiration_at('2025-01-31 09:30:00')->format('Y-m-d H:i:s') === '2026-01-31 09:30:00', 'month-end anniversary remains Jan 31');
expect(!bcrf_roost_is_inactive_at('2024-02-29 12:00:00','2025-02-28 11:59:59'), 'not expired before exact anniversary');
expect(bcrf_roost_is_inactive_at('2024-02-29 12:00:00','2025-02-28 12:00:00'), 'expired at exact anniversary');
$expiration = bcrf_roost_expiration_at('2025-01-31 09:30:00');
foreach ([60,30,7] as $milestone) {
    $start = $expiration->modify("-$milestone days");
    expect(bcrf_roost_expiration_reminder_day('2025-01-31 09:30:00',$start->format('Y-m-d H:i:s')) === $milestone, "reminder milestone $milestone starts exactly");
    expect(bcrf_roost_expiration_reminder_day('2025-01-31 09:30:00',$start->modify('+23 hours')->format('Y-m-d H:i:s')) === $milestone, "reminder milestone $milestone remains eligible during 24h window");
}
expect(bcrf_roost_expiration_reminder_day('2025-01-31 09:30:00',$expiration->format('Y-m-d H:i:s')) === null, 'no reminder at expiration');
expect(bcrf_roost_expiration_reminder_day('2025-01-31 09:30:00',$expiration->modify('-61 days')->format('Y-m-d H:i:s')) === null, 'no early reminder');
// Service-email event identities must be stable and reminder eligibility fail closed.
require_once __DIR__ . '/../includes/roost-email-events.php';
expect(bcrf_roost_welcome_event_key(3, '2026-11-08 12:00:00') === bcrf_roost_welcome_event_key(3, '2026-11-08 12:00:00'), 'welcome event identity stable');
expect(bcrf_roost_welcome_event_key(3, '2026-11-08 12:00:00') !== bcrf_roost_welcome_event_key(4, '2026-11-08 12:00:00'), 'welcome recipient isolated');
expect(bcrf_roost_expiration_event_key(1, 3, '2025-01-31 09:30:00', 30) !== bcrf_roost_expiration_event_key(1, 4, '2025-01-31 09:30:00', 30), 'reminder recipient isolated');
expect(bcrf_roost_expiration_event_key(1, 3, '2025-01-31 09:30:00', 30) !== bcrf_roost_expiration_event_key(1, 3, '2025-02-01 09:30:00', 30), 'qualifying activity resets reminder identity');
expect(bcrf_roost_reminder_eligible('active', 250, '2025-01-31 09:30:00', '2025-12-02 09:30:00', 60), 'valid reminder eligible');
expect(!bcrf_roost_reminder_eligible('suspended', 250, '2025-01-31 09:30:00', '2025-12-02 09:30:00', 60), 'suspended household suppressed');
expect(!bcrf_roost_reminder_eligible('active', -10, '2025-01-31 09:30:00', '2025-12-02 09:30:00', 60), 'negative balance suppressed');
expect(!bcrf_roost_reminder_eligible('active', 250, null, '2025-12-02 09:30:00', 60), 'missing activity suppressed');
rejected(fn()=>bcrf_roost_expiration_event_key(1, 3, '2025-01-31 09:30:00', 14), 'invalid reminder event milestone');
// Service-email content must remain inert and reuse the shared branded wrapper at delivery.
require_once __DIR__ . '/../includes/roost-email-content.php';
$welcomeMail = bcrf_roost_welcome_email_content('https://batcaveroadfarm.com/roost-rewards/');
expect($welcomeMail['subject'] === 'Welcome to Roost Rewards', 'welcome email content');
expect(str_contains($welcomeMail['html'], '25 points'), 'welcome visit rule');
expect($welcomeMail['opts']['unsubscribe'] === false, 'service email not marketing');
$reminderMail = bcrf_roost_expiration_email_content(30, 675, '2027-01-31 09:30:00', 'https://batcaveroadfarm.com/roost-rewards/');
expect(($welcomeMail['opts']['transport'] ?? '')==='smtp_required', 'welcome mail requires strict SMTP');
expect(($reminderMail['opts']['transport'] ?? '')==='smtp_required', 'reminder mail requires strict SMTP');
// Exercise the real mail adapter only when no SMTP credential is configured.
// This must return before any SMTP or PHP mail() call is attempted.
require_once __DIR__ . '/../admin/includes/email_template.php';
if (!bcf_smtp_available()) {
    expect(!bcf_send_mail('nobody@example.test','Strict transport check','<p>Never send</p>', ['transport'=>'smtp_required']), 'strict SMTP fails closed when credentials are absent');
    expect(($GLOBALS['bcf_mail_last_error'] ?? '')==='Required SMTP transport unavailable', 'strict SMTP reports unavailable transport');
}

expect(str_contains($reminderMail['html'], '675 points'), 'reminder includes current balance');
expect(str_contains($reminderMail['html'], 'January 31, 2027'), 'reminder includes UTC expiry');
expect($reminderMail['opts']['unsubscribe'] === false, 'reminder service email not marketing');
rejected(fn()=>bcrf_roost_expiration_email_content(14, 675, '2027-01-31 09:30:00', 'https://batcaveroadfarm.com/roost-rewards/'), 'unapproved reminder milestone');
rejected(fn()=>bcrf_roost_expiration_email_content(30, 0, '2027-01-31 09:30:00', 'https://batcaveroadfarm.com/roost-rewards/'), 'zero balance reminder');
rejected(fn()=>bcrf_roost_welcome_email_content('http://example.test/'), 'non-HTTPS account link');
function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $run, string $message): void { try { $run(); } catch (BcrfRewardError $e) { return; } throw new RuntimeException('Expected rejection: '.$message); }
$base = ['household_id'=>1,'policy_id'=>1,'customer_id'=>1,'source_type'=>'sale','source_id'=>'100','entry_type'=>'earn_spend','points_delta'=>200,'idempotency_key'=>'test-sale-100'];
$a = bcrf_reward_post($pdo,$base);
expect($a['balance']===200 && !$a['replayed'],'first award');
$activityAfterEarn = $pdo->query("SELECT last_qualifying_activity_at FROM reward_households WHERE id=1")->fetchColumn();
expect($activityAfterEarn !== false && $activityAfterEarn !== null, 'earning records household qualifying activity');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'999','idempotency_key'=>'missing-sale'])),'missing sale cannot earn');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'102','idempotency_key'=>'different-customer-sale'])),'sale belongs to another customer');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'103','idempotency_key'=>'voided-sale'])),'voided sale cannot earn');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['points_delta'=>201,'idempotency_key'=>'inflated-points'])),'caller cannot inflate purchase points');
$pdo->exec("UPDATE sale_lines SET gross_cents=4100 WHERE sale_id=101");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'101','points_delta'=>275,'idempotency_key'=>'tampered-sale-lines'])),'inconsistent sale lines cannot earn');
$pdo->exec("UPDATE sale_lines SET gross_cents=5500 WHERE sale_id=101");
$pdo->exec("UPDATE payments SET status='pending' WHERE sale_id=101");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'101','points_delta'=>275,'idempotency_key'=>'unsettled-purchase'])),'unsettled purchase cannot earn');
$pdo->exec("UPDATE payments SET status='completed' WHERE sale_id=101");

rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'fabricated-label','idempotency_key'=>'invalid-sale-label'])),'purchase source requires numeric sale ID');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_id'=>'0','idempotency_key'=>'invalid-sale-zero'])),'purchase source rejects zero sale ID');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'wrong-kind','idempotency_key'=>'test-wrong-spend-source'])),'purchase earning requires sale source');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'earn_visit','source_type'=>'sale','source_id'=>'wrong-kind','points_delta'=>25,'idempotency_key'=>'test-wrong-visit-source'])),'visit earning requires visit source');

$pdo->exec("INSERT INTO reward_households (id,status,created_at) VALUES (2,'active',UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO reward_accounts (household_id,updated_at) VALUES (2,UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO customers (id) VALUES (3)");
$pdo->exec("INSERT INTO reward_household_members (household_id,customer_id,role,verification_status,can_redeem,joined_at) VALUES (2,3,'manager','verified',1,UTC_TIMESTAMP(3))");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['household_id'=>2,'customer_id'=>3,'idempotency_key'=>'other-household-same-sale'])),'same sale cannot award another household');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['household_id'=>2,'customer_id'=>1,'source_id'=>'101','points_delta'=>275,'idempotency_key'=>'wrong-household-customer'])),'sale cannot award to household other than verified customer household');

$b = bcrf_reward_post($pdo,$base);
expect($b['replayed'] && $b['balance']===200,'idempotent replay');
expect($pdo->query("SELECT last_qualifying_activity_at FROM reward_households WHERE id=1")->fetchColumn() === $activityAfterEarn, 'replayed earning does not refresh inactivity clock');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['idempotency_key'=>'different-key-same-sale'])),'duplicate sale source with different key');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['points_delta'=>300])),'conflicting replay');
$v = bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'1','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'test-visit-1']));
expect($v['balance']===225,'visit award');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'not-an-id','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'fabricated-visit'])),'fabricated visit label rejected');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'999','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'missing-visit'])),'nonexistent attendance rejected');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'2','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'wrong-attendee'])),'another customer attendance rejected');
$pdo->exec("UPDATE reward_verified_attendance SET business_date='2026-11-10' WHERE id=1");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'1','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'mismatched-operating-day'])),'mismatched check-in date rejected');
$pdo->exec("UPDATE reward_verified_attendance SET business_date='2026-11-08' WHERE id=1");

rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'invalid-visit-award','entry_type'=>'earn_visit','points_delta'=>26,'idempotency_key'=>'invalid-visit-award'])),'visit cannot earn more than 25 points');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'underpaid-visit-award','entry_type'=>'earn_visit','points_delta'=>24,'idempotency_key'=>'underpaid-visit-award'])),'visit cannot earn fewer than 25 points');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['household_id'=>2,'customer_id'=>3,'source_type'=>'visit','source_id'=>'1','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'other-household-same-visit'])),'same visit cannot award another household');

rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['source_type'=>'visit','source_id'=>'1','entry_type'=>'earn_visit','points_delta'=>25,'idempotency_key'=>'different-key-same-visit'])),'duplicate visit source with different key');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-250,'idempotency_key'=>'test-redeem-too-much'])),'overspend');
$d = bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-200,'source_type'=>'reward_fulfillment','source_id'=>'test_receipt_r1','idempotency_key'=>'test-redeem-1','customer_id'=>2]));
expect($d['balance']===25,'shared adult redemption');
$pdo->exec("UPDATE reward_household_members SET can_redeem=0 WHERE customer_id=2");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-1,'source_type'=>'reward','source_id'=>'disabled','idempotency_key'=>'test-disabled-redeem','customer_id'=>2])),'disabled redemption permission');
$pdo->exec("UPDATE reward_household_members SET can_redeem=1 WHERE customer_id=2");
$pdo->exec("UPDATE reward_household_members SET revoked_at=UTC_TIMESTAMP(3) WHERE customer_id=2");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-10,'idempotency_key'=>'test-revoked','customer_id'=>2])),'revoked adult');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'earn_spend','points_delta'=>25,'source_id'=>'102','idempotency_key'=>'test-revoked-earn','customer_id'=>2])),'revoked customer cannot earn');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'earn_visit','points_delta'=>25,'source_id'=>'revoked-visit','idempotency_key'=>'test-revoked-visit','customer_id'=>2])),'revoked customer cannot receive visit points');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'2','idempotency_key'=>'wrong-sale-refund','related_entry_id'=>$a['id']])),'refund must belong to awarded sale');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'999','idempotency_key'=>'missing-refund','related_entry_id'=>$a['id']])),'refund must exist');
$pdo->exec("INSERT INTO refund_lines VALUES (1,1,4000)");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=4000 WHERE id=1");
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-199,'source_type'=>'refund','source_id'=>'1','idempotency_key'=>'incorrect-refund-amount','related_entry_id'=>$a['id']])), 'ledger rejects incorrect reversal amount');
$refund = bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-200,'source_type'=>'refund','source_id'=>'1','idempotency_key'=>'test-refund-1','related_entry_id'=>$a['id']]));
expect($refund['balance']===-175,'refund negative balance');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'1','idempotency_key'=>'duplicate-refund-different-key','related_entry_id'=>$a['id']])),'same refund cannot reverse twice');

rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'refund_reversal','points_delta'=>-1,'source_type'=>'refund','source_id'=>'1','idempotency_key'=>'test-refund-2','related_entry_id'=>$a['id']])),'over reversal');
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-1,'idempotency_key'=>'test-negative-redeem'])),'negative balance redeem');
// Race two independent database sessions against the same 100-point balance.
// Each child starts together and attempts a 100-point redemption. Exactly one succeeds.
// Skip the concurrency assertion only when CLI pcntl is unavailable (CI installs it).
if (function_exists('pcntl_fork') && function_exists('stream_socket_pair')) {
    $topup = bcrf_reward_post($pdo,array_merge($base,['source_type'=>'sale','source_id'=>'101','points_delta'=>275,'idempotency_key'=>'test-topup']));
    expect($topup['balance']===100,'race starting balance');
    $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pipes === false) throw new RuntimeException('Cannot create race synchronization pipes.');
    $children = [];
    for ($i=0; $i<2; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Cannot fork concurrent reward test.');
        if ($pid === 0) {
            fclose($pipes[1]);
            fread($pipes[0],1); // both children wait for parent release
            fclose($pipes[0]);
            try {
                // Never share a fork-inherited PDO connection.
                $db = new PDO($dsn, getenv('BCRF_REWARDS_TEST_USER') ?: '', getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
                bcrf_reward_post($db,array_merge($base,['entry_type'=>'redeem','points_delta'=>-100,'source_type'=>'reward','source_id'=>'race-'.($i+1),'idempotency_key'=>'test-race-'.($i+1)]));
                exit(0);
            } catch (BcrfRewardError $e) { exit(3); }
              catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\\n"); exit(4); }
        }
        $children[] = $pid;
    }
    fclose($pipes[0]);
    fwrite($pipes[1],"XX");
    fclose($pipes[1]);
    $success=0; $insufficient=0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid,$status);
        if (!pcntl_wifexited($status)) throw new RuntimeException('Race child failed.');
        $code=pcntl_wexitstatus($status);
        if ($code===0) $success++;
        elseif ($code===3) $insufficient++;
        else throw new RuntimeException('Race child error code '.$code);
    }
    expect($success===1 && $insufficient===1,'exactly one concurrent redemption succeeds');
    // Forked children inherit the parent's MySQL socket; child shutdown can close it.
    // Reconnect in the parent before asserting the final persisted balance.
    $pdo = new PDO($dsn, getenv('BCRF_REWARDS_TEST_USER') ?: '', getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    expect((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()===0,'concurrent balance never overspent');
}

$pdo->exec("UPDATE reward_household_members SET revoked_at=NULL WHERE customer_id=2");
// Trusted visit-award orchestration: provenance, fixed points, replay and fabricated sources.
require __DIR__ . '/../includes/roost-visit-awards.php';
$visitAward = bcrf_roost_award_verified_visit($pdo, $staffAttendance);
expect(!$visitAward['replayed'] && $visitAward['id'] > 0, 'verified individual visit awards 25 points');
$visitReplay = bcrf_roost_award_verified_visit($pdo, $staffAttendance);
expect($visitReplay['replayed'] && $visitReplay['id'] === $visitAward['id'], 'same attendance never awards twice');
rejected(fn()=>bcrf_roost_award_verified_visit($pdo, 999999), 'fabricated attendance cannot earn');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE entry_type='earn_visit' AND source_id='".(int)$staffAttendance."'")->fetchColumn()===1, 'single verified visit ledger award');


// Read-only expiration preview: only positive-balance, active, stale households.
require_once __DIR__ . '/../includes/roost-expiration-preview.php';
expect(!bcrf_roost_is_inactive_at('2025-11-08 12:00:00','2026-11-08 11:59:59'), 'not expired before anniversary');
expect(bcrf_roost_is_inactive_at('2025-11-08 12:00:00','2026-11-08 12:00:00'), 'expires on 12-month anniversary');
expect(bcrf_roost_is_inactive_at('2024-02-29 12:00:00','2025-02-28 12:00:00'), 'leap-day anniversary clamps');
$pdo->exec("UPDATE reward_households SET last_qualifying_activity_at='2024-01-01 00:00:00' WHERE id=1");
$pdo->exec("UPDATE reward_accounts SET balance_points=75 WHERE household_id=1");
$candidates = bcrf_roost_expiration_candidates($pdo);
expect(count($candidates)===1 && (int)$candidates[0]['household_id']===1, 'stale positive household appears in preview');
expect((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()===75, 'preview does not deduct points');
// Expiration processor must lock and recheck activity; replay cannot deduct twice.
require __DIR__ . '/../includes/roost-expiration-processor.php';
$expired = bcrf_roost_expire_household($pdo, 1);
expect($expired['expired'] && $expired['points']===75 && $expired['balance']===0, 'stale positive points expire exactly once');
$again = bcrf_roost_expire_household($pdo, 1);
expect(!$again['expired'] && $again['points']===0, 'expiration replay is a no-op');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger WHERE entry_type='expire' AND household_id=1")->fetchColumn()===1, 'one expiration ledger event');
$pdo->exec("UPDATE reward_accounts SET balance_points=50 WHERE household_id=1");
$pdo->exec("UPDATE reward_households SET last_qualifying_activity_at=UTC_TIMESTAMP(3) WHERE id=1");
$fresh = bcrf_roost_expire_household($pdo, 1);
expect(!$fresh['expired'] && $fresh['balance']===50, 'recent activity blocks expiration');
// A POS refund must never reverse visit points, even if a caller forges a related entry.
rejected(fn()=>bcrf_reward_post($pdo,array_merge($base,[
    'entry_type'=>'refund_reversal','points_delta'=>-25,'source_type'=>'refund',
    'source_id'=>'2','idempotency_key'=>'refund-visit-forgery',
    'related_entry_id'=>$visitAward['id']
])), 'refund cannot reverse a visit award');
// Reconciled refund preview uses actual refund lines and sale-line running totals.
require_once __DIR__ . '/../includes/roost-refund-preview.php';
$pdo->exec("UPDATE sales SET status='voided' WHERE id=100");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,100),'voided sale refund rejected');
$pdo->exec("UPDATE sales SET status='pending' WHERE id=100");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,100),'pending sale refund rejected');
$pdo->exec("UPDATE sales SET status='refunded' WHERE id=100");
expect(bcrf_roost_refund_reversal_preview($pdo,100)['cumulative_points_to_reverse']===200,
    'fully refunded sale may be reconciled');
$pdo->exec("UPDATE sales SET status='completed' WHERE id=100");
$preview = bcrf_roost_refund_reversal_preview($pdo,100);
expect($preview['cumulative_points_to_reverse']===200 && $preview['remaining_eligible_cents']===0,'full refund preview matches original points');
$pdo->exec("UPDATE refund_payments SET amount_cents=3999 WHERE refund_id=1");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,100),'refund payout total mismatch rejected');
$pdo->exec("UPDATE refund_payments SET amount_cents=4000 WHERE refund_id=1");
$pdo->exec("UPDATE refund_payments SET tender='venmo', reference=NULL WHERE refund_id=1");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,100),'external refund without reference rejected');
$pdo->exec("UPDATE refund_payments SET reference='refund-confirmed-123' WHERE refund_id=1");
expect(bcrf_roost_refund_reversal_preview($pdo,100)['cumulative_points_to_reverse']===200,
    'external refund with recorded reference passes reconciliation');
$pdo->exec("UPDATE refund_payments SET tender='cash', reference=NULL WHERE refund_id=1");
$pdo->exec("UPDATE refunds SET net_cents=-1 WHERE id=1");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,100),'negative refund header rejected');
$pdo->exec("UPDATE refunds SET net_cents=4000 WHERE id=1");
$pdo->exec("UPDATE refund_lines SET net_cents=3900 WHERE refund_id=1");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,100),'refund amount mismatch fails closed');
// Partial refunds must reverse only the change in floored points.
$pdo->exec("UPDATE refunds SET net_cents=1001,total_cents=1001 WHERE id=1");
$pdo->exec("UPDATE refund_lines SET net_cents=1001 WHERE refund_id=1");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=1001 WHERE id=1");
$pdo->exec("UPDATE refund_payments SET amount_cents=1001 WHERE refund_id=1");
$partial = bcrf_roost_refund_reversal_preview($pdo,100);
expect($partial['original_eligible_cents']===4000 && $partial['remaining_eligible_cents']===2999 &&
    $partial['cumulative_points_to_reverse']===51,'partial refund rounds cumulative points correctly');
// A second refund changes the cumulative reversal by only the newly lost points.
$pdo->exec("INSERT INTO refunds VALUES (3,100,999,999)");
$pdo->exec("INSERT INTO refund_lines VALUES (3,1,999)");
$pdo->exec("INSERT INTO refund_payments VALUES (3,'cash',999,NULL)");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=2000 WHERE id=1");
$twice = bcrf_roost_refund_reversal_preview($pdo,100);
expect(bcrf_roost_refund_points_for_id($twice,1,0)===51, 'first refund reverses only its own points');
rejected(fn()=>bcrf_roost_refund_points_for_id($twice,3,0), 'later refund cannot bypass earlier reversal');
expect(bcrf_roost_refund_points_for_id($twice,3,51)===49, 'second refund reverses only incremental points');
rejected(fn()=>bcrf_roost_record_zero_point_refund($pdo,1,1),
    'point-bearing refund cannot be recorded as zero-point processed');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id=1")->fetchColumn()===1,
    'rejected false zero-point processing preserves existing point-bearing receipt');

$zeroThenDebit = ['refund_point_boundaries'=>[
    10=>['points_before'=>0,'points_after'=>0],
    11=>['points_before'=>0,'points_after'=>1]
]];
expect(bcrf_roost_refund_points_for_id($zeroThenDebit,10,0)===0,
    'earlier refund crossing no point threshold requires no debit');
expect(bcrf_roost_refund_points_for_id($zeroThenDebit,11,0)===1,
    'later threshold-crossing refund can post after a zero-point refund');
rejected(fn()=>bcrf_roost_refund_points_for_id($zeroThenDebit,11,1),
    'later refund cannot be reversed again after its point was already deducted');


expect($twice['cumulative_points_to_reverse']===100 && count($twice['eligible_refunds_cents'])===2,
    'multiple partial refunds reconcile cumulatively');
// Ineligible items may be refunded without reducing eligible points.
$pdo->exec("INSERT INTO sales VALUES (104,1,'partially_refunded',3000,0,3000)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
    VALUES (104,'retail',2000,0,0),(104,'gift_card',1000,0,1000)");
$pdo->exec("INSERT INTO refunds VALUES (4,104,1000,1000)");
$pdo->exec("INSERT INTO refund_lines VALUES (4,6,1000)");
$pdo->exec("INSERT INTO refund_payments VALUES (4,'cash',1000,NULL)");
$mixed = bcrf_roost_refund_reversal_preview($pdo,104);
expect($mixed['cumulative_points_to_reverse']===0 && $mixed['remaining_eligible_cents']===2000,
    'ineligible refund does not reverse eligible points');
// Sub-dollar refunds may not cross a points boundary; no ledger debit is due.
$pdo->exec("INSERT INTO sales VALUES (105,1,'partially_refunded',101,0,101)");
$pdo->exec("INSERT INTO sale_lines (sale_id,product_type,gross_cents,discount_cents,refunded_net_cents)
    VALUES (105,'retail',101,0,1)");
$pdo->exec("INSERT INTO refunds VALUES (5,105,1,1)");
$pdo->exec("INSERT INTO refund_lines VALUES (5,7,1)");
$pdo->exec("INSERT INTO refund_payments VALUES (5,'cash',1,NULL)");
$zeroRefund = bcrf_roost_refund_reversal_preview($pdo,105);
expect($zeroRefund['cumulative_points_to_reverse']===0 && bcrf_roost_refund_points_due(5,0,0)===0, 'one-cent refund not crossing a point threshold needs no debit');
expect(bcrf_roost_refund_is_points_noop($pdo,105,5,0), 'read-only preflight recognizes zero-point refund');
$pdo->exec("INSERT INTO reward_ledger (household_id,customer_id,policy_id,entry_type,points_delta,source_type,source_id,idempotency_key,created_at)
    VALUES (1,1,1,'earn_spend',5,'sale','105','test-sale-105-zero',UTC_TIMESTAMP(3))");
$zeroEarnId = (int)$pdo->lastInsertId();
$balanceBeforeZero = (int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn();
$ledgerCountBeforeZero = (int)$pdo->query("SELECT COUNT(*) FROM reward_ledger")->fetchColumn();
$recordedZero = bcrf_roost_record_zero_point_refund($pdo,5,$zeroEarnId);
expect(!$recordedZero['replayed'] && $recordedZero['points_reversed']===0,
    'zero-point refund recorded durably');
$replayedZero = bcrf_roost_record_zero_point_refund($pdo,5,$zeroEarnId);
expect($replayedZero['replayed'] && $replayedZero['points_reversed']===0,
    'zero-point refund processing replay is idempotent');
expect((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()===$balanceBeforeZero,
    'zero-point processing and replay leave account balance unchanged');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_ledger")->fetchColumn()===$ledgerCountBeforeZero,
    'zero-point processing and replay create no financial ledger entry');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id=5")->fetchColumn()===1,
    'zero-point refund has exactly one processing receipt');
$otherPdo = new PDO($dsn, getenv('BCRF_REWARDS_TEST_USER') ?: '',
    getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$otherPdo->beginTransaction();
$otherPdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1 FOR UPDATE")->fetchColumn();
$pdo->exec("SET SESSION innodb_lock_wait_timeout=1");
$lockBlocked = false;
try {
    bcrf_roost_record_zero_point_refund($pdo,5,$zeroEarnId);
} catch (PDOException $e) {
    $lockBlocked = in_array((string)$e->getCode(), ['HY000','40001'], true);
} finally {
    $otherPdo->rollBack();
    $pdo->exec("SET SESSION innodb_lock_wait_timeout=50");
}
expect($lockBlocked, 'concurrent account lock blocks duplicate refund processing');
expect(bcrf_roost_record_zero_point_refund($pdo,5,$zeroEarnId)['replayed'],
    'refund replay succeeds after competing transaction releases account lock');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id=5")->fetchColumn()===1,
    'account contention does not create duplicate receipts');
$pdo->exec("UPDATE reward_households SET status='suspended' WHERE id=1");
rejected(fn()=>bcrf_roost_record_zero_point_refund($pdo,5,$zeroEarnId),
    'suspended household cannot replay zero-point processing');
// Simulate an unprocessed refund while suspended, then restore the fixture.
$pdo->exec("DELETE FROM reward_refund_processing WHERE refund_id=5");
rejected(fn()=>bcrf_roost_record_zero_point_refund($pdo,5,$zeroEarnId),
    'suspended household cannot create a zero-point refund receipt');
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id=5")->fetchColumn()===0,
    'rejected suspended-household refund creates no receipt');
$pdo->exec("UPDATE reward_households SET status='active' WHERE id=1");
expect(!bcrf_roost_record_zero_point_refund($pdo,5,$zeroEarnId)['replayed'],
    'fresh zero-point processing succeeds after reactivation');
expect(bcrf_roost_record_zero_point_refund($pdo,5,$zeroEarnId)['replayed'],
    'active household may safely replay the same refund');
if (!function_exists('proc_open')) throw new RuntimeException('Parallel worker test requires proc_open.');
$workers = [];
for ($worker=0; $worker<4; $worker++) {
    $pipes = [];
    $cmd = [PHP_BINARY, __DIR__.'/rewards_concurrency_worker.php', '5', (string)$zeroEarnId, 'replay'];
    $process = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start concurrent rewards worker.');
    fclose($pipes[0]);
    $workers[] = [$process,$pipes];
}
foreach ($workers as [$process,$pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process)===0, 'concurrent worker failed: '.$stdout.' '.$stderr);
}
expect((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id=5")->fetchColumn()===1,
    'four simultaneous replay workers preserve exactly one refund receipt');
for ($round=1; $round<=5; $round++) {
    $pdo->exec("DELETE FROM reward_refund_processing WHERE refund_id=5");
    $workers = [];
    for ($worker=0; $worker<4; $worker++) {
        $pipes = [];
        $cmd = [PHP_BINARY, __DIR__.'/rewards_concurrency_worker.php', '5', (string)$zeroEarnId, 'create'];
        $process = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to start first-write worker.');
        fclose($pipes[0]);
        $workers[] = [$process,$pipes];
    }
    $createdCount = 0;
    foreach ($workers as [$process,$pipes]) {
        $stdout = trim(stream_get_contents($pipes[1]));
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process)===0, 'first-write worker failed: '.$stdout.' '.$stderr);
        expect(in_array($stdout, ['0','1'], true), 'worker must report valid creation count');
        $createdCount += (int)$stdout;
    }
    expect($createdCount===1, 'exactly one first-write creator in round '.$round);
    expect((int)$pdo->query("SELECT COUNT(*) FROM reward_refund_processing WHERE refund_id=5")->fetchColumn()===1,
        'one durable refund receipt in round '.$round);
}

rejected(fn()=>bcrf_roost_record_zero_point_refund($pdo,5,$a['id']),
    'processed refund cannot be rebound to a different earning');

expect(!bcrf_roost_refund_is_points_noop($pdo,100,200,0), 'read-only preflight identifies refund needing reversal');
$pdo->exec("UPDATE refund_lines SET net_cents=0 WHERE refund_id=5");
$pdo->exec("UPDATE refunds SET net_cents=0,total_cents=0 WHERE id=5");
$pdo->exec("UPDATE sale_lines SET refunded_net_cents=0 WHERE id=7");
$pdo->exec("DELETE FROM refund_payments WHERE refund_id=5");
rejected(fn()=>bcrf_roost_refund_reversal_preview($pdo,105),
    'zero-cash refund without payout record is invalid in POS lifecycle');
expect(bcrf_roost_refund_points_due(200,100,51)===49,'incremental reversal accounts for prior refund');
expect(bcrf_roost_refund_points_due(200,100,100)===0,'no points deducted twice');
rejected(fn()=>bcrf_roost_refund_points_due(200,100,101),'over-reversal rejected');
rejected(fn()=>bcrf_roost_refund_points_due(200,201,0),'refund cannot exceed original award');
require_once __DIR__.'/../includes/roost-invitation-delivery-purge.php';
$purgeFixture=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
    SET updated_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 DAY)
    WHERE id=? AND status='failed'");
$purgeFixture->execute([$timeoutClaim['id']]);
if (bcrf_roost_purge_invitation_delivery_payloads($pdo,7,100)<1)
    throw new RuntimeException('Terminal encrypted invitation payload was not purged.');
$purgeCheck=$pdo->prepare("SELECT status,payload_nonce,payload_ciphertext,payload_tag,
    dispatch_started_at,last_error_code FROM reward_invitation_delivery_outbox WHERE id=?");
$purgeCheck->execute([$timeoutClaim['id']]);
$purged=$purgeCheck->fetch(PDO::FETCH_ASSOC);
if (!$purged || $purged['status']!=='failed' || $purged['payload_nonce']!=='' ||
    $purged['payload_ciphertext']!=='' || $purged['payload_tag']!=='' ||
    $purged['dispatch_started_at']===null || $purged['last_error_code']!=='smtp_timeout')
    throw new RuntimeException('Invitation purge removed audit state or retained secrets.');
if (bcrf_roost_purge_invitation_delivery_payloads($pdo,7,100)!==0)
    throw new RuntimeException('Invitation payload purge was not idempotent.');
$recentTerminal=bcrf_roost_create_household_invitation($pdo,30,'recent.terminal@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$recentJob=$pdo->prepare("UPDATE reward_invitation_delivery_outbox
    SET status='suppressed',suppressed_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
    WHERE invitation_id=?");
$recentJob->execute([$recentTerminal['invitation_id']]);
if (bcrf_roost_purge_invitation_delivery_payloads($pdo,7,100)!==0)
    throw new RuntimeException('Recent terminal invitation was purged before retention window.');
$recentCheck=$pdo->prepare("SELECT payload_ciphertext FROM reward_invitation_delivery_outbox
    WHERE invitation_id=?");
$recentCheck->execute([$recentTerminal['invitation_id']]);
if ($recentCheck->fetchColumn()==='')
    throw new RuntimeException('Recent terminal invitation lost encrypted payload prematurely.');
$expiredPending=bcrf_roost_create_household_invitation($pdo,30,'expired.queue@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$validPending=bcrf_roost_create_household_invitation($pdo,30,'valid.queue@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$expirePending=$pdo->prepare("UPDATE reward_household_invitations
    SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?");
$expirePending->execute([$expiredPending['invitation_id']]);
if (bcrf_roost_suppress_inactive_invitation_delivery($pdo,100)<1)
    throw new RuntimeException('Expired pending invitation job was not suppressed.');
$pendingStates=$pdo->prepare("SELECT status FROM reward_invitation_delivery_outbox WHERE invitation_id=?");
$pendingStates->execute([$expiredPending['invitation_id']]);
if ($pendingStates->fetchColumn()!=='suppressed')
    throw new RuntimeException('Expired invitation queue status not terminal.');
$pendingStates->execute([$validPending['invitation_id']]);
if ($pendingStates->fetchColumn()!=='pending')
    throw new RuntimeException('Valid pending invitation was incorrectly suppressed.');
if (bcrf_roost_suppress_inactive_invitation_delivery($pdo,100)!==0)
    throw new RuntimeException('Expired invitation suppression was not idempotent.');
$revokedPending=bcrf_roost_create_household_invitation($pdo,30,'revoked.queue@example.test',
    ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
$revokePending=$pdo->prepare("UPDATE reward_household_invitations
    SET status='revoked' WHERE id=? AND status='pending'");
$revokePending->execute([$revokedPending['invitation_id']]);
if (bcrf_roost_suppress_inactive_invitation_delivery($pdo,100)!==1)
    throw new RuntimeException('Revoked pending invitation job was not suppressed.');
$pendingStates->execute([$revokedPending['invitation_id']]);
if ($pendingStates->fetchColumn()!=='suppressed')
    throw new RuntimeException('Revoked invitation delivery was not terminalized.');
$pendingStates->execute([$validPending['invitation_id']]);
if ($pendingStates->fetchColumn()!=='pending')
    throw new RuntimeException('Revocation suppression changed unrelated valid invitation.');

$batchExpired=[];
foreach (['batch.one@example.test','batch.two@example.test'] as $address) {
    $item=bcrf_roost_create_household_invitation($pdo,30,$address,
        ['key'=>$deliveryKey,'key_id'=>'integration-v1']);
    $expirePending->execute([$item['invitation_id']]);
    $batchExpired[]=$item['invitation_id'];
}
if (bcrf_roost_suppress_inactive_invitation_delivery($pdo,1)!==1)
    throw new RuntimeException('Invitation suppression ignored one-row batch limit.');
$batchStates=$pdo->prepare("SELECT COUNT(*) FROM reward_invitation_delivery_outbox
    WHERE invitation_id IN (?,?) AND status='suppressed'");
$batchStates->execute($batchExpired);
if ((int)$batchStates->fetchColumn()!==1)
    throw new RuntimeException('Invitation suppression modified more than one batch row.');
if (bcrf_roost_suppress_inactive_invitation_delivery($pdo,1)!==1)
    throw new RuntimeException('Second bounded invitation suppression batch did not progress.');
$batchStates->execute($batchExpired);
if ((int)$batchStates->fetchColumn()!==2)
    throw new RuntimeException('Bounded invitation suppression failed to process both rows.');
if (bcrf_roost_suppress_inactive_invitation_delivery($pdo,1)!==0)
    throw new RuntimeException('Bounded invitation suppression was not idempotent.');
$pendingStates->execute([$validPending['invitation_id']]);
if ($pendingStates->fetchColumn()!=='pending')
    throw new RuntimeException('Bounded suppression touched unrelated valid invitation.');

// Redemption settlement is internal-only and requires a verified receipt.
require_once __DIR__.'/../includes/roost-redemption-settlement.php';
$pdo->exec("UPDATE reward_accounts SET balance_points=1500 WHERE household_id=1");
try {
    bcrf_roost_settle_fulfilled_redemption($pdo,1,1,1,250,'integration_reward_001');
    throw new RuntimeException('Unverified redemption settlement was allowed.');
} catch (LogicException $expected) {
    if ($expected->getMessage()==='Unverified redemption settlement was allowed.') throw $expected;
}
$redeem=bcrf_roost_settle_fulfilled_redemption($pdo,1,1,1,250,'integration_reward_001',true);
if ($redeem['replayed'] || $redeem['balance']!==1250)
    throw new RuntimeException('Verified redemption settlement did not deduct 250 points.');
$again=bcrf_roost_settle_fulfilled_redemption($pdo,1,1,1,250,'integration_reward_001',true);
if (!$again['replayed'] || $again['id']!==$redeem['id'] || $again['balance']!==1250)
    throw new RuntimeException('Redemption receipt retry was not idempotent.');
try {
    bcrf_roost_settle_fulfilled_redemption($pdo,1,1,1,500,'integration_reward_001',true);
    throw new RuntimeException('Redemption receipt reused with different tier.');
} catch (BcrfRewardError $expected) {
    if ($expected->getMessage()==='Redemption receipt reused with different tier.') throw $expected;
}
// An alternate idempotency key cannot redeem the same fulfillment twice.
try {
    bcrf_reward_post($pdo,[
        'household_id'=>1,'customer_id'=>1,'policy_id'=>1,'entry_type'=>'redeem',
        'points_delta'=>-250,'source_type'=>'reward_fulfillment',
        'source_id'=>'integration_reward_001',
        'idempotency_key'=>'alternate:redeem:integration_reward_001'
    ]);
    throw new RuntimeException('Duplicate fulfillment accepted with alternate key.');
} catch (BcrfRewardError $expected) {
    if ($expected->getMessage()==='Duplicate fulfillment accepted with alternate key.') throw $expected;
}
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==1250)
    throw new RuntimeException('Duplicate fulfillment changed balance.');
$pdo->exec("UPDATE reward_household_members SET can_redeem=0 WHERE household_id=1 AND customer_id=1");
try {
    bcrf_roost_settle_fulfilled_redemption($pdo,1,1,1,250,'integration_reward_002',true);
    throw new RuntimeException('Revoked redemption permission was ignored.');
} catch (BcrfRewardError $expected) {
    if ($expected->getMessage()==='Revoked redemption permission was ignored.') throw $expected;
}
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==1250)
    throw new RuntimeException('Rejected redemption changed balance.');
$pdo->exec("UPDATE reward_household_members SET can_redeem=1 WHERE household_id=1 AND customer_id=1");
$pdo->exec("UPDATE customers SET email_verified_at=NULL WHERE id=1");
try {
    bcrf_reward_post($pdo,[
        'household_id'=>1,'customer_id'=>1,'policy_id'=>1,'entry_type'=>'redeem',
        'points_delta'=>-250,'source_type'=>'reward_fulfillment',
        'source_id'=>'integration_reward_003',
        'idempotency_key'=>'roost:redeem:integration_reward_003'
    ]);
    throw new RuntimeException('Unverified customer posted redemption directly.');
} catch (BcrfRewardError $expected) {
    if ($expected->getMessage()==='Unverified customer posted redemption directly.') throw $expected;
}
$pdo->exec("UPDATE customers SET email_verified_at=UTC_TIMESTAMP() WHERE id=1");
if ((int)$pdo->query("SELECT balance_points FROM reward_accounts WHERE household_id=1")->fetchColumn()!==1250)
    throw new RuntimeException('Unverified redemption changed balance.');
echo "Farm Rewards integration checks passed.\\n";
