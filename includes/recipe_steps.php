<?php
// includes/recipe_steps.php
//
// Turns a recipe's stored instructions HTML into numbered steps that can carry
// nested sub-lists.
//
// The admin editor is Quill 2. Quill 2 does NOT save real nested lists: every
// list item lands in one flat <ol>, each <li> tagged data-list="ordered" or
// data-list="bullet", with Tab-indents saved as class="ql-indent-N". The old
// renderer turned every <li> into its own numbered step, so bullets under a
// step became the next numbers.
//
// Rule here: a top-level ordered item starts a step. Bullet items, and any item
// indented with Tab, that follow it belong to that step as a nested list.
//
// Existing recipes must render unchanged, so recipe_nested_steps() returns null
// whenever no step ends up with sub-items; the caller then uses the legacy
// line-split steps.

if (!function_exists('recipe_nested_steps')) {

/** Plain, whitespace-collapsed text of a DOM node. */
function recipe_steps_text(DOMNode $n): string {
    $t = preg_replace('/\s+/u', ' ', $n->textContent ?? '');
    return trim((string)$t);
}

/**
 * @return array<int, array{text:string, subs:array<int, array{type:string, depth:int, text:string}>}>|null
 */
function recipe_nested_steps(string $html): ?array {
    if (trim($html) === '' || !class_exists('DOMDocument')) return null;
    // Cheap pre-check: nothing list-like that could nest → legacy path.
    if (!preg_match('/data-list="bullet"|ql-indent-|<ul[\s>]/i', $html)) return null;

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8"?><div id="rs-root">' . $html . '</div>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return null;
    $root = $doc->getElementById('rs-root');
    if (!$root) return null;

    $steps = [];
    $cur   = null;   // index of the current step in $steps
    $hasSubs = false;

    $addStep = function (string $text) use (&$steps, &$cur): void {
        if ($text === '') return;
        $steps[] = ['text' => $text, 'subs' => []];
        $cur = count($steps) - 1;
    };

    foreach (iterator_to_array($root->childNodes) as $node) {
        if ($node instanceof DOMText) {
            foreach (preg_split('/\R/u', $node->textContent) as $line) { $addStep(trim(preg_replace('/\s+/u', ' ', $line))); }
            continue;
        }
        if (!($node instanceof DOMElement)) continue;
        $tag = strtolower($node->tagName);

        if ($tag === 'ol' || $tag === 'ul') {
            foreach ($node->childNodes as $li) {
                if (!($li instanceof DOMElement) || strtolower($li->tagName) !== 'li') continue;
                $type = $li->getAttribute('data-list');
                if ($type === '') $type = ($tag === 'ul') ? 'bullet' : 'ordered';
                if ($type === 'checked' || $type === 'unchecked') $type = 'bullet';
                $depth = 0;
                if (preg_match('/\bql-indent-(\d+)\b/', $li->getAttribute('class'), $m)) $depth = (int)$m[1];
                $text = recipe_steps_text($li);
                if ($text === '') continue;

                $isStep = ($type === 'ordered' && $depth === 0);
                if ($isStep || $cur === null) {
                    $addStep($text);
                } else {
                    // A bullet sits one level under the step even with no Tab; each Tab goes one deeper.
                    $raw = ($type === 'ordered') ? max(1, $depth) : $depth + 1;
                    $steps[$cur]['subs'][] = ['type' => $type === 'ordered' ? 'ordered' : 'bullet', 'depth' => $raw, 'text' => $text];
                    $hasSubs = true;
                }
            }
            continue;
        }

        // Paragraphs, headings, divs: same as the legacy renderer — each line is a step.
        $inner = '';
        foreach ($node->childNodes as $c) { $inner .= $doc->saveHTML($c); }
        $inner = preg_replace('#<br\s*/?>#i', "\n", $inner);
        foreach (preg_split('/\R/u', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $line) {
            $addStep(trim(preg_replace('/\s+/u', ' ', $line)));
        }
    }

    if (!$hasSubs) return null;
    // Normalise each step's sub-items: first one at level 1, never jump more than one level deeper.
    foreach ($steps as &$st) {
        if (!$st['subs']) continue;
        $shift = $st['subs'][0]['depth'] - 1;
        $prevD = 0;
        foreach ($st['subs'] as &$sub) {
            $d = max(1, $sub['depth'] - $shift);
            $sub['depth'] = $prevD = min($d, $prevD + 1);
        }
        unset($sub);
    }
    unset($st);
    return $steps;
}

/** Render a step's sub-items (flat list with depths) as properly nested lists. */
function recipe_render_subs(array $subs): string {
    $out = '';
    $stack = []; // open list tags, one per depth level
    $n = count($subs);
    for ($i = 0; $i < $n; $i++) {
        $s = $subs[$i];
        $d = $s['depth'];
        $tag = $s['type'] === 'ordered' ? 'ol' : 'ul';
        // close deeper levels
        while (count($stack) > $d) { $out .= '</li></' . array_pop($stack) . '>'; }
        if (count($stack) === $d) {
            // same level: close previous item; switch list type if it changed
            if ($stack[$d - 1] !== $tag) { $out .= '</li></' . array_pop($stack) . '><' . $tag . '>'; $stack[] = $tag; }
            else { $out .= '</li>'; }
        }
        // open levels up to $d
        while (count($stack) < $d) { $out .= '<' . $tag . '>'; $stack[] = $tag; }
        $out .= '<li>' . htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8');
    }
    while ($stack) { $out .= '</li></' . array_pop($stack) . '>'; }
    return $out;
}

/** Plain-text version of a step (for JSON-LD HowToStep text). */
function recipe_step_plain(array $step): string {
    if (!$step['subs']) return $step['text'];
    return $step['text'] . ' ' . implode('; ', array_column($step['subs'], 'text')) . '.';
}

}
