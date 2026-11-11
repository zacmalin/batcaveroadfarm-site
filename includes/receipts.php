<?php
// includes/receipts.php — register settings, discount rules and emailed receipts (Farm OS Phase 1, slice 9, Nov 6, 2026; 05 › POS, Tenders, Receipts)
//   Settings (site_settings, edited in Register › Settings by administrators):
//     p2p_paypal, p2p_venmo, p2p_cashapp   the farm's handles, shown with a QR code when staff take that payment
//     receipt_return_policy                printed and emailed at the bottom of every receipt
//     pos_discount_presets                 the % buttons anyone can use (default 5,10,15,20,25,50)
//   Discounts (05: "Preset discounts; manual price or discount changes need pos.discount.override"): a preset % is open to
//     every register user; any other %, including Free (100%), needs an Owner or Manager, or "POS Discount Override" in the
//     Permissions Matrix. The server checks this when the sale is saved.
//   Receipts: bcrf_receipt_email() sends the receipt (same content as the printed one) and marks the sale as emailed.
// No migration: settings live in site_settings; sales.receipt_delivery already exists (0009).
// Nov 6, 2026: pos_tenders = the payment types the register offers, in button order (comma list of codes from
//   bcrf_tenders()). Missing or empty = all of them in the default order. Edited in Settings › Payments & Register.
//   Turning one off only hides it for new sales; past sales, reports and refunds still know it.
if (!function_exists('bcrf_pos_settings')) {

function bcrf_pos_setting_keys(): array {
    return ['p2p_paypal', 'p2p_venmo', 'p2p_cashapp', 'receipt_return_policy', 'pos_discount_presets'];
}

/** Current register settings, read fresh (not the per-request site_info cache). */
function bcrf_pos_settings(PDO $pdo): array {
    $v = array_fill_keys(bcrf_pos_setting_keys(), '');
    try {
        $in = implode(',', array_fill(0, count($v), '?'));
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($in)");
        $st->execute(array_keys($v));
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $val) $v[$k] = (string)$val;
    } catch (Throwable $e) {}
    return [
        'handles' => ['paypal' => $v['p2p_paypal'], 'venmo' => $v['p2p_venmo'], 'cashapp' => $v['p2p_cashapp']],
        'policy'  => $v['receipt_return_policy'],
        'presets' => bcrf_parse_presets($v['pos_discount_presets']),
    ];
}

/** "5, 10,15" -> [5, 10, 15]: whole percents 1-99, unique, sorted, at most 8. Empty -> the defaults. */
function bcrf_parse_presets(string $s): array {
    $out = [];
    foreach (preg_split('/[\s,;%]+/', $s) as $p) {
        if ($p === '' || !ctype_digit($p)) continue;
        $n = (int)$p;
        if ($n >= 1 && $n <= 99) $out[$n] = $n;
    }
    ksort($out);
    $out = array_values(array_slice($out, 0, 8));
    return $out ?: [5, 10, 15, 20, 25, 50];
}

/** Clean a PayPal/Venmo/Cash App handle: no leading @ or $, letters, digits, dot, dash, underscore. */
function bcrf_clean_handle(string $h): string {
    $h = ltrim(trim($h), '@$');
    if ($h !== '' && !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $h)) throw new InvalidArgumentException('A handle can only have letters, numbers, dots, dashes and underscores (no spaces).');
    return $h;
}

/** Link a customer opens to pay the farm (what the QR code holds). PayPal and Cash App links carry the amount. */
function bcrf_p2p_link(string $tender, string $handle, int $cents = 0): string {
    if ($handle === '') return '';
    $amt = $cents > 0 ? '/' . number_format($cents / 100, 2, '.', '') : '';
    switch ($tender) {
        case 'paypal':  return 'https://paypal.me/' . rawurlencode($handle) . $amt;
        case 'cashapp': return 'https://cash.app/$' . rawurlencode($handle) . $amt;
        case 'venmo':   return 'https://venmo.com/u/' . rawurlencode($handle);
    }
    return '';
}

function bcrf_pos_settings_save(PDO $pdo, array $in, ?int $uid): array {
    $old = bcrf_pos_settings($pdo);
    $new = [
        'p2p_paypal'  => bcrf_clean_handle((string)($in['p2p_paypal'] ?? '')),
        'p2p_venmo'   => bcrf_clean_handle((string)($in['p2p_venmo'] ?? '')),
        'p2p_cashapp' => bcrf_clean_handle((string)($in['p2p_cashapp'] ?? '')),
        'receipt_return_policy' => trim(preg_replace('/[ \t]+/', ' ', str_replace("\r", '', (string)($in['receipt_return_policy'] ?? '')))),
        'pos_discount_presets' => implode(',', bcrf_parse_presets((string)($in['pos_discount_presets'] ?? ''))),
    ];
    if (mb_strlen($new['receipt_return_policy']) > 500) throw new InvalidArgumentException('Keep the return policy under 500 characters.');
    $st = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($new as $k => $v) $st->execute([$k, $v]);
    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$t, $aid, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => 'update',
            'object_type' => 'site_settings', 'object_id' => 'register', 'object_label' => 'Register settings',
            'before' => ['handles' => $old['handles'], 'policy' => $old['policy'], 'presets' => $old['presets']],
            'after' => ['handles' => ['paypal' => $new['p2p_paypal'], 'venmo' => $new['p2p_venmo'], 'cashapp' => $new['p2p_cashapp']],
                        'policy' => $new['receipt_return_policy'], 'presets' => bcrf_parse_presets($new['pos_discount_presets'])]]);
    }
    return bcrf_pos_settings($pdo);
}

// -- Payment types on the register --

/** Payment types the register offers for new sales, code => label, in button order. */
function bcrf_tenders_active(PDO $pdo): array {
    $all = bcrf_tenders();
    $raw = '';
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'pos_tenders'");
        $st->execute();
        $raw = (string)$st->fetchColumn();
    } catch (Throwable $e) {}
    if (trim($raw) === '') return $all;
    $out = [];
    foreach (explode(',', $raw) as $c) { $c = trim($c); if (isset($all[$c])) $out[$c] = $all[$c]; }
    return $out ?: $all;
}

/** Save which types are on and their order. $on = codes ticked; $order = code => position number. */
function bcrf_tenders_save(PDO $pdo, array $on, array $order): array {
    $all = bcrf_tenders();
    $before = array_keys(bcrf_tenders_active($pdo));
    $pos = []; $i = 0;
    foreach ($all as $c => $label) { $i++; $pos[$c] = [is_numeric($order[$c] ?? null) ? (float)$order[$c] : 99, $i]; }
    $codes = array_keys($all);
    usort($codes, fn($a, $b) => $pos[$a] <=> $pos[$b]);
    $codes = array_values(array_filter($codes, fn($c) => in_array($c, $on, true)));
    if (!$codes) throw new InvalidArgumentException('Leave at least one payment type turned on.');
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('pos_tenders', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([implode(',', $codes)]);
    if ($codes !== $before && function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$t, $aid, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => 'update',
            'object_type' => 'site_settings', 'object_id' => 'pos_tenders', 'object_label' => 'Register payment types',
            'before' => ['tenders' => $before], 'after' => ['tenders' => $codes]]);
    }
    return bcrf_tenders_active($pdo);
}

// -- Discounts --

/** Owner or Manager, or "POS Discount Override" ticked in the Permissions Matrix. */
function bcrf_can_override_discount(): bool {
    $role = (string)($_SESSION['user_role'] ?? '');
    if (in_array($role, ['administrator', 'moderator'], true)) return true;
    return $role !== '' && function_exists('hasViewAccess') && hasViewAccess($role, 'pos_discount_override');
}

/**
 * Refuse discounts that aren't presets unless this user may override. $saleBp = whole-sale discount in basis points,
 * $lineBps = item discounts in basis points. Returns null when allowed, or the message to show.
 */
function bcrf_discount_problem(PDO $pdo, int $saleBp, array $lineBps): ?string {
    if (bcrf_can_override_discount()) return null;
    $ok = array_map(fn($p) => $p * 100, bcrf_pos_settings($pdo)['presets']);
    foreach (array_merge([$saleBp], $lineBps) as $bp) {
        $bp = (int)$bp;
        if ($bp !== 0 && !in_array($bp, $ok, true)) {
            return 'A ' . rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') . '% discount needs an Owner or Manager. Use one of the % buttons ('
                 . implode(', ', array_map(fn($p) => $p . '%', bcrf_pos_settings($pdo)['presets'])) . ') or ask them.';
        }
    }
    return null;
}

// -- Emailed receipts --

/** The receipt as email HTML: same content as the printed one. */
function bcrf_receipt_html(PDO $pdo, array $s): string {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $m = fn($c) => ((int)$c < 0 ? '-$' : '$') . number_format(abs((int)$c) / 100, 2);
    $q = fn($x) => rtrim(rtrim(number_format((float)$x, 3, '.', ''), '0'), '.');
    $tenders = bcrf_tenders();
    $row = fn(string $a, string $b, bool $bold = false) => '<tr><td style="padding:3px 0;' . ($bold ? 'font-weight:bold;' : '') . '">' . $a
        . '</td><td style="padding:3px 0;text-align:right;white-space:nowrap;' . ($bold ? 'font-weight:bold;' : '') . '">' . $b . '</td></tr>';
    $small = fn(string $t) => '<span style="color:#78716c;font-size:13px;">' . $t . '</span>';
    $o = '<p style="margin:0 0 4px;"><b>Receipt ' . $h($s['sale_number']) . '</b></p><p style="margin:0 0 12px;color:#57534e;">' . $h($s['completed_local'])
       . ($s['customer_name'] ? ' · ' . $h($s['customer_name']) : '') . '</p>';
    if (($s['status'] ?? '') === 'voided') $o .= '<p style="color:#b91c1c;font-weight:bold;">This sale was voided.</p>';
    $o .= '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:15px;">';
    $thrift = false; $itemOff = 0;
    foreach ($s['lines'] as $l) {
        if (stripos((string)$l['category_label'], 'thrift') !== false) $thrift = true;
        $each = $l['unit'] === 'each';
        $o .= $row($h($l['description']), $m($l['gross_cents']));
        if ((float)$l['quantity'] != 1 || !$each) $o .= $row($small('&nbsp;&nbsp;' . $h($q($l['quantity'])) . ($each ? ' × ' : ' ' . $h($l['unit']) . ' × ') . $m($l['unit_price_cents'])), '');
        if ($bp = (int)($l['line_discount_bp'] ?? 0)) {
            $off = bcrf_muldiv((int)$l['gross_cents'], $bp, 10000); $itemOff += $off;
            $o .= $row($small('&nbsp;&nbsp;' . $h($bp / 100) . '% off'), $m(-$off));
        }
        if (!empty($l['note'])) $o .= $row($small('&nbsp;&nbsp;' . $h($l['note'])), '');
    }
    $o .= '</table><hr style="border:0;border-top:1px solid #e7e5e4;margin:10px 0;"><table role="presentation" style="width:100%;border-collapse:collapse;font-size:15px;">';
    $o .= $row('Subtotal', $m($s['subtotal_cents']));
    if ($itemOff) $o .= $row('Item discounts', $m(-$itemOff));
    if ($s['discount_cents'] - $itemOff) $o .= $row('Discount (' . $h($s['discount_bp'] / 100) . '%)', $m(-($s['discount_cents'] - $itemOff)));
    if ($s['tax_exempt']) $o .= $row('Tax (exempt)', $m(0));
    else foreach ($s['tax_by_rate'] as $r => $c) if ($r > 0) $o .= $row('Tax ' . $h($r / 100) . '%', $m($c));
    $o .= $row('Total', $m($s['total_cents']), true) . '</table><hr style="border:0;border-top:1px solid #e7e5e4;margin:10px 0;"><table role="presentation" style="width:100%;border-collapse:collapse;font-size:15px;">';
    foreach ($s['payments'] as $p) {
        $o .= $row($h($tenders[$p['tender']] ?? $p['tender']) . (!empty($p['gift_card']) ? ' ' . $h($p['gift_card']['masked']) : ''), $m($p['amount_cents']));
        if (!empty($p['gift_card'])) $o .= $row($small('&nbsp;&nbsp;Left on card'), $m($p['gift_card']['balance_after_cents']));   // gift cards (Nov 11)
        if ($p['tender'] === 'cash' && (int)$p['change_cents'] > 0) $o .= $row($small('&nbsp;&nbsp;Cash given'), $m($p['tendered_cents'])) . $row($small('&nbsp;&nbsp;Change'), $m($p['change_cents']));
    }
    foreach (($s['refunds'] ?? []) as $r) $o .= $row('Refund ' . $h($r['refund_number']) . ' ' . $h($r['when'] ?? ''), $m(-(int)$r['total_cents']));
    $o .= '</table>';
    if (!empty($s['gift_cards'])) {   // gift cards sold on this sale (Nov 11): the buyer keeps the code
        $o .= '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:15px;margin-top:10px;">';
        foreach ($s['gift_cards'] as $c) $o .= $row('Gift card <b>' . $h($c['code_fmt']) . '</b>' . ($c['status'] === 'void' ? ' (cancelled)' : ''), $m($c['initial_cents']));
        $o .= '</table><p style="color:#57534e;font-size:13px;">Gift cards never expire. Treat the code like cash.</p>';
    }
    if ($thrift) $o .= '<p style="color:#57534e;font-size:13px;">Thrift shop items are sold as-is.</p>';
    $policy = bcrf_pos_settings($pdo)['policy'];
    if ($policy !== '') $o .= '<p style="color:#57534e;font-size:13px;">' . nl2br($h($policy)) . '</p>';
    $addr = function_exists('site_address') ? (string)site_address() : '';
    $o .= '<p style="color:#57534e;font-size:13px;">' . $h(function_exists('bcrf_farm_name') ? bcrf_farm_name() : 'Bat Cave Road Farm') . ($addr !== '' ? '<br>' . $h($addr) : '')
        . (function_exists('site_phone_display') && site_phone_display() !== '' ? '<br>' . $h(site_phone_display()) : '') . '</p>';
    return $o;
}

/** Email a sale's receipt. Returns the address used. Throws InvalidArgumentException with a message staff can read. */
function bcrf_receipt_email(PDO $pdo, int $saleId, string $email): string {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) throw new InvalidArgumentException('Check the email address.');
    $s = function_exists('bcrf_sale_detail') ? bcrf_sale_detail($pdo, $saleId) : bcrf_sale_get($pdo, $saleId);
    if (!$s) throw new InvalidArgumentException('Sale not found.');
    // At most 5 emails per sale per hour (a resend or two is normal; more is a mistake or abuse).
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE action = 'receipt_email' AND object_type = 'sales' AND object_id = ? AND occurred_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
        $st->execute([(string)$saleId]);
        if ((int)$st->fetchColumn() >= 5) throw new InvalidArgumentException('This receipt was emailed 5 times in the last hour. Try again later.');
    } catch (InvalidArgumentException $e) { throw $e; } catch (Throwable $e) {}
    if (!function_exists('bcf_send_mail')) require_once dirname(__DIR__) . '/admin/includes/email_template.php';
    $farm = function_exists('bcrf_farm_name') ? bcrf_farm_name() : 'Bat Cave Road Farm';
    $ok = bcf_send_mail($email, 'Your receipt from ' . $farm . ' (' . $s['sale_number'] . ')', bcrf_receipt_html($pdo, $s),
                        ['subtitle' => 'Thank you for visiting!', 'reply_to' => function_exists('bcrf_farm_email') ? bcrf_farm_email('support') : null]);
    if (!$ok) {
        error_log('[receipts] email failed for ' . $s['sale_number'] . ': ' . ($GLOBALS['bcf_mail_last_error'] ?? ''));
        throw new InvalidArgumentException('The email could not be sent. Check the address, or print the receipt instead.');
    }
    $pdo->prepare("UPDATE sales SET receipt_delivery = CASE WHEN receipt_delivery IN ('print', 'both') THEN 'both' ELSE 'email' END WHERE id = ?")->execute([$saleId]);
    if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        [$t, $aid, $name, $ip] = bcrf_audit_actor();
        bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => 'receipt_email',
            'object_type' => 'sales', 'object_id' => (string)$saleId, 'object_label' => 'Receipt ' . $s['sale_number'] . ' emailed to ' . $email]);
    }
    return $email;
}

}
