<?php
// Dormant authenticated encryption for invitation delivery payloads.
// Caller supplies a 32-byte binary key loaded from external secret management.
// Never log keys, plaintext, or decrypted payloads.
function bcrf_roost_invite_encrypt(string $plaintext,string $key,string $keyId): array {
    if (strlen($key)!==32 || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$keyId))
        throw new InvalidArgumentException('Invalid invitation encryption configuration.');
    if ($plaintext==='' || strlen($plaintext)>8192)
        throw new InvalidArgumentException('Invalid invitation delivery payload.');
    if (!function_exists('openssl_encrypt'))
        throw new RuntimeException('Authenticated encryption unavailable.');
    $nonce=random_bytes(12);
    $tag='';
    $ciphertext=openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'roost-invite-delivery-v1',16);
    if ($ciphertext===false || strlen($tag)!==16)
        throw new RuntimeException('Invitation payload encryption failed.');
    return ['version'=>1,'key_id'=>$keyId,'nonce'=>base64_encode($nonce),
        'ciphertext'=>base64_encode($ciphertext),'tag'=>base64_encode($tag)];
}
function bcrf_roost_invite_decrypt(array $envelope,string $key,string $expectedKeyId): string {
    if (strlen($key)!==32 || ($envelope['version']??null)!==1 ||
        !is_string($envelope['key_id']??null) ||
        !hash_equals($expectedKeyId,$envelope['key_id']))
        throw new InvalidArgumentException('Invalid invitation encryption configuration.');
    foreach (['nonce','ciphertext','tag'] as $field) {
        if (!is_string($envelope[$field]??null)) throw new InvalidArgumentException('Invalid encrypted payload.');
    }
    $nonce=base64_decode($envelope['nonce'],true);
    $ciphertext=base64_decode($envelope['ciphertext'],true);
    $tag=base64_decode($envelope['tag'],true);
    if ($nonce===false || strlen($nonce)!==12 || $ciphertext===false ||
        strlen($ciphertext)>8192 || $tag===false || strlen($tag)!==16)
        throw new InvalidArgumentException('Invalid encrypted payload.');
    if (!function_exists('openssl_decrypt')) throw new RuntimeException('Authenticated decryption unavailable.');
    $plaintext=openssl_decrypt($ciphertext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,'roost-invite-delivery-v1');
    if ($plaintext===false) throw new DomainException('Invitation payload authentication failed.');
    return $plaintext;
}

/**
 * Decrypt an invitation using its recorded key ID. A keyring allows safe
 * read-only rollover while new invitations use the current encryption key.
 * Keys must be raw 32-byte values supplied by external secret management.
 */
function bcrf_roost_invite_decrypt_keyring(array $envelope,array $keys): string {
    $keyId=$envelope['key_id']??null;
    if (!is_string($keyId) || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/D',$keyId) ||
        !array_key_exists($keyId,$keys) || !is_string($keys[$keyId]) ||
        strlen($keys[$keyId])!==32)
        throw new InvalidArgumentException('Invitation decryption key unavailable.');
    return bcrf_roost_invite_decrypt($envelope,$keys[$keyId],$keyId);
}
