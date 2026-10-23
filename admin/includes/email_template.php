<?php
// admin/includes/email_template.php
// Shared email template functions — used by campaign_action.php and view_campaign.php
// Do not add any executable code here — functions only.

if (function_exists('bcf_email_header')) return; // prevent double-definition

// ── EMAIL TEMPLATE ───────────────────────────────────────────────────────────
// Fully inlined from batcaveroadfarm.com/css/min.style.css
// Table-based layout, hex values replacing CSS vars, no external stylesheet.

function bcf_email_header(string $subtitle = 'Newsletter'): string {
    $sub         = htmlspecialchars($subtitle, ENT_QUOTES);
    $titleSuffix = $subtitle !== '' ? ' ' . $sub : '';
    $subtitleDiv = $subtitle !== ''
        ? '<div style="font-family:Rye, Georgia, serif; font-size:14px; color:#e8b84b; text-align:center; letter-spacing:0.12em; margin-top:5px;">' . $sub . '</div>'
        : '';
    $tpl = <<<'EMAILHTML'
<!doctype html>
<html lang="en" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>Bat Cave Road Farm{{TITLE_SUFFIX}}</title>
    <link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:ital,wght@0,400;0,600;1,400&family=Caveat:wght@400;600&display=swap" rel="stylesheet">
    <!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
    <style>
        * { box-sizing: border-box; }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        body { margin: 0; padding: 0; background-color: #e8d5b0; }
        a { color: #4a6741; }
        p { margin: 0 0 16px; }
        h1, h2, h3 { margin: 0 0 16px; }
        @media screen and (max-width: 620px) {
            .email-body-wrap { width: 100% !important; }
            .footer-col-left, .footer-col-right { display: block !important; width: 100% !important; padding-right: 0 !important; padding-left: 0 !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#e8d5b0; font-family:Lora, Georgia, 'Times New Roman', serif; font-size:16px; line-height:1.6; color:#3b2010;">

<!-- Outer wrapper -->
<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background-color:#e8d5b0;">
<tr><td align="center" style="padding:20px 10px;">

<!-- Email body — max 600px -->
<table class="email-body-wrap" width="600" cellpadding="0" cellspacing="0" border="0" role="presentation"
    style="width:600px; max-width:600px; background-color:#f5ead6; box-shadow:0 4px 20px rgba(0,0,0,0.35);">

<!-- ══ HEADER ROW ══ -->
<tr>
    <td align="center" bgcolor="#3b2010"
        style="background-color:#3b2010; background-image:url('https://batcaveroadfarm.com/img/background.png'); background-size:400px auto; background-repeat:repeat; padding:22px 24px 18px;">
        <a href="https://batcaveroadfarm.com/" style="text-decoration:none; display:block;">
            <img src="https://batcaveroadfarm.com/img/logo.png" alt="Bat Cave Road Farm Logo"
                width="100" height="auto"
                style="width:100px; height:auto; display:block; margin:0 auto 12px; filter:drop-shadow(0 3px 8px rgba(0,0,0,0.7));">
        </a>
        <div style="font-family:Rye, Georgia, serif; font-size:28px; color:#f5ead6; text-align:center; line-height:1.2; text-shadow:2px 2px 6px rgba(0,0,0,0.8); letter-spacing:0.03em;">Bat Cave Road Farm</div>
        {{SUBTITLE_DIV}}
    </td>
</tr>

<!-- ══ HERO + BODY CONTENT ROW ══ -->
<tr>
    <td bgcolor="#3b2010"
        style="background-color:#3b2010; background-image:linear-gradient(135deg,#3b2010 0%,#5c3317 50%,#3b2010 100%); padding:36px 32px 40px; text-align:center;">
        <div style="font-size:72px; opacity:0.1; line-height:1; margin-bottom:18px; display:block;">&#127806;</div>
        <!-- ══ USER BODY CONTENT BEGINS ══ -->
        <div style="color:#f5ead6; font-family:Lora, Georgia, serif; font-size:16px; line-height:1.7; text-align:left;">
EMAILHTML;
    return str_replace(['{{TITLE_SUFFIX}}', '{{SUBTITLE_DIV}}'], [$titleSuffix, $subtitleDiv], $tpl);
}

function bcf_email_footer(bool $withUnsubscribe = true): string {
    $unsubRow = '';
    if ($withUnsubscribe) {
        $unsubRow = <<<'UNSUB'
<!-- ══ UNSUBSCRIBE ROW ══ -->
<tr>
    <td align="center" bgcolor="#1a0f05"
        style="background-color:#1a0f05; padding:14px 24px; border-top:1px solid rgba(255,255,255,0.08);">
        <p style="margin:0; font-family:Georgia, serif; font-size:11px; color:rgba(245,234,214,0.45); line-height:1.7;">
            You received this message because you subscribed to updates from Bat Cave Road Farm.<br>
            If you no longer wish to receive these emails,
            <a href="https://batcaveroadfarm.com/unsubscribe.php"
                style="color:rgba(245,234,214,0.65); text-decoration:underline;">click here to unsubscribe</a>.
        </p>
    </td>
</tr>
UNSUB;
    }
    $tpl = <<<'EMAILHTML'
        </div>
        <!-- ══ USER BODY CONTENT ENDS ══ -->
    </td>
</tr>

<!-- ══ DIVIDER ROW ══ -->
<tr>
    <td align="center" bgcolor="#5c3317"
        style="background-color:#5c3317; padding:12px; color:#c9923a; font-size:20px; letter-spacing:0.5em; text-align:center; font-family:Georgia, serif;">
        &#10022; &#10022; &#10022;
    </td>
</tr>

<!-- ══ FOOTER ROW ══ -->
<tr>
    <td bgcolor="#3b2010"
        style="background-color:#3b2010; border-top:4px solid #c9923a; padding:30px 24px 24px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
        <tr>

            <!-- Left col: contact info -->
            <td class="footer-col-left" width="50%" valign="top"
                style="width:50%; padding-right:20px; padding-bottom:16px; vertical-align:top;">
                <div style="font-family:Rye, Georgia, serif; font-size:18px; color:#e8b84b; margin-bottom:12px;">&#127807; Bat Cave Road Farm</div>
                <p style="color:#e8d5b0; line-height:2; font-size:14px; margin:0 0 10px;">
                    <a href="http://maps.google.com/?q=4132+Bat+Cave+Road,+Old+Fort,+North+Carolina,+28762"
                        rel="noopener" target="_blank"
                        style="color:#e8d5b0; text-decoration:underline;">4132 Bat Cave Road<br>Old Fort, NC 28762</a><br>
                    <a href="tel:8282165488" rel="noopener" target="_blank"
                        style="color:#e8d5b0; text-decoration:underline;">(828) 216-5488</a><br>
                    <a href="mailto:support@batcaveroadfarm.com"
                        style="color:#e8d5b0; text-decoration:underline;">support@batcaveroadfarm.com</a>
                </p>
                <div style="color:rgba(245,234,214,0.45); font-size:12px; margin-top:6px;">&copy; 2026 Bat Cave Road Farm &mdash; All Rights Reserved</div>
            </td>

            <!-- Right col: chalkboard social links -->
            <td class="footer-col-right" width="50%" valign="top"
                style="width:50%; padding-left:20px; padding-bottom:16px; vertical-align:top;">
                <!-- Chalkboard frame outer (wood) -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
                <tr>
                    <td style="background:linear-gradient(175deg,#7b4a1e 0%,#a0622a 18%,#6b3c14 35%,#995528 55%,#7a4520 75%,#5c2f0e 100%); border-radius:6px; padding:12px 12px 16px; border:3px solid #3d1f08;">
                        <!-- Chalkboard inner (green) -->
                        <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
                        <tr>
                            <td align="center"
                                style="background:linear-gradient(160deg,#1a2e1a 0%,#0f1f0f 50%,#111a11 100%); border-radius:2px; padding:24px 18px;">
                                <div style="font-family:Caveat, cursive; color:#f0ece0; font-size:22px; font-weight:600; letter-spacing:0.04em; margin-bottom:10px; line-height:1.2; text-shadow:0 0 6px rgba(255,255,255,0.3);">Follow us on social media!</div>
                                <div style="border-top:2px solid rgba(240,236,224,0.25); margin:10px 0;"></div>
                                <!-- Social icons side by side -->
                                <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:0 auto;">
                                <tr>
                                    <td style="padding:0 8px;">
                                        <a href="https://facebook.com/batcaveroadfarm/" target="_blank" style="text-decoration:none;">
                                            <img src="https://batcaveroadfarm.com/img/facebook.png" alt="Facebook"
                                                width="48" height="48"
                                                style="width:48px; height:48px; border-radius:10px; display:block; box-shadow:0 4px 14px rgba(0,0,0,0.25);">
                                        </a>
                                    </td>
                                    <td style="padding:0 8px;">
                                        <a href="https://instagram.com/batcaveroadfarm/" target="_blank" style="text-decoration:none;">
                                            <img src="https://batcaveroadfarm.com/img/instagram.png" alt="Instagram"
                                                width="48" height="48"
                                                style="width:48px; height:48px; border-radius:10px; display:block; box-shadow:0 4px 14px rgba(0,0,0,0.25);">
                                        </a>
                                    </td>
                                </tr>
                                </table>
                            </td>
                        </tr>
                        </table>
                    </td>
                </tr>
                </table>
            </td>

        </tr>
        </table>
    </td>
</tr>

<!-- ══ UNSUBSCRIBE ROW ══ -->
{{UNSUBSCRIBE_ROW}}

</table>
<!-- /email body -->

</td></tr>
</table>
<!-- /outer wrapper -->

</body>
</html>
EMAILHTML;
    return str_replace('{{UNSUBSCRIBE_ROW}}', $unsubRow, $tpl);
}

/**
 * Send one branded HTML+plaintext email via mail(). Single sender for the whole site.
 * $opts: subtitle (header label), unsubscribe (bool), from_name, from_email, reply_to, text.
 * Never throws; returns mail()'s result.
 */
/** True when authenticated SMTP credentials are configured (loaded by config.php). */
function bcf_smtp_available(): bool {
    return defined('BCRF_SMTP_PASS') && constant('BCRF_SMTP_PASS') !== '';
}

/** Ensure the PHPMailer class is available, trying common autoload locations. */
function bcf_phpmailer_ready(): bool {
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true;
    foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $a) {
        if (is_file($a)) { require_once $a; if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) return true; }
    }
    return false;
}

/**
 * Send one branded HTML+plaintext email. Single sender for the whole site.
 * Uses authenticated SMTP when configured (far better deliverability), and
 * falls back to PHP mail() otherwise — so dependency-free callers still work.
 * $opts: subtitle, unsubscribe (bool), from_name, from_email, reply_to, text, list_unsubscribe.
 */
function bcf_send_mail(string $to, string $subject, string $innerHtml, array $opts = []): bool {
    $subtitle    = $opts['subtitle']    ?? '';
    $unsubscribe = !empty($opts['unsubscribe']);
    $fromName    = $opts['from_name']   ?? 'Bat Cave Road Farm';
    $fromEmail   = $opts['from_email']  ?? 'no-reply@batcaveroadfarm.com';
    $replyTo     = $opts['reply_to']    ?? $fromEmail;

    $html = bcf_email_header($subtitle) . $innerHtml . bcf_email_footer($unsubscribe);

    // Newsletters: give each recipient their own signed unsubscribe link + one-click headers.
    $unsubUrl = (string)($opts['list_unsubscribe'] ?? '');
    if ($unsubscribe) {
        if (!function_exists('bcrf_unsub_url')) { @include_once dirname(__DIR__, 2) . '/includes/unsubscribe.php'; }
        if ($unsubUrl === '' && function_exists('bcrf_unsub_url')) $unsubUrl = bcrf_unsub_url($to);
        if ($unsubUrl !== '') $html = str_replace('https://batcaveroadfarm.com/unsubscribe.php"', htmlspecialchars($unsubUrl, ENT_QUOTES) . '"', $html);
    }
    $oneClick = $unsubUrl !== '' && str_contains($unsubUrl, '&t=');

    // Plain-text alternative: explicit if provided, else derived from the inner HTML.
    $text = (isset($opts['text']) && $opts['text'] !== '')
        ? (string)$opts['text']
        : trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>'], "\n", $innerHtml)), ENT_QUOTES)));

    // ── Preferred path: authenticated SMTP via PHPMailer (best deliverability) ──
    if (bcf_smtp_available() && bcf_phpmailer_ready()) {
        try {
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host       = BCRF_SMTP_HOST;
            $m->SMTPAuth   = true;
            $m->Username   = BCRF_SMTP_USER;
            $m->Password   = BCRF_SMTP_PASS;
            $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $m->Port       = BCRF_SMTP_PORT;
            $m->CharSet    = 'UTF-8';
            $m->setFrom($fromEmail, $fromName);
            $m->addAddress($to);
            if ($replyTo !== '') { $m->addReplyTo($replyTo); }
            if ($unsubUrl !== '') {
                $m->addCustomHeader('List-Unsubscribe', '<' . $unsubUrl . '>, <mailto:support@batcaveroadfarm.com?subject=unsubscribe>');
                if ($oneClick) $m->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
            $m->Subject = $subject;
            $m->isHTML(true);
            $m->Body    = $html;
            $m->AltBody = $text;
            $m->send();
            return true;
        } catch (\Throwable $e) {
            error_log('[bcf_send_mail] SMTP send failed, falling back to mail(): ' . $e->getMessage());
            // fall through to mail()
        }
    }

    // ── Fallback path: PHP mail() multipart (resilient, dependency-free) ──
    $boundary = 'bcrf_' . bin2hex(random_bytes(8));
    $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $html . "\r\n--{$boundary}--";

    $headers  = 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n";
    $headers .= 'Reply-To: ' . $replyTo . "\r\n";
    if ($unsubUrl !== '') {
        $headers .= 'List-Unsubscribe: <' . $unsubUrl . ">, <mailto:support@batcaveroadfarm.com?subject=unsubscribe>\r\n";
        if ($oneClick) $headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    }
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";

    return @mail($to, $subject, $body, $headers, '-f' . $fromEmail);
}
