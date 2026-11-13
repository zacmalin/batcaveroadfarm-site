<?php
// includes/pdf_lite.php — a small PDF writer for labels (Farm OS Phase 4 slice 5, Nov 13, 2026; used by includes/kitchen_print.php).
//   No library needed: pages of any size, text in Helvetica / Helvetica-Bold (built into every PDF reader, WinAnsi
//   characters), filled rectangles, outlines, JPEG images, and Code 128 barcodes. Coordinates are points (1/72 in)
//   from the TOP-LEFT of the page; text y is the baseline. bcrf_pdf_width() measures text exactly as it prints,
//   so callers can wrap lines and know for certain whether text fits.
if (!function_exists('bcrf_pdf_width')) {

/** Helvetica and Helvetica-Bold advance widths (1/1000 em) for WinAnsi bytes 32–255 (Adobe core font metrics). */
function bcrf_pdf_widths(bool $bold): array {
    static $w = null;
    if ($w === null) {
        $w = [false => array_map('intval', explode(',', '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500')),
              true  => array_map('intval', explode(',', '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556'))];
    }
    return $w[$bold];
}

/** UTF-8 text to the WinAnsi bytes the PDF prints (characters it can't print become '?'). */
function bcrf_pdf_text(string $s): string {
    $s = strtr($s, ["\u{2009}" => ' ', "\u{202F}" => ' ', "\u{00A0}" => ' ', "\u{2032}" => "'", "\u{2033}" => '"', "\u{2212}" => '-', "\u{2044}" => '/']);
    $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    if ($out === false) $out = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    return preg_replace('/[\x00-\x1F]/', ' ', $out);
}

/** Width in points of UTF-8 text at a size. */
function bcrf_pdf_width(string $s, float $size, bool $bold = false): float {
    $w = bcrf_pdf_widths($bold); $t = 0;
    $b = bcrf_pdf_text($s);
    for ($i = 0, $n = strlen($b); $i < $n; $i++) { $c = ord($b[$i]); $t += $c >= 32 ? $w[$c - 32] : 0; }
    return $t * $size / 1000;
}

/**
 * Wrap text into lines no wider than $width. $first is room already used on the first line (for a bold lead-in such as
 * "Ingredients:"). A word longer than a whole line is broken by characters.
 */
function bcrf_pdf_wrap(string $s, float $size, float $width, bool $bold = false, float $first = 0.0): array {
    $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
    $lines = []; $cur = ''; $room = $width - $first;
    foreach ($words as $word) {
        $try = $cur === '' ? $word : $cur . ' ' . $word;
        if (bcrf_pdf_width($try, $size, $bold) <= $room) { $cur = $try; continue; }
        if ($cur !== '') { $lines[] = $cur; $cur = ''; $room = $width; }
        while (bcrf_pdf_width($word, $size, $bold) > $room) {
            $k = mb_strlen($word);
            while ($k > 1 && bcrf_pdf_width(mb_substr($word, 0, $k), $size, $bold) > $room) $k--;
            $lines[] = mb_substr($word, 0, $k); $word = mb_substr($word, $k); $room = $width;
        }
        $cur = $word;
    }
    if ($cur !== '' || !$lines) $lines[] = $cur;
    return $lines;
}

/** Code 128 (set B) bars for printable ASCII: module widths, bar first, alternating bar/space. Null if it can't encode. */
function bcrf_pdf_code128(string $data): ?array {
    if ($data === '' || preg_match('/[^\x20-\x7E]/', $data)) return null;
    static $p = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
    $codes = [104]; $sum = 104;
    for ($i = 0, $n = strlen($data); $i < $n; $i++) { $v = ord($data[$i]) - 32; $codes[] = $v; $sum += $v * ($i + 1); }
    $codes[] = $sum % 103; $codes[] = 106;
    $mods = [];
    foreach ($codes as $c) foreach (str_split($p[$c]) as $d) $mods[] = (int)$d;
    return $mods;
}

final class BcrfPdf {
    private array $pages = [];
    private int $cur = -1;
    private array $images = [];

    /** Start a page, width and height in points. */
    public function page(float $w, float $h): void { $this->pages[] = ['w' => $w, 'h' => $h, 'ops' => [], 'img' => []]; $this->cur = count($this->pages) - 1; }

    private function n(float $v): string { return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0'; }
    private function y(float $y): float { return $this->pages[$this->cur]['h'] - $y; }
    private function op(string $s): void { $this->pages[$this->cur]['ops'][] = $s; }

    public function text(float $x, float $y, string $s, float $size, bool $bold = false, array $rgb = [0, 0, 0]): void {
        $t = str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], bcrf_pdf_text($s));
        $this->op(sprintf('BT %s %s %s rg /%s %s Tf %s %s Td (%s) Tj ET', $this->n($rgb[0]), $this->n($rgb[1]), $this->n($rgb[2]),
            $bold ? 'F2' : 'F1', $this->n($size), $this->n($x), $this->n($this->y($y)), $t));
    }
    public function rect(float $x, float $y, float $w, float $h, array $rgb = [0, 0, 0]): void {
        $this->op(sprintf('%s %s %s rg %s %s %s %s re f', $this->n($rgb[0]), $this->n($rgb[1]), $this->n($rgb[2]), $this->n($x), $this->n($this->y($y + $h)), $this->n($w), $this->n($h)));
    }
    public function outline(float $x, float $y, float $w, float $h, float $lw = 0.5, array $rgb = [0.6, 0.6, 0.6], bool $dash = false): void {
        $this->op(sprintf('q %s %s %s RG %s w %s %s %s %s %s re S Q', $this->n($rgb[0]), $this->n($rgb[1]), $this->n($rgb[2]), $this->n($lw), $dash ? '[3 2] 0 d' : '',
            $this->n($x), $this->n($this->y($y + $h)), $this->n($w), $this->n($h)));
    }
    /** A JPEG file's bytes, drawn at x,y (top-left) w×h. */
    public function jpeg(string $bytes, float $x, float $y, float $w, float $h): bool {
        $info = @getimagesizefromstring($bytes);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_JPEG) return false;
        $key = md5($bytes);
        if (!isset($this->images[$key])) $this->images[$key] = ['data' => $bytes, 'w' => $info[0], 'h' => $info[1], 'gray' => ($info['channels'] ?? 3) === 1, 'name' => 'Im' . (count($this->images) + 1)];
        $im = $this->images[$key];
        $this->pages[$this->cur]['img'][$im['name']] = $key;
        $this->op(sprintf('q %s 0 0 %s %s %s cm /%s Do Q', $this->n($w), $this->n($h), $this->n($x), $this->n($this->y($y + $h)), $im['name']));
        return true;
    }
    /** A Code 128 barcode filling w×h at x,y (top-left). Returns false if the text can't be encoded. */
    public function barcode(string $data, float $x, float $y, float $w, float $h): bool {
        $mods = bcrf_pdf_code128($data);
        if (!$mods) return false;
        $unit = $w / (array_sum($mods) + 20);          // 10-module quiet zone each side
        $cx = $x + 10 * $unit;
        foreach ($mods as $i => $m) {
            if ($i % 2 === 0) $this->rect($cx, $y, $m * $unit, $h);
            $cx += $m * $unit;
        }
        return true;
    }

    /** The finished PDF. */
    public function output(): string {
        $objs = [];                                    // object number => body
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5; $imgObj = [];
        foreach ($this->images as $key => $im) {
            $imgObj[$key] = $next;
            $objs[$next++] = "<< /Type /XObject /Subtype /Image /Width {$im['w']} /Height {$im['h']} /ColorSpace /" . ($im['gray'] ? 'DeviceGray' : 'DeviceRGB')
                . " /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($im['data']) . " >>\nstream\n" . $im['data'] . "\nendstream";
        }
        $kids = [];
        foreach ($this->pages as $pg) {
            $content = implode("\n", $pg['ops']);
            $filter = '';
            if (function_exists('gzcompress')) { $content = gzcompress($content); $filter = ' /Filter /FlateDecode'; }
            $cObj = $next++; $pObj = $next++;
            $objs[$cObj] = '<< /Length ' . strlen($content) . $filter . " >>\nstream\n" . $content . "\nendstream";
            $xo = '';
            foreach ($pg['img'] as $name => $key) $xo .= '/' . $name . ' ' . $imgObj[$key] . ' 0 R ';
            $objs[$pObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->n($pg['w']) . ' ' . $this->n($pg['h']) . '] /Contents ' . $cObj . ' 0 R'
                . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . ($xo !== '' ? ' /XObject << ' . $xo . '>>' : '') . ' >> >>';
            $kids[] = $pObj . ' 0 R';
        }
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R /ViewerPreferences << /PrintScaling /None >> >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($objs as $num => $body) { $off[$num] = strlen($out); $out .= $num . " 0 obj\n" . $body . "\nendobj\n"; }
        $xref = strlen($out); $count = max(array_keys($objs)) + 1;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) $out .= isset($off[$i]) ? sprintf("%010d 00000 n \n", $off[$i]) : "0000000000 65535 f \n";
        return $out . "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}

}
