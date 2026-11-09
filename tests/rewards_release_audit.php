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
$verificationPage=file_get_contents($root.'/admin/roost_adult_verification.php');
if ($verificationPage===false ||
    !str_contains($verificationPage, "BCRF_ROOST_STAFF_VERIFICATION_ENABLED") ||
    !str_contains($verificationPage, 'bcrf_roost_staff_verification_access') ||
    !str_contains($verificationPage, 'csrf_verify(') ||
    !str_contains($verificationPage, 'bcrf_rl_hit(') ||
    !str_contains($verificationPage, 'bcrf_roost_record_staff_adult_verification(') ||
    !str_contains($verificationPage, "SELECT role,account_status FROM users WHERE id=?") ||
    !str_contains($verificationPage, 'adult_evidence_confirmed'))
    throw new RuntimeException('Staff verification form must remain gated, audited and CSRF protected.');
$enrollPage=file_get_contents($root.'/roost-rewards/enroll.php');
if ($enrollPage===false ||
    !str_contains($enrollPage, "BCRF_ROOST_ENROLLMENT_ENABLED") ||
    !str_contains($enrollPage, 'bcrf_roost_consent_form_config()') ||
    !str_contains($enrollPage, 'csrf_verify(') ||
    !str_contains($enrollPage, 'bcrf_rl_hit(') ||
    !str_contains($enrollPage, 'bcrf_roost_verified_adult_with_consent(') ||
    !str_contains($enrollPage, "consent['policy_version']") ||
    !str_contains($enrollPage, 'bcrf_roost_enroll_verified_adult('))
    throw new RuntimeException('Customer enrollment must remain launch-gated and evidence-based.');
$acceptPage=file_get_contents($root.'/roost-rewards/accept-invitation.php');
if ($acceptPage===false ||
    !str_contains($acceptPage, 'BCRF_ROOST_INVITATION_ACCEPTANCE_ENABLED') ||
    !str_contains($acceptPage, 'bcrf_roost_consent_form_config()') ||
    !str_contains($acceptPage, 'csrf_verify(') ||
    !str_contains($acceptPage, 'bcrf_rl_hit(') ||
    !str_contains($acceptPage, "consent['policy_version']") ||
    !str_contains($acceptPage, 'bcrf_roost_verified_adult_with_consent(') ||
    !str_contains($acceptPage, 'bcrf_roost_accept_household_invitation(') ||
    !str_contains($acceptPage, 'Referrer-Policy: no-referrer'))
    throw new RuntimeException('Invitation acceptance must remain launch-gated, private and evidence-based.');
require_once $root.'/includes/roost-household-invitation-email.php';
$testToken=str_repeat('a',64);
$renderedInvite=bcrf_roost_household_invitation_email($testToken,'https://batcaveroadfarm.com');
if (!str_contains($renderedInvite['html'],$testToken) ||
    !str_contains($renderedInvite['opts']['text'],$testToken) ||
    !str_contains($renderedInvite['html'],'/roost-rewards/accept-invitation.php') ||
    str_contains($renderedInvite['html'],'?token=') ||
    $renderedInvite['opts']['transport']!=='smtp_required')
    throw new RuntimeException('Invitation email must use code entry and strict SMTP.');
foreach (['http://batcaveroadfarm.com','https://evil.example','https://batcaveroadfarm.com@evil.example','https://batcaveroadfarm.com?redirect=evil'] as $unsafeUrl) {
    try {
        bcrf_roost_household_invitation_email($testToken,$unsafeUrl);
        throw new RuntimeException('Unsafe invitation URL was accepted.');
    } catch (InvalidArgumentException $expected) {}
}
foreach (['',str_repeat('a',63),str_repeat('g',64),str_repeat('A',64)] as $invalidToken) {
    try {
        bcrf_roost_household_invitation_email($invalidToken,'https://batcaveroadfarm.com');
        throw new RuntimeException('Invalid invitation bearer code was accepted.');
    } catch (InvalidArgumentException $expected) {}
}
$wwwInvite=bcrf_roost_household_invitation_email($testToken,'https://www.batcaveroadfarm.com');
if (!str_contains($wwwInvite['html'],'https://www.batcaveroadfarm.com/roost-rewards/accept-invitation.php'))
    throw new RuntimeException('Official www invitation URL was not rendered.');
require_once $root.'/includes/roost-invitation-delivery-crypto.php';
$cryptoKey=random_bytes(32);
$secretPayload=json_encode(['recipient'=>'invitee@example.test','token'=>$testToken],JSON_THROW_ON_ERROR);
$encrypted=bcrf_roost_invite_encrypt($secretPayload,$cryptoKey,'test-key-1');
if (bcrf_roost_invite_decrypt($encrypted,$cryptoKey,'test-key-1')!==$secretPayload ||
    str_contains(json_encode($encrypted,JSON_THROW_ON_ERROR),$testToken) ||
    str_contains(json_encode($encrypted,JSON_THROW_ON_ERROR),'invitee@example.test'))
    throw new RuntimeException('Invitation encryption round trip or confidentiality failed.');
foreach (['ciphertext','nonce','tag'] as $field) {
    $tampered=$encrypted;
    $tampered[$field]=base64_encode(str_repeat('z',strlen(base64_decode($encrypted[$field],true))));
    try {
        bcrf_roost_invite_decrypt($tampered,$cryptoKey,'test-key-1');
        throw new RuntimeException('Tampered invitation payload was accepted.');
    } catch (DomainException|InvalidArgumentException $expected) {}
}
try {
    bcrf_roost_invite_decrypt($encrypted,random_bytes(32),'test-key-1');
    throw new RuntimeException('Wrong invitation encryption key was accepted.');
} catch (DomainException $expected) {}
try {
    bcrf_roost_invite_decrypt($encrypted,$cryptoKey,'other-key');
    throw new RuntimeException('Wrong invitation encryption key ID was accepted.');
} catch (InvalidArgumentException $expected) {}
$inviteMail=file_get_contents($root.'/includes/roost-household-invitation-email.php');
if ($inviteMail===false ||
    !str_contains($inviteMail, 'smtp_required') ||
    !str_contains($inviteMail, '/roost-rewards/accept-invitation.php') ||
    !str_contains($inviteMail, 'Invitation code:') ||
    str_contains($inviteMail, '?token=') ||
    str_contains($inviteMail, 'bcf_send_mail('))
    throw new RuntimeException('Invitation content must remain dormant and bearer codes must stay out of URLs.');
$revokePage=file_get_contents($root.'/roost-rewards/revoke-invitation.php');
if ($revokePage===false ||
    !str_contains($revokePage, 'BCRF_ROOST_INVITATION_REVOCATION_ENABLED') ||
    !str_contains($revokePage, 'csrf_verify(') ||
    !str_contains($revokePage, 'bcrf_rl_hit(') ||
    !str_contains($revokePage, 'bcrf_roost_revoke_household_invitation(') ||
    !str_contains($revokePage, "confirm_revoke") ||
    !str_contains($revokePage, "is_logged_in()") ||
    !str_contains($revokePage, 'bcrf_roost_manager_pending_invitations(') ||
    !str_contains($revokePage, 'in_array((int)$id,') ||
    !str_contains($revokePage, '<select id="invitation_id"') ||
    !str_contains($revokePage, '$hasNextPage') ||
    !str_contains($revokePage, 'array_slice($pendingInvitations,0,$pageSize)') ||
    !str_contains($revokePage, 'name="page"'))
    throw new RuntimeException('Invitation revocation must remain gated, authenticated and CSRF protected.');
$dashboard=file_get_contents($root.'/roost-rewards/balance.php');
if ($dashboard===false ||
    !str_contains($dashboard, "BCRF_ROOST_ENROLLMENT_ENABLED") ||
    !str_contains($dashboard, "BCRF_ROOST_INVITATION_ACCEPTANCE_ENABLED") ||
    !str_contains($dashboard, "BCRF_ROOST_INVITATION_REVOCATION_ENABLED") ||
    !str_contains($dashboard, "BCRF_ROOST_INVITATION_ISSUANCE_ENABLED") ||
    !str_contains($dashboard, '$issuanceOpen') ||
    !str_contains($dashboard, '/roost-rewards/invite-adult.php') ||
    !str_contains($dashboard, '$revocationOpen') ||
    !str_contains($dashboard, "/roost-rewards/revoke-invitation.php") ||
    !str_contains($dashboard, '$enrollmentOpen ?') ||
    !str_contains($dashboard, '$joiningOpen ?') ||
    !str_contains($dashboard, '/roost-rewards/enroll.php') ||
    !str_contains($dashboard, '/roost-rewards/accept-invitation.php') ||
    !str_contains($dashboard, 'Redemption is not available yet.'))
    throw new RuntimeException('Dashboard onboarding links must follow dormant feature gates.');

/* Monitoring is aggregate-only, admin-only and never starts email delivery. */
$monitor=file_get_contents($root.'/includes/roost-invitation-delivery-monitor.php');
$dashboardView=file_get_contents($root.'/admin/views/dashboard.php');
if ($monitor===false || $dashboardView===false ||
    !str_contains($monitor, 'COUNT(*) AS total') ||
    !str_contains($monitor, 'GROUP BY status') ||
    !str_contains($dashboardView, "if (\$isAdmin)") ||
    !str_contains($dashboardView, 'bcrf_roost_invitation_delivery_metrics($pdo)') ||
    !str_contains($dashboardView, "TABLE_NAME='reward_invitation_delivery_outbox'") ||
    str_contains($monitor, 'payload_ciphertext') ||
    str_contains($monitor, 'invitee_email') ||
    str_contains($monitor, 'claim_token') ||
    str_contains($monitor, 'payload_nonce') ||
    str_contains($monitor, 'payload_tag') ||
    str_contains($dashboardView, 'bcrf_roost_run_invitation_delivery_once('))
    throw new RuntimeException('Roost delivery dashboard must remain aggregate-only, admin-only and inert.');

/* Invitation SMTP must never silently fall back to PHP mail(). */
$inviteAdapter=file_get_contents($root.'/includes/roost-invitation-delivery-smtp.php');
$inviteWorker=file_get_contents($root.'/includes/roost-invitation-delivery-worker.php');
if ($inviteAdapter===false || $inviteWorker===false ||
    !str_contains($inviteAdapter, "'smtp_enabled'") ||
    !str_contains($inviteAdapter, "'transport'=>'smtp_required'") ||
    !str_contains($inviteWorker, "bcrf_roost_mark_invitation_dispatch_started("))
    throw new RuntimeException('Invitation SMTP strict transport or dispatch boundary missing.');

/* Manager invitation issuance must remain disabled by default and queue only. */
$issuePage=file_get_contents($root.'/roost-rewards/invite-adult.php');
if ($issuePage===false ||
    !str_contains($issuePage, "BCRF_ROOST_INVITATION_ISSUANCE_ENABLED") ||
    !str_contains($issuePage, "is_logged_in()") ||
    !str_contains($issuePage, "csrf_verify(") ||
    !str_contains($issuePage, "bcrf_rl_hit(") ||
    !str_contains($issuePage, "confirm_adult") ||
    !str_contains($issuePage, "bcrf_roost_create_household_invitation(") ||
    !str_contains($issuePage, "BCRF_ROOST_INVITATION_ENCRYPTION_KEY_B64") ||
    !str_contains($issuePage, "'delivery_queued'") ||
    str_contains($issuePage, "bcf_send_mail(") ||
    str_contains($issuePage, "bcrf_roost_run_invitation_delivery_once(") ||
    str_contains($issuePage, 'echo $token'))
    throw new RuntimeException('Manager invitation issuance must stay gated and queue-only.');

/* Redemption preview must verify adult permissions without posting points. */
$redeemAuth=file_get_contents($root.'/includes/roost-redemption-authorization.php');
if ($redeemAuth===false ||
    !str_contains($redeemAuth, "can_redeem") ||
    !str_contains($redeemAuth, "verification_status") ||
    !str_contains($redeemAuth, "email_verified_at") ||
    !str_contains($redeemAuth, "balance_points") ||
    !str_contains($redeemAuth, "[250,500,1000,2000]") ||
    str_contains($redeemAuth, "bcrf_reward_post("))
    throw new RuntimeException('Redemption authorization must remain permission-scoped and read-only.');

// Legacy settlement is disabled: a caller-supplied boolean is not proof.
$settlement=file_get_contents($root.'/includes/roost-redemption-settlement.php');
if ($settlement===false ||
    !str_contains($settlement,'Legacy redemption settlement disabled; use approved voucher issuance.') ||
    str_contains($settlement,'bcrf_reward_post('))
    throw new RuntimeException('Legacy settlement must fail closed without ledger posting.');

// The replacement path must bind the debit and durable receipt atomically.
$issuance=file_get_contents($root.'/includes/roost-redemption-issuance.php');
if ($issuance===false ||
    !str_contains($issuance,'beginTransaction()') ||
    !str_contains($issuance,'commit()') ||
    !str_contains($issuance,'rollBack()') ||
    !str_contains($issuance,"'internal_voucher'") ||
    !str_contains($issuance,"'roost:redeem:'") ||
    !str_contains($issuance,'fulfillment_receipt') ||
    !str_contains($issuance,'ledger_entry_id'))
    throw new RuntimeException('Approved voucher issuance lacks transaction or receipt safeguards.');
echo "Offline rewards release safety audit passed.\\n";
