<?php
// admin/handlers/jobs_emails.php
// Saves for Hiring > Emails: templates (save, reset, test send) and the
// approval queue (send, edit, cancel, bulk). Administrators only.
// Runs inside admin.php, so $pdo, $current_role and the session are in scope.

require_once __DIR__ . '/../../includes/jobs/emails.php';
require_once __DIR__ . '/../includes/audit.php';

$__je_back = (string)($_POST['back'] ?? '');
if (!preg_match('#^view=jobs(_emails)?(&[A-Za-z0-9_%=&.\-+]*)?(\#[a-z0-9\-]+)?$#', $__je_back)) $__je_back = 'view=jobs_emails';

$__je_go = function (string $msg, string $type = 'success', ?string $qs = null) use ($__je_back) {
    $_SESSION['toast_msg']  = $msg;
    $_SESSION['toast_type'] = $type;
    header('Location: /admin/admin.php?' . ($qs ?? $__je_back));
    exit;
};

if (($current_role ?? '') !== 'administrator') $__je_go('Administrator access required.', 'error', 'view=dashboard');
if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    $__je_go('Security token expired. Please try again.', 'error');
}

$act = (string)($_POST['jobs_emails_action'] ?? '');
$key = (string)($_POST['tpl_key'] ?? '');
$qid = (int)($_POST['qid'] ?? 0);
$cat = jobs_template_catalog();

switch ($act) {

    // ── Templates ───────────────────────────────────────────────────────────
    case 'tpl_save': {
        if (!isset($cat[$key])) $__je_go('Unknown template.', 'error');
        [$ok, $msg] = jobs_template_save($pdo, $key, (string)($_POST['subject'] ?? ''), (string)($_POST['body_html'] ?? ''), !empty($_POST['is_active']));
        if (!$ok) {
            $_SESSION['jobs_form_old']['tpl_' . $key] = ['subject' => (string)($_POST['subject'] ?? ''), 'body_html' => (string)($_POST['body_html'] ?? ''), 'is_active' => !empty($_POST['is_active'])];
            $__je_go($msg, 'error', 'view=jobs_emails&tab=templates&tpl=' . rawurlencode($key));
        }
        erp_audit($pdo, 'update', 'email_templates', null, 'Edited email template: ' . $key);
        if (!empty($_POST['then_test'])) {
            $t = jobs_template($pdo, $key) ?: null;
            $me = (function () use ($pdo) { $st = $pdo->prepare('SELECT email FROM users WHERE id = ?'); $st->execute([(int)($_SESSION['user_id'] ?? 0)]); return (string)$st->fetchColumn(); })();
            if (!$t) $__je_go('Saved, but it\'s turned off, so no test was sent.', 'success', 'view=jobs_emails&tab=templates&tpl=' . rawurlencode($key));
            $sent = $me !== '' && jobs_template_test_send($pdo, $t, $me);
            $__je_go($sent ? 'Saved and sent a test to ' . $me . '.' : 'Saved, but the test email didn\'t send. Check Hiring > Emails > Sent log.', $sent ? 'success' : 'error',
                'view=jobs_emails&tab=templates&tpl=' . rawurlencode($key));
        }
        $__je_go('Saved "' . ($pdo->query('SELECT name FROM email_templates WHERE tpl_key = ' . $pdo->quote($key))->fetchColumn() ?: $key) . '".', 'success', 'view=jobs_emails&tab=templates&tpl=' . rawurlencode($key));
    }
    case 'tpl_reset': {
        if (!isset($cat[$key]) || !jobs_template_reset($pdo, $key)) $__je_go('Couldn\'t reset that template.', 'error');
        erp_audit($pdo, 'update', 'email_templates', null, 'Reset email template to default: ' . $key);
        $__je_go('Put back the original wording (and turned it on).', 'success', 'view=jobs_emails&tab=templates&tpl=' . rawurlencode($key));
    }
    case 'tpl_test': {
        $st = $pdo->prepare('SELECT * FROM email_templates WHERE tpl_key = ?'); $st->execute([$key]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) $__je_go('Unknown template.', 'error');
        $st = $pdo->prepare('SELECT email FROM users WHERE id = ?'); $st->execute([(int)($_SESSION['user_id'] ?? 0)]);
        $me = (string)$st->fetchColumn();
        if ($me === '') $__je_go('Couldn\'t find your email address.', 'error');
        $ok = jobs_template_test_send($pdo, $t, $me);
        $__je_go($ok ? 'Test sent to ' . $me . ' (with sample details).' : 'The test email didn\'t send. See the Sent log for the reason.', $ok ? 'success' : 'error',
            'view=jobs_emails&tab=templates&tpl=' . rawurlencode($key));
    }

    // ── Queue ───────────────────────────────────────────────────────────────
    case 'q_send': {
        [$ok, $msg] = jobs_queue_send($pdo, $qid);
        $__je_go($msg, $ok ? 'success' : 'error');
    }
    case 'q_cancel': {
        $__je_go(jobs_queue_cancel($pdo, $qid) ? 'Cancelled. Nothing was sent.' : 'That email was already sent or cancelled.');
    }
    case 'q_edit': {
        [$ok, $msg] = jobs_queue_edit($pdo, $qid, (string)($_POST['subject'] ?? ''), (string)($_POST['body_html'] ?? ''));
        if (!$ok) $__je_go($msg, 'error', 'view=jobs_emails&tab=queue&edit=' . $qid . '#q' . $qid);
        $__je_go($msg, 'success', 'view=jobs_emails&tab=queue#q' . $qid);
    }
    case 'q_bulk': {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['qids'] ?? [])), fn($v) => $v > 0)));
        $do  = (string)($_POST['bulk_do'] ?? '');
        if (!$ids) $__je_go('Tick at least one email first.', 'error');
        if ($do === 'cancel') {
            $n = 0; foreach ($ids as $i) if (jobs_queue_cancel($pdo, $i)) $n++;
            $__je_go('Cancelled ' . $n . ' email' . ($n === 1 ? '' : 's') . '. Nothing was sent.');
        }
        if ($do !== 'send') $__je_go('Pick Send or Cancel.', 'error');
        // A batch at a time keeps each tap well under the server's time limit.
        $max = 25; $sent = 0; $fail = [];
        @set_time_limit(120);
        foreach (array_slice($ids, 0, $max) as $i) {
            [$ok, $msg] = jobs_queue_send($pdo, $i);
            if ($ok) $sent++; else $fail[] = $msg;
        }
        $left = max(0, count($ids) - $max);
        $msg  = 'Sent ' . $sent . ' email' . ($sent === 1 ? '' : 's') . '.';
        if ($fail) $msg .= ' ' . count($fail) . ' not sent: ' . implode(' ', array_slice($fail, 0, 3)) . (count($fail) > 3 ? '…' : '');
        if ($left) $msg .= ' ' . $left . ' more are still waiting — tick and send again.';
        $__je_go($msg, $fail && !$sent ? 'error' : 'success');
    }

    default:
        $__je_go('Unknown action.', 'error');
}
