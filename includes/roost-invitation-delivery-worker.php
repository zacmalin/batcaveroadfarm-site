<?php
// Testable single-job worker. No SMTP client or scheduler is wired here.
// Explicit opt-in plus a caller-provided transport callback are required.
require_once __DIR__.'/roost-invitation-delivery-claim.php';
require_once __DIR__.'/roost-invitation-delivery-eligibility.php';
require_once __DIR__.'/roost-invitation-delivery-crypto.php';
require_once __DIR__.'/roost-household-invitation-email.php';

function bcrf_roost_run_invitation_delivery_once(PDO $pdo,array $config,callable $transport): string {
    if (($config['enabled']??false)!==true) return 'disabled';
    if (!isset($config['key'],$config['key_id']) || !is_string($config['key']) ||
        strlen($config['key'])!==32 || !is_string($config['key_id']) ||
        !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$config['key_id']))
        throw new InvalidArgumentException('Invitation delivery encryption key required.');
    $baseUrl=$config['base_url']??'';
    if (!is_string($baseUrl)) throw new InvalidArgumentException('Official farm URL required.');
    // Validate URL/host before claiming a job. A configuration error must not
    // consume an attempt or move a queued invitation to failed.
    bcrf_roost_household_invitation_email(str_repeat('0',64),$baseUrl);
    $claim=bcrf_roost_claim_invitation_delivery($pdo);
    if ($claim===null) return 'empty';
    $id=$claim['id']; $claimToken=$claim['claim_token'];
    try {
        if (!bcrf_roost_invitation_delivery_eligible($pdo,$id,$claimToken)) {
            bcrf_roost_complete_invitation_delivery($pdo,$id,$claimToken,'suppressed');
            return 'suppressed';
        }
        $q=$pdo->prepare("SELECT payload_version,encryption_key_id,payload_nonce,payload_ciphertext,payload_tag
            FROM reward_invitation_delivery_outbox
            WHERE id=? AND claim_token=? AND status='processing'");
        $q->execute([$id,$claimToken]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Claim no longer valid.');
        $envelope=[
            'version'=>(int)$row['payload_version'],'key_id'=>$row['encryption_key_id'],
            'nonce'=>$row['payload_nonce'],'ciphertext'=>$row['payload_ciphertext'],
            'tag'=>$row['payload_tag']
        ];
        $decryptionKeys=$config['decryption_keys']??[$config['key_id']=>$config['key']];
        if (!is_array($decryptionKeys)) throw new InvalidArgumentException('Invalid decryption keyring.');
        $payload=json_decode(bcrf_roost_invite_decrypt_keyring($envelope,$decryptionKeys),
            true,512,JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_string($payload['recipient']??null) ||
            !is_string($payload['token']??null) ||
            !bcrf_roost_invitation_delivery_payload_matches($pdo,$id,$claimToken,
                $payload['recipient'],$payload['token']))
            throw new DomainException('Delivery payload mismatch.');
        $message=bcrf_roost_household_invitation_email($payload['token'],$baseUrl);
        // Recheck immediately before invoking transport, including revocation.
        if (!bcrf_roost_invitation_delivery_eligible($pdo,$id,$claimToken)) {
            bcrf_roost_complete_invitation_delivery($pdo,$id,$claimToken,'suppressed');
            return 'suppressed';
        }
        // Commit a durable no-auto-retry boundary before any external send.
        if (!bcrf_roost_mark_invitation_dispatch_started($pdo,$id,$claimToken))
            throw new RuntimeException('Unable to mark invitation dispatch boundary.');
        // The callback may have sent before throwing; never retry an ambiguous outcome.
        $accepted=$transport($payload['recipient'],$message);
        if ($accepted!==true) {
            bcrf_roost_fail_invitation_delivery($pdo,$id,$claimToken,'delivery_unknown',false);
            return 'failed';
        }
        if (!bcrf_roost_complete_invitation_delivery($pdo,$id,$claimToken,'sent'))
            throw new RuntimeException('Delivery acknowledgement lost.');
        return 'sent';
    } catch (Throwable $e) {
        // Do not log secrets or exception messages. Ambiguous outcomes are not retried.
        try {
            bcrf_roost_fail_invitation_delivery($pdo,$id,$claimToken,'delivery_unknown',false);
        } catch (Throwable $ignored) {
            // Preserve a non-secret failure result; stale-claim recovery is separately bounded.
        }
        return 'failed';
    }
}
