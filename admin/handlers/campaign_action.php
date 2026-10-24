<?php
// admin/handlers/campaign_action.php
// Handles email campaign sends — both test and live blasts.

ob_start();
header('Content-Type: text/html; charset=utf-8');

require_once '../../config.php';
require_once __DIR__ . '/../includes/audit.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();   // config.php usually started it already (fix pack O)
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['administrator', 'moderator'])) {
    $_SESSION['toast_msg']  = 'Access denied.';
    $_SESSION['toast_type'] = 'error';
    header('Location: /admin/admin.php?view=newsletter'); exit;
}

date_default_timezone_set('America/New_York');

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['toast_msg']  = 'Security token expired. Please try again.';
    $_SESSION['toast_type'] = 'error';
    header('Location: /admin/admin.php?view=newsletter'); exit;
}

// ── RESPONSE MODE ────────────────────────────────────────────────────────────
$is_ajax = !empty($_POST['ajax']);

function ajax_exit(bool $ok, string $message): never {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

function redirect_exit(string $msg, string $type): never {
    ob_end_clean();
    $_SESSION['toast_msg']  = $msg;
    $_SESSION['toast_type'] = $type;
    header('Location: /admin/admin.php?view=newsletter');
    exit;
}

// ── VALIDATE INPUTS ───────────────────────────────────────────────────────────
$subject    = trim($_POST['subject']    ?? '');
$from_name  = trim($_POST['from_name']  ?? 'Bat Cave Road Farm');
$reply_to   = trim($_POST['reply_to']   ?? '');
$segment    = trim($_POST['segment']    ?? 'all');
$body_html  = trim($_POST['body_html']  ?? '');
$is_test    = (int)($_POST['is_test']   ?? 0) === 1;
$test_email = trim($_POST['test_email'] ?? '');

if (empty($subject) || empty($body_html)) {
    $is_ajax ? ajax_exit(false, 'Subject and body are required.') : redirect_exit('Subject and body are required.', 'error');
}

if (!in_array($segment, ['all', 'nursery', 'inventory'])) {
    $segment = 'all';
}

if ($is_test && empty($test_email)) {
    $is_ajax ? ajax_exit(false, 'Enter a test email address.') : redirect_exit('Enter a test email address.', 'error');
}

if ($is_test && !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
    $is_ajax ? ajax_exit(false, 'Invalid test email address.') : redirect_exit('Invalid test email address.', 'error');
}

// ── GET RECIPIENTS ────────────────────────────────────────────────────────────
$recipients = [];

if ($is_test) {
    $recipients[] = ['email' => $test_email, 'first_name' => 'Subscriber'];
} else {
    try {
        $where_extra = match($segment) {
            'nursery'   => 'AND track_nursery_updates  = 1',
            'inventory' => 'AND track_inventory_alerts = 1',
            default     => '',
        };

        $stmt = $pdo->query("
            SELECT * FROM mailing_list
            WHERE  is_subscribed = 1 {$where_extra}
        ");
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Use null coalescing so missing columns never cause SQL errors
        foreach ($raw as $row) {
            $recipients[] = [
                'email'      => $row['email'] ?? '',
                'first_name' => $row['first_name'] ?? 'Subscriber',
            ];
        }
    } catch (Throwable $e) {
        error_log('[ERP] Campaign: recipient query failed: ' . $e->getMessage());
        $_SESSION['toast_msg']  = 'Could not load subscriber list: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=newsletter'); exit;
    }
}

if (empty($recipients)) {
    $is_ajax ? ajax_exit(false, 'No recipients found for this segment.') : redirect_exit('No recipients found for this segment.', 'error');
}

// ── LOG CAMPAIGN BEFORE SENDING ───────────────────────────────────────────────
// Log first so we always have a record even if some sends fail
$campaign_id     = null;
$recipient_count = count($recipients);

try {
    $ins = $pdo->prepare("
        INSERT INTO campaigns (subject, segment, from_name, reply_to, body, recipient_count, is_test, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'sent')
    ");
    $ins->execute([$subject, $segment, $from_name, $reply_to, $body_html, $recipient_count, $is_test ? 1 : 0]);
    $campaign_id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    error_log('[ERP] Campaign: log insert failed: ' . $e->getMessage());
    // Surface the error so it's visible — don't silently continue
    $is_ajax ? ajax_exit(false, 'Could not log campaign: ' . $e->getMessage())
             : redirect_exit('Could not log campaign: ' . $e->getMessage(), 'error');
}

// ── EMAIL TEMPLATE ──────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/email_template.php';
require_once __DIR__ . '/../../includes/unsubscribe.php';



// ── PHPMAILER BOOTSTRAP ──────────────────────────────────────────────────────
// Tries common PHPMailer install locations — composer vendor, direct include paths
$phpmailer_loaded = false;
$doc_root         = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$phpmailer_paths  = [
    $doc_root . '/vendor/autoload.php',                             // composer at web root
    $doc_root . '/../vendor/autoload.php',                          // composer one above web root
    $doc_root . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',    // manual at web root
    __DIR__ . '/../../vendor/autoload.php',                         // relative from handler
    __DIR__ . '/../../../vendor/autoload.php',                      // one level higher
];
foreach ($phpmailer_paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        // If it's the composer autoloader it handles the rest
        // If it's the PHPMailer.php directly, load the other required files too
        if (str_ends_with($p, 'PHPMailer.php')) {
            $dir = dirname($p);
            require_once $dir . '/Exception.php';
            require_once $dir . '/SMTP.php';
        }
        $phpmailer_loaded = true;
        break;
    }
}

if (!$phpmailer_loaded) {
    error_log('[ERP] Campaign: PHPMailer not found. Checked: ' . implode(', ', $phpmailer_paths));
    $_SESSION['toast_msg']  = 'PHPMailer not found — check server configuration.';
    $_SESSION['toast_type'] = 'error';
    ob_end_clean();
    header('Location: /admin/admin.php?view=newsletter'); exit;
}

// ── SEND EMAILS ───────────────────────────────────────────────────────────────
function sendEmail(string $to, string $toName, string $subject, string $htmlBody, string $fromName, string $replyTo, string $unsubUrl = ''): bool {
    $from_email = 'noreply@batcaveroadfarm.com';

    // Personalise any {{first_name}} tokens in the body
    $personalised = str_replace(
        ['{{first_name}}', '{{name}}'],
        [htmlspecialchars($toName), htmlspecialchars($toName)],
        $htmlBody
    );

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // Use isMail() — most reliable on Bluehost shared hosting.
        // Routes through PHP's own mail system rather than SMTP,
        // which avoids port 25 blocks common on shared servers.
        $mail->isMail();

        $mail->setFrom($from_email, $fromName);
        if (!empty($replyTo)) $mail->addReplyTo($replyTo, $fromName);
        $mail->addAddress($to, $toName);

        $mail->isHTML(true);
        $mail->CharSet  = 'UTF-8';
        $mail->Subject  = $subject;
        $footer = bcf_email_footer();
        if ($unsubUrl !== '') {
            // Personal signed unsubscribe link + one-click headers (Gmail / Apple Mail button)
            $footer = str_replace('https://batcaveroadfarm.com/unsubscribe.php"', htmlspecialchars($unsubUrl, ENT_QUOTES) . '"', $footer);
            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubUrl . '>, <mailto:support@batcaveroadfarm.com?subject=unsubscribe>');
            if (str_contains($unsubUrl, '&t=')) $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }
        $mail->Body     = bcf_email_header() . $personalised . $footer;
        $mail->AltBody  = strip_tags(html_entity_decode(strip_tags($personalised)))
                        . ($unsubUrl !== '' ? "\n\n---\nUnsubscribe: " . $unsubUrl : '');

        $mail->send();
        return true;
    } catch (Throwable $e) {
        // Log the full PHPMailer error so it's visible in cPanel error log
        error_log('[ERP] Campaign: PHPMailer failed to ' . $to . ' — ' . $e->getMessage());
        if (isset($mail)) error_log('[ERP] Campaign: PHPMailer error info — ' . $mail->ErrorInfo);
        return false;
    }
}

$sent_count   = 0;
$failed_count = 0;

foreach ($recipients as $recipient) {
    if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
        $failed_count++;
        continue;
    }

    $ok = sendEmail(
        $recipient['email'],
        $recipient['first_name'],
        $subject,
        $body_html,
        $from_name,
        $reply_to,
        bcrf_unsub_url($recipient['email'])
    );

    if ($ok) $sent_count++;
    else     $failed_count++;

    // Brief pause between sends to avoid hitting rate limits on shared hosting
    if (!$is_test) usleep(100000); // 0.1s
}

// ── UPDATE STATUS ─────────────────────────────────────────────────────────────
if ($campaign_id) {
    $final_status = match(true) {
        $failed_count === 0                      => 'sent',
        $sent_count   === 0                      => 'failed',
        default                                  => 'partial',
    };
    try {
        $pdo->prepare("UPDATE campaigns SET recipient_count = ?, status = ? WHERE id = ?")
            ->execute([$sent_count, $final_status, $campaign_id]);
    } catch (Throwable $e) {
        error_log('[ERP] Campaign: status update failed: ' . $e->getMessage());
    }
}

// ── AUDIT ──
if ($campaign_id) {
    erp_audit($pdo, 'create', 'campaigns', $campaign_id,
        ($is_test ? 'TEST to ' . $test_email . ': ' : 'Sent (' . $segment . '): ') . $subject
        . ' - ' . $sent_count . ' delivered' . ($failed_count ? ', ' . $failed_count . ' failed' : ''));
}

// ── RESULT ────────────────────────────────────────────────────────────────────
if ($is_test) {
    $msg = "Test email sent to {$test_email}.";
    $is_ajax ? ajax_exit(true, $msg) : redirect_exit($msg, 'success');
} elseif ($failed_count === 0) {
    $msg = "Campaign sent to {$sent_count} subscribers.";
    $is_ajax ? ajax_exit(true, $msg) : redirect_exit($msg, 'success');
} elseif ($sent_count === 0) {
    $msg = "Send failed — no emails delivered. Check error log for PHPMailer details.";
    $is_ajax ? ajax_exit(false, $msg) : redirect_exit($msg, 'error');
} else {
    $msg = "Partial send: {$sent_count} delivered, {$failed_count} failed.";
    $is_ajax ? ajax_exit(false, $msg) : redirect_exit($msg, 'error');
}
