<?php
// includes/fonts.php
// Self-hosted web fonts (Nov 11, 2026 performance fix). Replaces the Google Fonts
// stylesheet, which PageSpeed showed blocking first paint for ~750 ms and then
// fetching 7 files from fonts.gstatic.com on a separate connection.
//
// Files live in /fonts/ (from Fontsource 5.3.0, the same Google Fonts files):
// Rye 400, Lora 400 / 600 / 400 italic, Playfair Display 700, Caveat 400 / 600,
// each split into "latin" and "latin-ext". A browser only downloads a file when
// the page actually uses that font, weight and character range.
//
// Usage, inside <head>: require_once this file, then echo bcrf_fonts_head();
// (no closing PHP tag in this comment on purpose: one would end PHP mode here). The default preloads Rye (headings, the homepage LCP text) and Lora 400 (body).

if (!function_exists('bcrf_fonts_head')) {
    function bcrf_fonts_head(array $preload = ['rye-latin-400-normal', 'lora-latin-400-normal']): string
    {
        $v      = '5.3.0';   // bump if the font files are ever replaced (Cloudflare caches /fonts/ for 30 days)
        $latin  = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
        $ext    = 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF';
        // [family, file stem, weight, style]
        $faces  = [
            ['Rye',              'rye',              400, 'normal'],
            ['Lora',             'lora',             400, 'normal'],
            ['Lora',             'lora',             600, 'normal'],
            ['Lora',             'lora',             400, 'italic'],
            ['Playfair Display', 'playfair-display', 700, 'normal'],
            ['Caveat',           'caveat',           400, 'normal'],
            ['Caveat',           'caveat',           600, 'normal'],
        ];

        $out = '';
        foreach ($preload as $p) {
            $p = preg_replace('/[^a-z0-9\-]/', '', (string)$p);
            if ($p !== '') {
                $out .= '<link rel="preload" href="/fonts/' . $p . '.woff2?v=' . $v . '" as="font" type="font/woff2" crossorigin>' . "\n";
            }
        }
        $css = '';
        foreach ($faces as [$fam, $stem, $w, $st]) {
            foreach (['latin-ext' => $ext, 'latin' => $latin] as $sub => $range) {
                $css .= "@font-face{font-family:'{$fam}';font-style:{$st};font-weight:{$w};font-display:swap;"
                      . "src:url(/fonts/{$stem}-{$sub}-{$w}-{$st}.woff2?v={$v}) format('woff2');unicode-range:{$range}}\n";
            }
        }
        return $out . "<style>\n" . $css . "</style>\n";
    }
}
