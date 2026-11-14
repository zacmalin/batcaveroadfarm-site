<?php
// includes/admin_switch.php — one on/off switch for Farm OS list rows (Nov 14, 2026).
// A switch is a tiny POST form: without script it submits and the page reloads; with admin-ui.js it saves in the
// background (same CSRF token, same permission checks, same audit trail as the page's own Save) and offers Undo.
// The form always sends the state it wants (on=1 / on=0), never "flip", so a double tap or an Undo can't drift.
//
//   echo bcrf_switch(['action' => 'products.php', 'csrf' => $csrf, 'fields' => ['act' => 'active', 'id' => 7],
//                     'on' => true, 'label' => 'Kettle corn on sale']);
//   Handler: if (bcrf_switch_ajax()) bcrf_switch_json(true, 'Turned off.', false);
if (!function_exists('bcrf_switch')) {

/**
 * $o: action (form URL), csrf, fields (hidden name => value), on (bool), label (what the switch controls, for
 * screen readers), on_text / off_text (default On / Off), disabled (bool), class (extra form classes).
 */
function bcrf_switch(array $o): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $on = !empty($o['on']);
    $onText = (string)($o['on_text'] ?? 'On');
    $offText = (string)($o['off_text'] ?? 'Off');
    $out = '<form method="POST" action="' . $h($o['action'] ?? '') . '" class="ui-switch-form ' . $h($o['class'] ?? '') . '" data-switch>'
         . '<input type="hidden" name="csrf_token" value="' . $h($o['csrf'] ?? '') . '">';
    foreach ((array)($o['fields'] ?? []) as $k => $v) $out .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
    $out .= '<input type="hidden" name="on" value="' . ($on ? '0' : '1') . '">'
          . '<button type="submit" class="ui-switch" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '"'
          . ' aria-label="' . $h($o['label'] ?? '') . '" data-on-text="' . $h($onText) . '" data-off-text="' . $h($offText) . '"'
          . (!empty($o['disabled']) ? ' disabled' : '') . '>'
          . '<span class="ui-switch-track" aria-hidden="true"><span class="ui-switch-knob"></span></span>'
          . '<span class="ui-switch-text">' . $h($on ? $onText : $offText) . '</span></button></form>';
    return $out;
}

/** True when admin-ui.js sent the switch (it adds ajax=1 and wants JSON back instead of a redirect). */
function bcrf_switch_ajax(): bool {
    return ($_POST['ajax'] ?? '') === '1';
}

/** The state the switch asked for. */
function bcrf_switch_wanted(): bool {
    return ($_POST['on'] ?? '') === '1';
}

/** Answer a background switch and stop. $on is the state now saved (null when nothing changed). */
function bcrf_switch_json(bool $ok, string $msg, ?bool $on = null): never {
    while (ob_get_level() > 0) ob_end_clean();
    if (!$ok) http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $ok, 'msg' => $msg, 'on' => $on], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Set one on/off column and audit it. $table/$col/$labelCol come from code, never from the request.
 * $extra: more "col = value" pairs to set (e.g. updated_by). Returns ['label' => …, 'was' => bool] or null if the row is gone.
 */
function bcrf_switch_set(PDO $pdo, string $table, string $col, int $id, bool $on, string $labelCol = 'name', array $extra = [], bool $touch = false): ?array {
    foreach ([$table, $col, $labelCol] as $n) if (!preg_match('/^[a-z_]+$/', $n)) throw new InvalidArgumentException('Bad column.');
    $st = $pdo->prepare("SELECT `$col` AS v, `$labelCol` AS l FROM `$table` WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $was = (int)$row['v'] === 1;
    if ($was !== $on) {
        $set = ["`$col` = ?"]; $vals = [$on ? 1 : 0];
        foreach ($extra as $k => $v) { if (!preg_match('/^[a-z_]+$/', $k)) continue; $set[] = "`$k` = ?"; $vals[] = $v; }
        if ($touch) $set[] = 'updated_at = UTC_TIMESTAMP()';
        $vals[] = $id;
        $pdo->prepare("UPDATE `$table` SET " . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
        if (function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
            [$t, $aid, $name, $ip] = bcrf_audit_actor();
            bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $name, 'ip' => $ip, 'action' => 'update',
                'object_type' => $table, 'object_id' => (string)$id, 'object_label' => (string)$row['l'] . ($on ? ' turned on' : ' turned off'),
                'before' => [$col => $was ? 1 : 0], 'after' => [$col => $on ? 1 : 0]]);
        }
    }
    return ['label' => (string)$row['l'], 'was' => $was];
}

}
