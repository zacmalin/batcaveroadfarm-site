<?php
// Pure invitation email renderer. No transport, queue, or sending side effects.
// The one-time bearer token must never be logged or persisted in plaintext.
function bcrf_roost_household_invitation_email(string $token,string $baseUrl): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token))
        throw new InvalidArgumentException('Valid invitation token required.');
    $url=parse_url($baseUrl);
    if (!$url || ($url['scheme']??'')!=='https' || empty($url['host']) ||
        isset($url['user']) || isset($url['pass']) || isset($url['query']) ||
        isset($url['fragment']) || isset($url['port']) ||
        !in_array(strtolower($url['host']),['batcaveroadfarm.com','www.batcaveroadfarm.com'],true))
        throw new InvalidArgumentException('Official HTTPS farm URL required.');
    $link='https://'.strtolower($url['host']).'/roost-rewards/accept-invitation.php?token='.$token;
    $safe=htmlspecialchars($link,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    return [
        'subject'=>'Invitation to join a Roost Rewards household',
        'html'=>'<h1>Roost Rewards household invitation</h1>'
            .'<p>A verified household manager invited you to join their shared Roost Rewards household.</p>'
            .'<p><a href="'.$safe.'">Review your invitation</a>. This link expires in 7 days and can be used once.</p>'
            .'<p>Only accept if you recognize the invitation. A verified adult account and separate Rewards consent are required.</p>'
            .'<p>If you were not expecting this invitation, you can ignore this message.</p>',
        'opts'=>['subtitle'=>'Roost Rewards','unsubscribe'=>false,'transport'=>'smtp_required',
            'text'=>"You were invited to a Roost Rewards household. Review the one-time invitation within 7 days: $link. Only accept if you recognize it. A verified adult account and separate Rewards consent are required. If unexpected, ignore this message."],
    ];
}
