<?php
// includes/kitchen_print.php — Farm OS Phase 4 slice 5: printing kitchen labels (Nov 13, 2026; 09 › Labels › Printing;
// migration 0055, admin/kitchen_label_print.php, includes/pdf_lite.php).
//   Prints a batch's labels as a PDF sized to the label stock: the product's approved label (which must be for the
//   recipe version the batch was made from), with the batch's lot code and best-by date filled in and the SKU barcode.
//   Label stock (sheet size, label size, columns, rows, margins) and type sizes are settings (site_settings
//   kitchen_label_*). Defaults: PLS451 3″×3″, 6 per letter sheet; body text 6 pt (never below the 4.5 pt minimum);
//   net quantity 10 pt (never below 9 pt). Text that doesn't fit is flagged and the PDF is refused; type is never
//   shrunk to make it fit. Each print run is recorded (kitchen_label_prints).
// Used by admin/kitchen_label_print.php, admin/kitchen_batches.php and tests/kitchen_print_test.php.
if (!function_exists('bcrf_kitchen_print_ready')) {

require_once __DIR__ . '/kitchen_batches.php';
require_once __DIR__ . '/pdf_lite.php';

/** Migration 0055 has run. */
function bcrf_kitchen_print_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM kitchen_label_prints LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/** Built-in label stocks (inches). Anything else is entered as Custom. */
function bcrf_kitchen_print_stocks(): array {
    return [
        'PLS451' => ['name' => 'PLS451 3″ × 3″ square, 6 per sheet', 'page_w' => 8.5, 'page_h' => 11.0, 'label_w' => 3.0, 'label_h' => 3.0,
                     'cols' => 2, 'rows' => 3, 'left' => 0.75, 'top' => 0.5, 'col_pitch' => 4.0, 'row_pitch' => 3.5],
    ];
}

/** Defaults for every printing setting. */
function bcrf_kitchen_print_defaults(): array {
    return ['stock' => 'PLS451'] + bcrf_kitchen_print_stocks()['PLS451'] + [
            'body_pt' => 6.0, 'net_pt' => 10.0, 'min_body_pt' => 4.5, 'min_net_pt' => 9.0, 'nudge_x' => 0.0, 'nudge_y' => 0.0, 'logo' => 1];
}

/** The printing settings (site_settings kitchen_label_print, JSON), with defaults filled in. */
function bcrf_kitchen_print_settings(PDO $pdo): array {
    $d = bcrf_kitchen_print_defaults();
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'kitchen_label_print'");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
    } catch (Throwable $e) { $j = null; }
    if (!is_array($j)) return $d;
    foreach ($d as $k => $v) if (array_key_exists($k, $j)) $d[$k] = is_string($v) ? (string)$j[$k] : (is_int($v) ? (int)$j[$k] : (float)$j[$k]);
    if (isset(bcrf_kitchen_print_stocks()[$d['stock']])) $d = array_merge($d, bcrf_kitchen_print_stocks()[$d['stock']]);
    return $d;
}

/** Check and save the printing settings. Returns the saved settings; throws BcrfKitchenError on a bad value. */
function bcrf_kitchen_print_settings_save(PDO $pdo, array $in): array {
    $d = bcrf_kitchen_print_defaults();
    $num = function (string $k, float $min, float $max, string $what) use ($in, $d): float {
        $v = trim((string)($in[$k] ?? ''));
        if ($v === '') return (float)$d[$k];
        if (!is_numeric($v)) throw new BcrfKitchenError($what . ' must be a number.');
        $v = (float)$v;
        if ($v < $min || $v > $max) throw new BcrfKitchenError($what . ' must be between ' . $min . ' and ' . $max . '.');
        return round($v, 3);
    };
    $s = ['stock' => isset(bcrf_kitchen_print_stocks()[$in['stock'] ?? '']) ? (string)$in['stock'] : 'custom'];
    $s['min_body_pt'] = $num('min_body_pt', 4, 12, 'Smallest body text');
    $s['min_net_pt']  = $num('min_net_pt', 4, 24, 'Smallest net quantity text');
    $s['body_pt'] = $num('body_pt', 4, 12, 'Body text size');
    $s['net_pt']  = $num('net_pt', 4, 24, 'Net quantity size');
    if ($s['body_pt'] < $s['min_body_pt']) throw new BcrfKitchenError('Body text can\'t be smaller than the ' . $s['min_body_pt'] . ' pt minimum.');
    if ($s['net_pt'] < $s['min_net_pt']) throw new BcrfKitchenError('Net quantity text can\'t be smaller than the ' . $s['min_net_pt'] . ' pt minimum.');
    $s['nudge_x'] = $num('nudge_x', -0.5, 0.5, 'Nudge right');
    $s['nudge_y'] = $num('nudge_y', -0.5, 0.5, 'Nudge down');
    $s['logo'] = !empty($in['logo']) ? 1 : 0;
    if ($s['stock'] === 'custom') {
        $s['name'] = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)) ?: 'Custom labels';
        $s['page_w'] = $num('page_w', 2, 17, 'Sheet width');       $s['page_h'] = $num('page_h', 2, 17, 'Sheet height');
        $s['label_w'] = $num('label_w', 1, 8.5, 'Label width');    $s['label_h'] = $num('label_h', 0.75, 11, 'Label height');
        $s['cols'] = (int)$num('cols', 1, 10, 'Columns');          $s['rows'] = (int)$num('rows', 1, 20, 'Rows');
        $s['left'] = $num('left', 0, 8, 'Left margin');            $s['top'] = $num('top', 0, 8, 'Top margin');
        $s['col_pitch'] = $num('col_pitch', 0, 17, 'Column spacing'); $s['row_pitch'] = $num('row_pitch', 0, 17, 'Row spacing');
        if ($s['cols'] > 1 && $s['col_pitch'] < $s['label_w']) throw new BcrfKitchenError('Column spacing (left edge to left edge) can\'t be less than the label width.');
        if ($s['rows'] > 1 && $s['row_pitch'] < $s['label_h']) throw new BcrfKitchenError('Row spacing (top edge to top edge) can\'t be less than the label height.');
        if ($s['left'] + ($s['cols'] - 1) * $s['col_pitch'] + $s['label_w'] > $s['page_w'] + 0.001
            || $s['top'] + ($s['rows'] - 1) * $s['row_pitch'] + $s['label_h'] > $s['page_h'] + 0.001) throw new BcrfKitchenError('Those labels don\'t fit on the sheet. Check the margins and spacing.');
    }
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('kitchen_label_print', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([json_encode($s)]);
    return bcrf_kitchen_print_settings($pdo);
}

/** Labels per sheet. */
function bcrf_kitchen_print_per_sheet(array $cfg): int { return max(1, (int)$cfg['cols'] * (int)$cfg['rows']); }

/** Top-left corner (points) of sheet position $i (0-based, across then down), nudge included. */
function bcrf_kitchen_print_pos(array $cfg, int $i): array {
    $c = $i % (int)$cfg['cols']; $r = intdiv($i, (int)$cfg['cols']);
    return [72 * ((float)$cfg['left'] + $c * (float)$cfg['col_pitch'] + (float)$cfg['nudge_x']),
            72 * ((float)$cfg['top'] + $r * (float)$cfg['row_pitch'] + (float)$cfg['nudge_y'])];
}

/** The farm logo as JPEG bytes for the label (from img/logos/print_square_logo.png; cached). Null if unavailable. */
function bcrf_kitchen_print_logo(): ?string {
    $src = dirname(__DIR__) . '/img/logos/print_square_logo.png';
    if (!is_file($src) || !function_exists('imagecreatefrompng')) return null;
    $cache = rtrim(sys_get_temp_dir(), '/') . '/bcrf_label_logo_' . filemtime($src) . '_' . filesize($src) . '.jpg';
    if (is_file($cache)) return (string)file_get_contents($cache);
    $size = @getimagesize($src);
    if (!$size || $size[0] * $size[1] > 16000000) return null;   // too big to decode safely on shared hosting
    $im = @imagecreatefrompng($src);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im); $side = 360;
    $out = imagecreatetruecolor($side, $side);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    $scale = min($side / $w, $side / $h); $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
    imagecopyresampled($out, $im, intdiv($side - $nw, 2), intdiv($side - $nh, 2), 0, 0, $w, $h, $nw, $nh);
    ob_start(); imagejpeg($out, null, 88); $jpg = (string)ob_get_clean();
    imagedestroy($im); imagedestroy($out);
    @file_put_contents($cache, $jpg);
    return $jpg;
}

/**
 * Lay out one label. $l: product_name, statement, contains_line, net_qty, made_by, extra. $lot, $bestBy (display text,
 * '' to leave off), $sku (barcode, '' for none). Returns ['w', 'h' (points), 'ops' (drawing steps, label-relative),
 * 'fits', 'problems', 'room', 'used'].
 * ops: ['t', x, y, text, size, bold] · ['r', x, y, w, h] (filled black) · ['bar', data, x, y, w, h] · ['img', x, y, w, h]
 */
function bcrf_kitchen_label_layout(array $l, string $lot, string $bestBy, string $sku, array $cfg, bool $logo): array {
    $W = 72 * (float)$cfg['label_w']; $H = 72 * (float)$cfg['label_h'];
    $pad = min(9.0, $W * 0.05); $iw = $W - 2 * $pad;
    $body = (float)$cfg['body_pt']; $net = (float)$cfg['net_pt'];
    $lead = 1.22; $ops = []; $problems = [];
    if ($body < (float)$cfg['min_body_pt']) $problems[] = 'Body text is set below the ' . $cfg['min_body_pt'] . ' pt minimum.';
    if ($net < (float)$cfg['min_net_pt']) $problems[] = 'Net quantity text is set below the ' . $cfg['min_net_pt'] . ' pt minimum.';

    // Header: logo (wide enough labels) and the product name, largest size that fits in 3 lines.
    $L = ($logo && $W >= 150) ? min(54.0, $H * 0.25) : 0.0;
    if ($L) $ops[] = ['img', $pad, $pad, $L, $L];
    $nx = $pad + ($L ? $L + 6 : 0); $nw = $iw - ($L ? $L + 6 : 0);
    $nameLines = []; $ns = 9.0;
    foreach ([14, 13, 12, 11, 10, 9] as $ns) {
        $nameLines = bcrf_pdf_wrap((string)$l['product_name'], $ns, $nw, true);
        if (count($nameLines) <= 3 && count($nameLines) * $ns * 1.12 <= max($L, 3 * 14 * 1.12)) break;
    }
    if (count($nameLines) > 3) $problems[] = 'The product name is too long for this label.';
    $nh = count($nameLines) * $ns * 1.12;
    $ny = $pad + ($L > $nh ? ($L - $nh) / 2 : 0);
    foreach ($nameLines as $i => $t) $ops[] = ['t', $nx, $ny + $ns * 0.9 + $i * $ns * 1.12, $t, $ns, true];
    $top = $pad + max($L, $nh) + 3;
    $ops[] = ['r', $pad, $top, $iw, 0.5];
    $top += 0.5 + $body * 0.6;

    // Bottom: net quantity (left, bottom of the label) and the barcode (right).
    $bw = $sku !== '' && bcrf_pdf_code128($sku) ? min(72.0, $iw * 0.42) : 0.0;
    $netW = $iw - ($bw ? $bw + 6 : 0);
    $netLines = (string)$l['net_qty'] !== '' ? bcrf_pdf_wrap((string)$l['net_qty'], $net, $netW, true) : [];
    if (count($netLines) > 2) $problems[] = 'The net quantity doesn\'t fit on two lines.';
    $bh = $bw ? 24.0 : 0.0;
    $bottomH = max(count($netLines) * $net * 1.1, $bh);
    $bottomTop = $H - $pad - $bottomH;
    foreach ($netLines as $i => $t) $ops[] = ['t', $pad, $H - $pad - (count($netLines) - 1 - $i) * $net * 1.1 - $net * 0.2, $t, $net, true];
    if ($bw) {
        $ops[] = ['bar', $sku, $W - $pad - $bw, $H - $pad - $bh, $bw, $bh - 6];
        $ops[] = ['t', $W - $pad - $bw / 2 - bcrf_pdf_width($sku, 5) / 2, $H - $pad - 0.5, $sku, 5.0, false];
    }

    // Lot and best-by, just above the bottom.
    $lotText = trim(($lot !== '' ? 'Lot ' . $lot : '') . ($bestBy !== '' ? ($lot !== '' ? ' · ' : '') . 'Best by ' . $bestBy : ''));
    $lotY = $bottomTop - 3;
    if ($lotText !== '') {
        foreach (array_reverse(bcrf_pdf_wrap($lotText, $body, $iw, true)) as $i => $t) $ops[] = ['t', $pad, $lotY - $body * 0.2 - $i * $body * $lead, $t, $body, true];
        $lotY -= count(bcrf_pdf_wrap($lotText, $body, $iw, true)) * $body * $lead + 2;
    }

    // Middle: Ingredients, Contains, Made by, required statement. Never shrunk: if it doesn't fit, it's flagged.
    $room = $lotY - $top;
    $y = $top + $body * 0.85; $used = 0.0; $paras = [];
    $ing = 'Ingredients: ';
    $paras[] = ['lead' => $ing, 'text' => rtrim((string)$l['statement'], '.') . '.', 'bold' => false];
    if ((string)$l['contains_line'] !== '') $paras[] = ['lead' => '', 'text' => 'Contains: ' . rtrim((string)$l['contains_line'], '.') . '.', 'bold' => true];
    if ((string)$l['made_by'] !== '') $paras[] = ['lead' => '', 'text' => (string)$l['made_by'], 'bold' => false];
    foreach (preg_split('/\n+/', trim((string)($l['extra'] ?? ''))) as $x) if (trim($x) !== '') $paras[] = ['lead' => '', 'text' => trim($x), 'bold' => false];
    foreach ($paras as $k => $p) {
        $leadW = $p['lead'] !== '' ? bcrf_pdf_width($p['lead'], $body, true) : 0.0;
        $lines = bcrf_pdf_wrap($p['text'], $body, $iw, $p['bold'], $leadW);
        foreach ($lines as $i => $t) {
            if ($i === 0 && $p['lead'] !== '') { $ops[] = ['t', $pad, $y, rtrim($p['lead']), $body, true]; $ops[] = ['t', $pad + $leadW, $y, $t, $body, $p['bold']]; }
            else $ops[] = ['t', $pad, $y, $t, $body, $p['bold']];
            $y += $body * $lead;
        }
        $y += $body * 0.35;
    }
    $used = $y - $body * 0.35 - $body * $lead - $top + $body * 0.3;
    $fits = $used <= $room;
    if (!$fits) {
        $over = (int)ceil(($used - $room) / ($body * $lead));
        $problems[] = 'The ingredient text is about ' . $over . ' line' . ($over === 1 ? '' : 's') . ' too long for a ' . bcrf_kitchen_q((float)$cfg['label_w']) . '″ × ' . bcrf_kitchen_q((float)$cfg['label_h'])
            . '″ label at ' . bcrf_kitchen_q($body) . ' pt. Shorten label names in Kitchen › Ingredients, or use a bigger label. Type is never shrunk to fit.';
    }
    return ['w' => $W, 'h' => $H, 'ops' => $ops, 'fits' => $fits && !$problems, 'problems' => $problems, 'room' => $room, 'used' => $used];
}

/** The label drawn as inline SVG at true size, for the on-screen preview (same layout as the PDF). */
function bcrf_kitchen_label_svg(array $layout, ?string $logoJpeg): string {
    $W = $layout['w']; $H = $layout['h']; $f = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    $o = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $f($W) . ' ' . $f($H) . '" width="' . $f($W / 72) . 'in" height="' . $f($H / 72) . 'in" class="max-w-full h-auto bg-white border border-stone-400 rounded" role="img" aria-label="Label preview">';
    foreach ($layout['ops'] as $op) {
        if ($op[0] === 't') $o .= '<text x="' . $f($op[1]) . '" y="' . $f($op[2]) . '" font-family="Helvetica, Arial, sans-serif" font-size="' . $f($op[4]) . '"' . ($op[5] ? ' font-weight="bold"' : '') . ' fill="#000">' . htmlspecialchars($op[3], ENT_QUOTES) . '</text>';
        elseif ($op[0] === 'r') $o .= '<rect x="' . $f($op[1]) . '" y="' . $f($op[2]) . '" width="' . $f($op[3]) . '" height="' . $f($op[4]) . '" fill="#000"/>';
        elseif ($op[0] === 'img') $o .= $logoJpeg ? '<image x="' . $f($op[1]) . '" y="' . $f($op[2]) . '" width="' . $f($op[3]) . '" height="' . $f($op[4]) . '" href="data:image/jpeg;base64,' . base64_encode($logoJpeg) . '"/>'
                                                  : '<rect x="' . $f($op[1]) . '" y="' . $f($op[2]) . '" width="' . $f($op[3]) . '" height="' . $f($op[4]) . '" fill="#e7e5e4"/>';
        elseif ($op[0] === 'bar') {
            $mods = bcrf_pdf_code128($op[1]); $unit = $op[4] / (array_sum($mods) + 20); $cx = $op[2] + 10 * $unit;
            foreach ($mods as $i => $m) { if ($i % 2 === 0) $o .= '<rect x="' . $f($cx) . '" y="' . $f($op[3]) . '" width="' . $f($m * $unit) . '" height="' . $f($op[5]) . '" fill="#000"/>'; $cx += $m * $unit; }
        }
    }
    return $o . '</svg>';
}

/** Draw a laid-out label into the PDF at x,y. */
function bcrf_kitchen_label_draw(BcrfPdf $pdf, array $layout, float $x, float $y, ?string $logoJpeg): void {
    foreach ($layout['ops'] as $op) {
        if ($op[0] === 't') $pdf->text($x + $op[1], $y + $op[2], $op[3], $op[4], $op[5]);
        elseif ($op[0] === 'r') $pdf->rect($x + $op[1], $y + $op[2], $op[3], $op[4]);
        elseif ($op[0] === 'img' && $logoJpeg) $pdf->jpeg($logoJpeg, $x + $op[1], $y + $op[2], $op[3], $op[4]);
        elseif ($op[0] === 'bar') $pdf->barcode($op[1], $x + $op[2], $y + $op[3], $op[4], $op[5]);
    }
}

/**
 * Everything needed to print a batch's labels. Returns ['batch', 'label', 'cfg', 'layout' (null if blocked), 'blockers',
 * 'warnings', 'copies_default'].
 */
function bcrf_kitchen_print_plan(PDO $pdo, int $batchId, ?string $logoJpeg = null): array {
    $b = bcrf_kitchen_batch($pdo, $batchId);
    if (!$b) throw new BcrfKitchenError('That batch doesn\'t exist.');
    $cfg = bcrf_kitchen_print_settings($pdo);
    $blockers = []; $warnings = []; $label = null; $layout = null;
    if ($b['voided_at']) $blockers[] = 'This batch was voided. Its labels can\'t be printed.';
    if (!$blockers) {
        $label = bcrf_kitchen_label_state($pdo, (int)$b['inventory_id'])['approved'];
        if (!$label) $blockers[] = 'This product has no approved label right now. Approve one in Kitchen › Labels first.';
        elseif ((int)$label['recipe_version_id'] !== (int)$b['recipe_version_id'])
            $blockers[] = 'This batch was made from recipe version ' . (int)$b['version_no'] . ', but the approved label is for version ' . (int)$label['version_no'] . '. Its labels would not match what is in the jar.';
    }
    if (!$blockers) {
        $best = $b['best_by'] ? date('M j, Y', strtotime($b['best_by'] . ' 12:00')) : '';
        if ($best === '') $warnings[] = 'No best-by date: set a shelf life on the product in Kitchen › Products before the next batch.';
        $layout = bcrf_kitchen_label_layout($label, (string)$b['lot_code'], $best, (string)($b['sku'] ?? ''), $cfg, (bool)$cfg['logo'] && $logoJpeg !== null);
    }
    return ['batch' => $b, 'label' => $label, 'cfg' => $cfg, 'layout' => $layout, 'blockers' => $blockers, 'warnings' => $warnings,
            'copies_default' => max(1, min(500, (int)ceil((float)$b['units_made'])))];
}

/** Build the PDF for a print run: $copies labels starting at sheet position $start (1-based). */
function bcrf_kitchen_print_pdf(array $layout, array $cfg, int $copies, int $start, ?string $logoJpeg): string {
    $per = bcrf_kitchen_print_per_sheet($cfg);
    $pdf = new BcrfPdf();
    $slot = $start - 1; $placed = 0;
    while ($placed < $copies) {
        if ($placed === 0 || $slot % $per === 0) $pdf->page(72 * (float)$cfg['page_w'], 72 * (float)$cfg['page_h']);
        [$x, $y] = bcrf_kitchen_print_pos($cfg, $slot % $per);
        bcrf_kitchen_label_draw($pdf, $layout, $x, $y, $logoJpeg);
        $placed++; $slot++;
    }
    return $pdf->output();
}

/** A test sheet: every label position outlined and numbered, to check the printer lines up with the stock. */
function bcrf_kitchen_print_test_pdf(array $cfg): string {
    $pdf = new BcrfPdf();
    $pdf->page(72 * (float)$cfg['page_w'], 72 * (float)$cfg['page_h']);
    $W = 72 * (float)$cfg['label_w']; $H = 72 * (float)$cfg['label_h'];
    for ($i = 0, $n = bcrf_kitchen_print_per_sheet($cfg); $i < $n; $i++) {
        [$x, $y] = bcrf_kitchen_print_pos($cfg, $i);
        $pdf->outline($x, $y, $W, $H, 0.75, [0, 0, 0]);
        $pdf->outline($x + 9, $y + 9, $W - 18, $H - 18, 0.5, [0.55, 0.55, 0.55], true);
        $pdf->rect($x + $W / 2 - 6, $y + $H / 2 - 0.25, 12, 0.5); $pdf->rect($x + $W / 2 - 0.25, $y + $H / 2 - 6, 0.5, 12);
        $pdf->text($x + 12, $y + 22, 'Position ' . ($i + 1), 10, true);
        $pdf->text($x + 12, $y + 34, 'Solid line = label edge. Dashed = 1/8" margin.', 6);
    }
    $pdf->text(36, 72 * (float)$cfg['page_h'] - 14, 'Test sheet: ' . $cfg['name'] . '. Print at 100% / Actual size (not "Fit to page"). Lay it over a label sheet and hold to the light.', 7);
    return $pdf->output();
}

/** Record a print run. Returns its id. */
function bcrf_kitchen_print_record(PDO $pdo, array $plan, int $copies, int $start, ?int $uid): int {
    if ($plan['blockers']) throw new BcrfKitchenError($plan['blockers'][0]);
    if (!$plan['layout'] || !$plan['layout']['fits']) throw new BcrfKitchenError($plan['layout']['problems'][0] ?? 'The label doesn\'t fit this label stock.');
    if ($copies < 1 || $copies > 500) throw new BcrfKitchenError('Print between 1 and 500 labels at a time.');
    $per = bcrf_kitchen_print_per_sheet($plan['cfg']);
    if ($start < 1 || $start > $per) throw new BcrfKitchenError('Start position must be 1 to ' . $per . '.');
    $pdo->prepare("INSERT INTO kitchen_label_prints (batch_id, label_id, copies, start_pos, stock, created_at, created_by) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([(int)$plan['batch']['id'], (int)$plan['label']['id'], $copies, $start, mb_substr((string)$plan['cfg']['name'], 0, 120), $uid]);
    return (int)$pdo->lastInsertId();
}

/** Print runs for a batch, newest first. */
function bcrf_kitchen_prints_for(PDO $pdo, int $batchId): array {
    $st = $pdo->prepare("SELECT p.*, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS by_name
                         FROM kitchen_label_prints p LEFT JOIN users u ON u.id = p.created_by WHERE p.batch_id = ? ORDER BY p.id DESC");
    $st->execute([$batchId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

}
