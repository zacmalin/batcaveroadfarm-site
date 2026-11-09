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
$pdo->exec("CREATE TABLE refunds (id BIGINT UNSIGNED PRIMARY KEY, sale_id BIGINT NOT NULL, net_cents BIGINT NOT NULL, total_cents BIGINT NOT NULL) ENGINE=InnoDB");
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
require_once __DIR__ . '/../includes/roost-customer-balance.php';
$customerBalance=bcrf_roost_customer_balance($pdo,1);
if (!$customerBalance || $customerBalance['balance_points']!==0 || $customerBalance['lifetime_earned']!==0)
    throw new RuntimeException('Verified adult cannot read own household points.');
$pdo->exec("UPDATE customers SET email_verified_at=NULL WHERE id=2");
if (bcrf_roost_customer_balance($pdo,2)!==null)
    throw new RuntimeException('Unverified customer could read household points.');
$pdo->exec("UPDATE customers SET email_verified_at=UTC_TIMESTAMP() WHERE id=2");
$pdo->exec("UPDATE reward_household_members SET verification_status='pending' WHERE customer_id=2");
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
rejected(fn()=>bcrf_roost_verify_attendee($pdo,5,3),'unverified household member cannot be verified'); // customer 3 not yet enrolled
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
$d = bcrf_reward_post($pdo,array_merge($base,['entry_type'=>'redeem','points_delta'=>-200,'source_type'=>'reward','source_id'=>'r1','idempotency_key'=>'test-redeem-1','customer_id'=>2]));
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
echo "Farm Rewards integration checks passed.\\n";
