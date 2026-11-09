<?php
// Adapter to the existing Farm OS SMTP sender. No worker invocation or scheduling.
// Caller must explicitly enable both the worker and this transport at launch.
function bcrf_roost_invitation_smtp_transport(array $config): callable {
    if (($config['smtp_enabled']??false)!==true)
        throw new LogicException('Roost Rewards SMTP delivery is disabled.');
    return static function (string $recipient,array $message): bool {
        if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || strlen($recipient)>254 ||
            !is_string($message['subject']??null) ||
            !is_string($message['html']??null) ||
            !is_array($message['opts']??null))
            throw new InvalidArgumentException('Invalid invitation email.');
        if (!function_exists('bcf_send_mail'))
            require_once __DIR__.'/../admin/includes/email_template.php';
        if (!function_exists('bcf_send_mail'))
            throw new RuntimeException('Farm SMTP sender unavailable.');
        // Never log or expose the invitation body, code or recipient.
        return bcf_send_mail($recipient,$message['subject'],$message['html'],[
            'subtitle'=>'Roost Rewards',
            'unsubscribe'=>false,
            'text'=>(string)($message['opts']['text']??'')
        ])===true;
    };
}
