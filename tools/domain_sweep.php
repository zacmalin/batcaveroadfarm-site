<?php // tools/domain_sweep.php — Farm OS Phase 0, stage 2 (CLI only; run once, kept for reference)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Usage: php domain_sweep.php <repo root> <file>...   (rewrites files in place, prints a report line per file)
// PHP code strings: 'https://batcaveroadfarm.com/x' → bcrf_farm_url() . '/x'; support@… → bcrf_farm_email('support');
// bare domain → bcrf_farm_domain(). Inline HTML gets short echo tags. Comments, subdomains and other mailboxes untouched.
// Strings with interpolation (heredocs, "…$x…") are reported, not changed.

$root = rtrim($argv[1], '/');
$files = array_slice($argv, 2);

const RE_URL   = '#https?://(?:www\.)?batcaveroadfarm\.com(?![a-z0-9.-]*[a-z0-9-])#i';
const RE_MAIL  = '#\b(support|jobs|no-reply|newsletter)@batcaveroadfarm\.com(?![a-z0-9-])#i';
const RE_BARE  = '#(?<![a-z0-9._%+-]@)(?<![a-z0-9-]\.)(?<!://)(?<![a-z0-9-])batcaveroadfarm\.com(?![a-z0-9-])#i';
const ROLES    = ['support' => 'support', 'jobs' => 'jobs', 'no-reply' => 'noreply', 'newsletter' => 'newsletter'];

/** Split text into [literal, expr, literal, expr, ...] pieces. Returns [] if nothing to replace. */
function pieces(string $s): array {
    $hits = [];
    foreach ([RE_URL, RE_MAIL, RE_BARE] as $i => $re) {
        preg_match_all($re, $s, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $j => [$txt, $off]) {
            $expr = $i === 0 ? 'bcrf_farm_url()' : ($i === 1 ? "bcrf_farm_email('" . ROLES[strtolower($m[1][$j][0])] . "')" : 'bcrf_farm_domain()');
            $hits[] = [$off, strlen($txt), $expr];
        }
    }
    if (!$hits) return [];
    usort($hits, fn($a, $b) => $a[0] <=> $b[0]);
    $out = []; $pos = 0;
    foreach ($hits as [$off, $len, $expr]) {
        if ($off < $pos) continue;                     // overlap (url rule wins: it runs first and sorts equal)
        $out[] = substr($s, $pos, $off - $pos); $out[] = $expr; $pos = $off + $len;
    }
    $out[] = substr($s, $pos);
    return $out;
}

$report = [];
foreach ($files as $rel) {
    $path = "$root/$rel";
    $src = file_get_contents($path);
    $toks = token_get_all($src);
    $new = ''; $n = 0; $skipped = [];
    $line = 1;
    foreach ($toks as $k => $t) {
        if (is_string($t)) { $new .= $t; continue; }
        [$id, $text, $line] = $t;
        $p = [];
        if (stripos($text, 'batcaveroadfarm.com') !== false) {
            if ($id === T_INLINE_HTML) {
                $p = pieces($text);
                if ($p) {
                    $buf = '';
                    foreach ($p as $i => $piece) {
                        if ($i % 2 === 0) { $buf .= $piece; continue; }
                        $echo = str_starts_with($piece, 'bcrf_farm_email') ? "htmlspecialchars($piece, ENT_QUOTES)" : $piece;
                        $after = $p[$i + 1] ?? '';
                        $buf .= "<?= $echo ?>" . (str_starts_with($after, "\n") || str_starts_with($after, "\r\n") ? "\n" : '');   // a closing tag eats one newline
                        $n++;
                    }
                    $new .= $buf; continue;
                }
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $q = $text[0]; $body = substr($text, 1, -1);
                $p = pieces($body);
                if ($p) {
                    $parts = [];
                    foreach ($p as $i => $piece) {
                        if ($i % 2 === 1) { $parts[] = $piece; $n++; }
                        elseif ($piece !== '') $parts[] = $q . $piece . $q;
                    }
                    $new .= '(' . implode(' . ', $parts) . ')';
                    continue;
                }
            } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
                if (pieces($text)) $skipped[] = $line;
            }
            // comments, subdomain-only and other mailboxes fall through unchanged
        }
        $new .= $text;
    }
    if ($n === 0) { $report[] = "$rel\t0\t" . ($skipped ? 'interp:' . implode(',', array_unique($skipped)) : ''); continue; }

    // Make sure the helpers are loaded: one guarded require near the top.
    $depth = substr_count($rel, '/');
    $req = "if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '" . str_repeat('/..', $depth) . "/includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)";
    if ($rel === 'includes/farm.php') continue;
    if (str_starts_with($rel, 'includes/') && $depth === 1) $req = str_replace("'/../includes/farm.php'", "'/farm.php'", $req);
    // After the opening tag, or after the path comment on line 2 (one that names this file).
    $pathLine = '(?:[ \t]*\/\/ ' . preg_quote($rel, '/') . '\b[^\n]*\n)?';
    if (preg_match('/^<\?php[^\n]*\n' . $pathLine . '/', $new, $m)) {
        $new = $m[0] . $req . "\n" . substr($new, strlen($m[0]));
    } else {
        $new = "<?php $req ?>" . $new;
    }
    file_put_contents($path, $new);
    $report[] = "$rel\t$n\t" . ($skipped ? 'interp:' . implode(',', array_unique($skipped)) : '');
}
echo implode("\n", $report), "\n";
