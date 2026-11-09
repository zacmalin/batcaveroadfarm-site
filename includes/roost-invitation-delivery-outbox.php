<?php
// Queue only: never sends, logs, or returns decrypted invitation secrets.
require_once __DIR__.'/roost-invitation-delivery-crypto.php';
function bcrf_roost_queue_invitation_delivery(PDO $pdo,int $invitationId,string $recipientEmail,
    string $token,string $binaryKey,string $keyId): int {
    if ($invitationId<1 || !preg_match('/^[a-f0-9]{64}$/D',$token))
        throw new InvalidArgumentException('Valid invitation and code required.');
    if (!filter_var($recipientEmail,FILTER_VALIDATE_EMAIL) || strlen($recipientEmail)>254)
        throw new InvalidArgumentException('Valid recipient required.');
    // Require an outer transaction: issuance and delivery intent must commit atomically.
    if (!$pdo->inTransaction()) throw new LogicException('Invitation delivery must be queued within issuance transaction.');
    $payload=json_encode(['recipient'=>strtolower(trim($recipientEmail)),'token'=>$token],JSON_THROW_ON_ERROR);
    $envelope=bcrf_roost_invite_encrypt($payload,$binaryKey,$keyId);
    $q=$pdo->prepare("SELECT id FROM reward_household_invitations
        WHERE id=? AND token_hash=? AND invitee_email_hash=?
          AND status='pending' AND expires_at>UTC_TIMESTAMP(3) FOR UPDATE");
    $q->execute([$invitationId,hash('sha256',$token),hash('sha256',strtolower(trim($recipientEmail)))]);
    if (!$q->fetchColumn()) throw new DomainException('Invitation not eligible for delivery.');
    $q=$pdo->prepare("INSERT INTO reward_invitation_delivery_outbox
        (invitation_id,payload_version,encryption_key_id,payload_nonce,payload_ciphertext,payload_tag,
         status,attempts,available_at,created_at,updated_at)
        VALUES (?,?,?,?,?,?,'pending',0,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $q->execute([$invitationId,$envelope['version'],$envelope['key_id'],
        $envelope['nonce'],$envelope['ciphertext'],$envelope['tag']]);
    return (int)$pdo->lastInsertId();
}
