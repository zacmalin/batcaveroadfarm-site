<?php
// Offline release audit: no credentials, production queries, or activation.
$root=dirname(__DIR__);
$expected=[
 'admin/migrations/0016_farm_rewards_foundation.php'=>['reward_households','reward_household_members','reward_policies','reward_accounts','reward_ledger'],
 'admin/migrations/0017_roost_rewards_attendance.php'=>[],
 'admin/migrations/0018_roost_refund_processing.php'=>[],
 'admin/migrations/0019_roost_pos_outbox.php'=>['reward_pos_outbox'],
];
foreach($expected as $path=>$tables){
    $source=file_get_contents($root.'/'.$path);
    if($source===false || !str_contains($source,'return function (PDO $pdo): void'))
        throw new RuntimeException("Missing additive migration: $path");
    foreach($tables as $table)
        if(!str_contains($source,'CREATE TABLE IF NOT EXISTS '.$table))
            throw new RuntimeException("Missing required schema $table in $path");
}
$worker=file_get_contents($root.'/includes/roost-pos-outbox-worker.php');
$dispatch=file_get_contents($root.'/includes/roost-pos-outbox-dispatch.php');
$adapter=file_get_contents($root.'/includes/roost-pos-outbox-integration.php');
if(!str_contains($worker,'BCRF_ROOST_OUTBOX_MAX_ATTEMPTS = 8') ||
   !str_contains($worker,'FOR UPDATE SKIP LOCKED') ||
   !str_contains($worker,"status='dead'") ||
   !str_contains($dispatch,'function bcrf_roost_outbox_dispatch_enabled(): bool { return false; }') ||
   !str_contains($adapter,'function bcrf_roost_pos_enqueue_enabled(): bool { return false; }') ||
   !str_contains($adapter,'ORDER BY id LIMIT 2 FOR UPDATE') ||
   !str_contains($adapter,'bcrf_roost_pos_outbox_enqueue_pinned_sale($pdo,$sourceId,(int)$policies[0])'))
    throw new RuntimeException('Required worker safety or dormant release gate missing.');
// Offline service-email launch guard; does not connect or send.
foreach ([
 'admin/migrations/0020_roost_email_outbox.php'=>'reward_email_outbox',
 'admin/migrations/0021_roost_email_suppressions.php'=>'reward_email_suppressions',
] as $path=>$table) {
 $source=file_get_contents($root.'/'.$path);
 if ($source===false || !str_contains($source,'return function (PDO $pdo): void') ||
     !str_contains($source,'CREATE TABLE IF NOT EXISTS '.$table))
     throw new RuntimeException("Missing dormant service-email schema: $path");
}
$mailer=file_get_contents($root.'/admin/includes/email_template.php');
$content=file_get_contents($root.'/includes/roost-email-content.php');
$eligibility=file_get_contents($root.'/includes/roost-email-delivery-eligibility.php');
$claim=file_get_contents($root.'/includes/roost-email-claim.php');
if ($mailer===false || $content===false || $eligibility===false || $claim===false ||
    !str_contains($mailer, "'smtp_required'") ||
    !str_contains($mailer, 'if ($strictSmtp) return false;') ||
    !str_contains($mailer, 'Required SMTP transport unavailable') ||
    !str_contains($content, "'transport'=>'smtp_required'") ||
    !str_contains($eligibility, 'bcrf_roost_email_service_suppressed') ||
    !str_contains($claim, 'FOR UPDATE SKIP LOCKED'))
    throw new RuntimeException('Dormant service-email strict SMTP, suppression or claim safety gate missing.');
// Keep the admin delivery-review route permission-gated and disconnected.
$registry=file_get_contents($root.'/admin/includes/permission_checks.php');
$controller=file_get_contents($root.'/admin/admin.php');
$view=file_get_contents($root.'/admin/views/rewards_delivery_review.php');
$access=file_get_contents($root.'/includes/roost-email-dsn-review-access.php');
if ($registry===false || $controller===false || $view===false || $access===false ||
    !str_contains($registry, "'rewards_delivery_review' =>") ||
    !str_contains($controller, "'rewards_delivery_review' => __DIR__ . '/views/rewards_delivery_review.php'") ||
    !str_contains($view, 'bcrf_roost_delivery_review_access') ||
    !str_contains($view, 'http_response_code(403)') ||
    !str_contains($access, "\$role === 'administrator'") ||
    !str_contains($view, 'Mailbox integration and delivery-failure records are not connected.') ||
    str_contains($view, '\$_POST') ||
    str_contains($view, 'bcrf_roost_email_record_suppression(') ||
    !str_contains($view, "'DEMO-ONLY-001'") ||
    !str_contains($view, "'provenance_verified' => false") ||
    !str_contains($view, "'recipient_correlated' => false") ||
    !str_contains($view, 'bcrf_roost_delivery_review_admin_preview'))
    throw new RuntimeException('Admin delivery-review preview must remain gated, read-only and disconnected.');
echo "Offline rewards release safety audit passed.\\n";
