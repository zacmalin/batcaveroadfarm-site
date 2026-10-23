<?php
// admin/handlers/pass_action.php — admin pass management actions.
require_once dirname(__DIR__, 2) . '/admin/includes/email_template.php';
require_once __DIR__ . '/../includes/audit.php';

$action  = trim((string)($_POST['pass_action'] ?? ''));
$passId  = (int)($_POST['pass_id'] ?? 0);
$pstatus = trim((string)($_GET['pstatus'] ?? 'active'));
$back    = '/admin/admin.php?view=passes&pstatus=' . rawurlencode($pstatus);

// ── SAVE / CREATE PASS TYPE ──────────────────────────────────────────────────
if ($action === 'save_type') {
    $ptId    = (int)($_POST['pt_id'] ?? 0);
    $pt      = $_POST['pt'] ?? [];
    $label   = trim(strip_tags((string)($pt['label']    ?? '')));
    $tagline = trim(strip_tags((string)($pt['tagline']  ?? '')));
    $price   = round(max(0.0, (float)($pt['price']     ?? 0)), 2);
    $months  = max(1, min(60, (int)($pt['duration_months'] ?? 12)));
    $guests  = max(1, min(20, (int)($pt['max_guests']   ?? 1)));
    $sort    = max(0, (int)($pt['sort_order']            ?? 0));
    $founding = !empty($pt['is_founding'])  ? 1 : 0;
    $active   = !empty($pt['is_active'])    ? 1 : 0;
    $hasLimit = !empty($pt['has_limit']);
    $limit    = $hasLimit ? max(1, (int)($pt['founding_qty_limit'] ?? 50)) : null;
    $rawPerks = array_values(array_filter(array_map('trim', (array)($pt['perks'] ?? [])), fn($p) => $p !== ''));
    $perksJson = !empty($rawPerks) ? json_encode($rawPerks, JSON_UNESCAPED_UNICODE) : null;

    if ($label === '') {
        $_SESSION['toast_msg']  = 'Pass title is required.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=passes&' . ($ptId ? "edit_type_id=$ptId" : 'create_type=1') . '#pass-types'); exit;
    }

    if ($ptId > 0) {
        // Update existing
        $pdo->prepare("UPDATE pass_types SET label=?,tagline=?,price=?,duration_months=?,max_guests=?,perks=?,
                        is_founding=?,founding_qty_limit=?,sort_order=?,is_active=? WHERE id=?")
            ->execute([$label,$tagline,$price,$months,$guests,$perksJson,$founding,$limit,$sort,$active,$ptId]);
        erp_audit($pdo, 'update', 'pass_types', $ptId, 'Pass type: ' . $label);
        $_SESSION['toast_msg'] = 'Pass type updated.';
    } else {
        // Create — auto-generate type_key from label
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label));
        $key = trim($key, '_');
        // Deduplicate if needed
        $existing = (int)$pdo->prepare("SELECT COUNT(*) FROM pass_types WHERE type_key=?")->execute([$key]) ? (int)$pdo->query("SELECT COUNT(*) FROM pass_types WHERE type_key='$key'")->fetchColumn() : 0;
        if ($existing) $key .= '_' . time();
        $pdo->prepare("INSERT INTO pass_types (type_key,label,tagline,price,duration_months,max_guests,perks,is_founding,founding_qty_limit,sort_order,is_active)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$key,$label,$tagline,$price,$months,$guests,$perksJson,$founding,$limit,$sort,$active]);
        erp_audit($pdo, 'create', 'pass_types', (int)$pdo->lastInsertId(), 'Pass type: ' . $label);
        $_SESSION['toast_msg'] = 'Pass type created.';
    }
    $_SESSION['toast_type'] = 'success';
    header('Location: /admin/admin.php?view=passes#pass-types'); exit;
}

// ── ISSUE (in-person / manual) ───────────────────────────────────────────────
if ($action === 'issue') {
    $ptId    = (int)($_POST['pt_id'] ?? 0);
    $name    = trim(strip_tags((string)($_POST['holder_name'] ?? '')));
    $email   = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $phone   = preg_replace('/[^0-9+\-() ]/', '', (string)($_POST['phone'] ?? ''));
    $prov    = in_array($_POST['payment_provider'] ?? '', ['cash','comp'], true) ? $_POST['payment_provider'] : 'cash';
    $paid    = max(0.0, (float)($_POST['amount_paid'] ?? 0));

    if (!$ptId || !$name || !$email) {
        $_SESSION['toast_msg']  = 'Name, email, and pass type are required.';
        $_SESSION['toast_type'] = 'error';
        header('Location: ' . $back); exit;
    }

    $pt = null;
    try { $st = $pdo->prepare("SELECT * FROM pass_types WHERE id=? LIMIT 1"); $st->execute([$ptId]); $pt = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
    if (!$pt) { $_SESSION['toast_msg'] = 'Pass type not found.'; $_SESSION['toast_type'] = 'error'; header('Location: '.$back); exit; }

    $token = bin2hex(random_bytes(24));
    try {
        $pdo->prepare("INSERT INTO passes
            (pass_type_id, holder_name, email, phone, token, valid_from, valid_until,
             status, payment_status, payment_provider, amount_paid)
            VALUES (?,?,?,?,?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL ? MONTH),
             'active','paid',?,?)")->execute([
            $ptId, $name, $email, $phone ?: null, $token,
            (int)($pt['duration_months'] ?? 12), $prov, $paid > 0 ? $paid : (float)$pt['price'],
        ]);
        $newPassId = (int)$pdo->lastInsertId();
        erp_audit($pdo, 'create', 'passes', $newPassId, $name . ' - ' . ($pt['label'] ?? 'pass') . ' issued (' . $prov . ')');
    } catch (Throwable $e) {
        error_log('[BCRF pass_action issue] ' . $e->getMessage());
        $_SESSION['toast_msg']  = 'Error creating pass: ' . $e->getMessage();
        $_SESSION['toast_type'] = 'error';
        header('Location: ' . $back); exit;
    }

    // Send welcome email
    if (function_exists('bcf_send_mail')) {
        $verifyUrl = 'https://batcaveroadfarm.com/passes/verify.php?token=' . rawurlencode($token);
        $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:1.3rem;margin:0 0 14px">Your annual pass is active! 🎟</h1>'
            . '<p style="margin:0 0 12px">Hi ' . htmlspecialchars($name) . ', your <strong>' . htmlspecialchars($pt['label']) . '</strong> is ready to use.</p>'
            . '<p style="margin:0 0 12px">Valid until <strong>' . date('F j, Y', strtotime('+' . max(1, (int)($pt['duration_months'] ?? 12)) . ' months')) . '</strong> — show the link below to staff at the gate.</p>'
            . '<p style="margin:0 0 18px;text-align:center"><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px">View My Pass →</a></p>'
            . '<p style="font-size:.85rem;color:rgba(245,234,214,.8)">Bookmark this link or add it to your phone\'s home screen for instant gate access. We can\'t wait to see you at the farm!</p>';
        @bcf_send_mail($email, 'Your Bat Cave Road Farm annual pass is active!', $inner, ['subtitle' => 'Annual Pass']);
    }

    $_SESSION['toast_msg']  = 'Pass issued and email sent to ' . $email . '.';
    $_SESSION['toast_type'] = 'success';
    header('Location: ' . $back); exit;
}

// ── STATUS UPDATE ────────────────────────────────────────────────────────────
if ($action === 'status' && $passId > 0) {
    $new = trim((string)($_POST['new_status'] ?? ''));
    $allowed = ['active','suspended','cancelled','expired'];
    if (in_array($new, $allowed, true)) {
        $oldStatus = '';
        try { $os = $pdo->prepare("SELECT status FROM passes WHERE id=?"); $os->execute([$passId]); $oldStatus = (string)$os->fetchColumn(); } catch (Throwable $e) {}
        $pdo->prepare("UPDATE passes SET status=? WHERE id=?")->execute([$new, $passId]);
        erp_audit($pdo, 'update', 'passes', $passId, erp_row_label($pdo, 'passes', $passId) . ' - ' . ($oldStatus ?: '?') . ' -> ' . $new);
        $_SESSION['toast_msg']  = 'Pass status updated to ' . $new . '.';
        $_SESSION['toast_type'] = 'success';
    }
    header('Location: ' . $back); exit;
}

// ── EXTEND by 1 year ────────────────────────────────────────────────────────
if ($action === 'extend' && $passId > 0) {
    $pdo->prepare("UPDATE passes SET valid_until = DATE_ADD(valid_until, INTERVAL 1 YEAR) WHERE id=?")
        ->execute([$passId]);
    erp_audit($pdo, 'update', 'passes', $passId, erp_row_label($pdo, 'passes', $passId) . ' - extended 1 year');
    $_SESSION['toast_msg']  = 'Pass extended by 1 year.';
    $_SESSION['toast_type'] = 'success';
    header('Location: ' . $back); exit;
}

header('Location: ' . $back); exit;
