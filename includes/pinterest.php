<?php
// includes/pinterest.php — Pinterest drafts from published recipes and public animals (Part A, Nov 5, 2026).
//
//   bcrf_pin_generate($pdo, 'recipe', 30, ['origin' => 'bulk', 'batch_id' => 4])
//       Makes (or refreshes) the draft Pin for one record: title, description, link, alt text, suggested
//       board and a branded 1000 × 1500 JPEG. Returns ['result' => created|updated|unchanged|skipped|failed,
//       'id' => target id, 'msg' => text]. Bulk and manual generation only ever make DRAFTS; nothing here
//       posts to Pinterest (that is Part B, which only sends approved rows).
//   bcrf_pin_batch_create($pdo, ['recipe', 'animal'], $userId)  → batch id (queues job pinterest.batch)
//   bcrf_pin_batch_step($pdo, $batchId)                          → works the next 10 records, checkpointed
//
// Rules (spec v1.0): only published recipes (status = 'published') and public animals (is_active = 1) that
// have a photo on this site. Copy comes only from the record's own fields (no invented facts). Text edited by
// hand is never overwritten; regenerating refreshes the photo, link and fingerprint. Approved and sent Pins
// are never changed: when their record changes they are only flagged (source_changed = 1).
// Tables: social_queue_targets, pinterest_batches (migration 0007). Times UTC.

if (!function_exists('bcrf_pin_generate')) {

require_once __DIR__ . '/pinterest_image.php';
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';
if (!function_exists('bcrf_utm_url'))  require_once __DIR__ . '/ga.php';

define('BCRF_PIN_BATCH_CHUNK', 10);
define('BCRF_PIN_IMAGE_DIR', 'img/pinterest');   // from the site root

/** True once migration 0007 has run. */
function bcrf_pinterest_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
                               AND table_name IN ('social_queue_targets', 'pinterest_batches')")->fetchColumn();
        return $ok = ($n === 2);
    } catch (Throwable $e) { return $ok = false; }
}

/** Board names suggested per content type (Settings keys pinterest_board_recipes / _animals). */
function bcrf_pin_board_names(PDO $pdo): array {
    $names = ['recipes' => 'Farmhouse Recipes', 'animals' => 'Meet Our Farm Animals'];
    try {
        $st = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('pinterest_board_recipes', 'pinterest_board_animals')");
        foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            if (trim((string)$v) !== '') $names[substr($k, strlen('pinterest_board_'))] = trim((string)$v);
        }
    } catch (Throwable $e) {}
    return $names;
}

/** Plain text from stored HTML: tags, entities, zero-width characters and extra spaces removed. */
function bcrf_pin_text(?string $html): string {
    $t = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', (string)$html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/** Cut to $max characters at a word boundary, adding … when cut. */
function bcrf_pin_clip(string $t, int $max): string {
    if (mb_strlen($t) <= $max) return $t;
    $cut = mb_substr($t, 0, $max - 1);
    if (preg_match('/^(.*[.!?])\s/us', $cut, $m) && mb_strlen($m[1]) > $max * 0.6) return $m[1];   // end on a full sentence
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, " ,;:-–—") . '…';
}

/** Eligible records of one type with id > $afterId, oldest first. */
function bcrf_pin_sources(PDO $pdo, string $type, int $afterId = 0, int $limit = 10): array {
    $limit = max(1, min(200, $limit));
    if ($type === 'recipe') {
        $st = $pdo->prepare("SELECT id FROM recipes WHERE status = 'published' AND slug <> '' AND COALESCE(featured_image, '') <> ''
                             AND id > ? ORDER BY id LIMIT $limit");
    } elseif ($type === 'animal') {
        $st = $pdo->prepare("SELECT id FROM animals WHERE is_active = 1 AND COALESCE(photo_url, '') <> '' AND id > ? ORDER BY id LIMIT $limit");
    } else return [];
    $st->execute([$afterId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function bcrf_pin_count_sources(PDO $pdo, string $type): int {
    if ($type === 'recipe') return (int)$pdo->query("SELECT COUNT(*) FROM recipes WHERE status = 'published' AND slug <> '' AND COALESCE(featured_image, '') <> ''")->fetchColumn();
    if ($type === 'animal') return (int)$pdo->query("SELECT COUNT(*) FROM animals WHERE is_active = 1 AND COALESCE(photo_url, '') <> ''")->fetchColumn();
    return 0;
}

/** One record with what the copy needs, or null. Includes unpublished/inactive rows (eligibility is checked separately). */
function bcrf_pin_record(PDO $pdo, string $type, int $id): ?array {
    if ($type === 'recipe') {
        $st = $pdo->prepare("SELECT id, title, slug, status, featured_image, description, prep_time, cook_time, recipe_yield FROM recipes WHERE id = ?");
    } elseif ($type === 'animal') {
        $st = $pdo->prepare("SELECT a.id, a.name, a.tagline, a.bio, a.fun_fact, a.photo_url, a.photo_alt_text, a.is_active,
                                    b.breed_name AS breed, b.tag_line AS breed_tagline, c.class_name AS classification
                             FROM animals a LEFT JOIN breeds b ON b.id = a.breed_id LEFT JOIN classifications c ON c.id = a.classification_id
                             WHERE a.id = ?");
    } else return null;
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Why a record can't be pinned ('' when it can). */
function bcrf_pin_ineligible(string $type, ?array $r): string {
    if (!$r) return 'Record no longer exists';
    if ($type === 'recipe') {
        if (($r['status'] ?? '') !== 'published') return 'Recipe is not published';
        if (trim((string)$r['slug']) === '') return 'Recipe has no web address (slug)';
        if (trim((string)$r['featured_image']) === '') return 'Recipe has no photo';
    } else {
        if ((int)($r['is_active'] ?? 0) !== 1) return 'Animal is not public';
        if (trim((string)$r['photo_url']) === '') return 'Animal has no photo';
    }
    return '';
}

/** Public page for the record (matches the page's own canonical URL). */
function bcrf_pin_permalink(string $type, array $r): string {
    $base = rtrim(bcrf_farm_url(), '/');
    if ($type === 'animal') {
        $slug = function_exists('createSlug') ? createSlug((string)$r['name']) : strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$r['name']), '-'));
        return $base . '/petting-zoo/animals/' . (int)$r['id'] . '/' . ($slug !== '' ? $slug : 'animal') . '/';
    }
    return $base . '/recipes/' . rawurlencode((string)$r['slug']) . '/';
}

/**
 * Pin copy from the record's own fields. Returns title (≤100), description (≤500), link (with Pinterest UTM
 * tags), alt (≤500), board_key, kicker (image line above the title), photo (stored URL), focus_y.
 */
function bcrf_pin_copy(string $type, array $r): array {
    $farm = 'Bat Cave Road Farm';
    if ($type === 'recipe') {
        $title = bcrf_pin_clip(bcrf_pin_text($r['title']), 100);
        $parts = [];
        $desc  = bcrf_pin_text($r['description'] ?? '');
        $facts = [];
        if ((int)$r['prep_time'] > 0) $facts[] = 'Prep ' . (int)$r['prep_time'] . ' min';
        if ((int)$r['cook_time'] > 0) $facts[] = 'Cook ' . (int)$r['cook_time'] . ' min';
        $yield = trim((string)$r['recipe_yield']);
        if ($yield !== '') $facts[] = ctype_digit($yield) ? 'Serves ' . $yield : 'Makes ' . $yield;
        $tail  = ($facts ? implode(' · ', $facts) . '. ' : '') . "A farmhouse recipe from {$farm} in Old Fort, NC.";
        if ($desc !== '') $parts[] = bcrf_pin_clip($desc, max(80, 500 - mb_strlen($tail) - 1));
        $parts[] = $tail;
        return [
            'title' => $title, 'description' => bcrf_pin_clip(implode(' ', $parts), 500),
            'link'  => bcrf_utm_url(bcrf_pin_permalink('recipe', $r), 'pinterest', 'social', 'pin-recipe'),
            'alt'   => bcrf_pin_clip($title . ', a recipe from ' . $farm, 500),
            'board_key' => 'recipes', 'kicker' => 'Farmhouse recipe', 'photo' => (string)$r['featured_image'], 'focus_y' => 0.5,
            'image_title' => $title,
        ];
    }
    $name  = bcrf_pin_text($r['name']);
    $breed = bcrf_pin_text($r['breed'] ?? '');
    $title = 'Meet ' . $name . ($breed !== '' ? ', our ' . $breed : '');
    if (mb_strlen($title) > 100) $title = bcrf_pin_clip('Meet ' . $name, 100);
    $lead  = bcrf_pin_text($r['tagline'] ?? '') ?: bcrf_pin_text($r['breed_tagline'] ?? '');
    $bio   = bcrf_pin_text($r['bio'] ?? '');
    $fact  = bcrf_pin_text($r['fun_fact'] ?? '');
    $tail  = "Meet {$name} at the {$farm} petting zoo in Old Fort, NC.";
    $body  = trim(($lead !== '' ? rtrim($lead, '.!') . '. ' : '') . $bio);
    if ($fact !== '' && mb_strlen($body) + mb_strlen($fact) + mb_strlen($tail) < 470) $body = trim($body . ' Fun fact: ' . $fact);
    $desc  = ($body !== '' ? bcrf_pin_clip($body, max(80, 500 - mb_strlen($tail) - 1)) . ' ' : '') . $tail;
    $alt   = bcrf_pin_text($r['photo_alt_text'] ?? '') ?: ($name . ($breed !== '' ? ', a ' . $breed : '') . ', at ' . $farm);
    return [
        'title' => $title, 'description' => bcrf_pin_clip($desc, 500),
        'link'  => bcrf_utm_url(bcrf_pin_permalink('animal', $r), 'pinterest', 'social', 'pin-animal'),
        'alt'   => bcrf_pin_clip($alt, 500),
        'board_key' => 'animals', 'kicker' => 'Meet our farm animals', 'photo' => (string)$r['photo_url'], 'focus_y' => 0.35,
        'image_title' => 'Meet ' . $name,
    ];
}

/** The draft row for a record, or null. */
function bcrf_pin_target(PDO $pdo, string $type, int $id, string $variant = 'first'): ?array {
    $st = $pdo->prepare("SELECT * FROM social_queue_targets WHERE platform = 'pinterest' AND content_type = ? AND content_id = ? AND variant_key = ?");
    $st->execute([$type, $id, $variant]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function bcrf_pin_image_abs(?string $rel): ?string {
    if (!$rel) return null;
    $root = realpath(dirname(__DIR__));
    $f = $root ? $root . '/' . ltrim($rel, '/') : null;
    return ($f && str_starts_with($f, $root . '/' . BCRF_PIN_IMAGE_DIR . '/')) ? $f : null;
}

/**
 * Make or refresh the draft Pin for one record. $o: origin (bulk|manual|auto), batch_id, actor_id,
 * force (re-render even when nothing changed), reset_text (drop hand edits and use generated text).
 */
function bcrf_pin_generate(PDO $pdo, string $type, int $id, array $o = []): array {
    if (!bcrf_pinterest_ready($pdo)) return ['result' => 'failed', 'id' => 0, 'msg' => 'Run migration 0007 first (Settings › Database).'];
    if (!in_array($type, ['recipe', 'animal'], true)) return ['result' => 'skipped', 'id' => 0, 'msg' => 'Only recipes and animals can be pinned'];
    $r   = bcrf_pin_record($pdo, $type, $id);
    $row = bcrf_pin_target($pdo, $type, $id);
    $why = bcrf_pin_ineligible($type, $r);
    if ($why !== '') return ['result' => 'skipped', 'id' => (int)($row['id'] ?? 0), 'msg' => $why];

    $c    = bcrf_pin_copy($type, $r);
    $file = bcrf_pin_local_photo($c['photo']);
    if (!$file) return ['result' => 'failed', 'id' => (int)($row['id'] ?? 0), 'msg' => 'Photo is not a file on this website: ' . mb_substr($c['photo'], 0, 120)];
    $srcHash = hash_file('sha256', $file);
    $fp = hash('sha256', json_encode([BCRF_PIN_TEMPLATE_VERSION, $type, $id, $c['title'], $c['description'], $c['link'], $c['alt'], $c['image_title'], $srcHash], JSON_UNESCAPED_UNICODE));

    // Approved / sent / in-flight Pins are history: only flag that the record changed.
    if ($row && !in_array($row['status'], ['draft', 'failed', 'skipped'], true)) {
        if ($row['source_fingerprint'] !== $fp && !(int)$row['source_changed']) {
            $pdo->prepare("UPDATE social_queue_targets SET source_changed = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$row['id']]);
        }
        return ['result' => 'unchanged', 'id' => (int)$row['id'], 'msg' => 'Already ' . $row['status'] . ($row['source_fingerprint'] !== $fp ? '; record changed since (flagged)' : '')];
    }
    if ($row && $row['status'] === 'skipped' && empty($o['force'])) {
        return ['result' => 'unchanged', 'id' => (int)$row['id'], 'msg' => 'Skipped by an editor'];
    }
    $resetText = !empty($o['reset_text']);
    $keepText  = $row && $row['edited_at'] !== null && !$resetText;
    $imgAbs    = bcrf_pin_image_abs($row['image_path'] ?? null);
    if ($row && $row['source_fingerprint'] === $fp && $imgAbs && is_file($imgAbs) && empty($o['force']) && !$resetText) {
        return ['result' => 'unchanged', 'id' => (int)$row['id'], 'msg' => 'Up to date'];
    }

    // Image: the title on the picture follows a hand-edited title too
    $imgTitle = $keepText ? ($type === 'recipe' ? (string)$row['title'] : $c['image_title']) : $c['image_title'];
    $rel = BCRF_PIN_IMAGE_DIR . '/' . $type . '-' . $id . '-' . substr($fp, 0, 10) . '.jpg';
    $abs = realpath(dirname(__DIR__)) . '/' . $rel;
    $img = bcrf_pin_render($file, $abs, ['title' => $imgTitle, 'kicker' => $c['kicker'], 'focus_y' => $c['focus_y']]);
    if (!$img['ok']) {
        if ($row) $pdo->prepare("UPDATE social_queue_targets SET last_error_code = 'image', last_error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$img['error'], $row['id']]);
        return ['result' => 'failed', 'id' => (int)($row['id'] ?? 0), 'msg' => 'Image: ' . $img['error']];
    }
    if ($imgAbs && $imgAbs !== $abs && is_file($imgAbs)) @unlink($imgAbs);   // old draft image (never a sent one)

    $actor = isset($o['actor_id']) ? (int)$o['actor_id'] : null;
    if ($row) {
        $sql = "UPDATE social_queue_targets SET link_url = ?, board_key = ?, image_path = ?, image_source = ?, image_source_hash = ?,
                    template_version = ?, source_fingerprint = ?, source_changed = 0, last_error_code = NULL, last_error = NULL,
                    status = IF(status = 'failed', 'draft', status), updated_at = UTC_TIMESTAMP()"
             . ($keepText ? '' : ", title = ?, description = ?, alt_text = ?, edited_at = NULL, edited_by = NULL")
             . " WHERE id = ?";
        $args = [$c['link'], $c['board_key'], $rel, $c['photo'], $srcHash, BCRF_PIN_TEMPLATE_VERSION, $fp];
        if (!$keepText) array_push($args, $c['title'], $c['description'], $c['alt']);
        $args[] = $row['id'];
        $pdo->prepare($sql)->execute($args);
        return ['result' => 'updated', 'id' => (int)$row['id'], 'msg' => $keepText ? 'Photo and link refreshed; your text kept' : 'Regenerated'];
    }
    try {
        $pdo->prepare("INSERT INTO social_queue_targets
                (platform, content_type, content_id, variant_key, origin, batch_id, status, title, description, link_url, alt_text, board_key,
                 image_path, image_source, image_source_hash, template_version, source_fingerprint, created_by, created_at, updated_at)
             VALUES ('pinterest', ?, ?, 'first', ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
            ->execute([$type, $id, in_array($o['origin'] ?? '', ['bulk', 'auto', 'manual'], true) ? $o['origin'] : 'manual',
                       isset($o['batch_id']) ? (int)$o['batch_id'] : null, $c['title'], $c['description'], $c['link'], $c['alt'],
                       $c['board_key'], $rel, $c['photo'], $srcHash, BCRF_PIN_TEMPLATE_VERSION, $fp, $actor]);
        return ['result' => 'created', 'id' => (int)$pdo->lastInsertId(), 'msg' => 'Draft created'];
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {   // another run made it a moment ago
            @unlink($abs);
            $row = bcrf_pin_target($pdo, $type, $id);
            return ['result' => 'unchanged', 'id' => (int)($row['id'] ?? 0), 'msg' => 'Already exists'];
        }
        @unlink($abs);
        throw $e;
    }
}

// ── Batches ──────────────────────────────────────────────────────────────────────────────────────

/** The batch still queued or running, if any. */
function bcrf_pin_batch_active(PDO $pdo): ?array {
    return $pdo->query("SELECT * FROM pinterest_batches WHERE status IN ('queued', 'running') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Start a bulk run (or return the one already going). Queues job pinterest.batch. */
function bcrf_pin_batch_create(PDO $pdo, array $types, ?int $actorId): int {
    $types = array_values(array_intersect(['recipe', 'animal'], $types));
    if (!$types) throw new InvalidArgumentException('Pick recipes, animals or both.');
    if ($b = bcrf_pin_batch_active($pdo)) return (int)$b['id'];
    $total = 0;
    foreach ($types as $t) $total += bcrf_pin_count_sources($pdo, $t);
    $pdo->prepare("INSERT INTO pinterest_batches (content_types, status, total, cursor_json, created_by, created_at, updated_at)
                   VALUES (?, 'queued', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute([implode(',', $types), $total, json_encode(['type' => $types[0], 'after_id' => 0]), $actorId]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('bcrf_queue_push')) bcrf_queue_push('pinterest.batch', ['batch_id' => $id], ['dedupe' => 'pinterest.batch:' . $id]);
    return $id;
}

/**
 * Work the next chunk of a batch. Safe to call from the queue runner and the "Process next 10 now" button at
 * the same time (a database lock lets only one work a batch). Returns ['done' => bool, 'worked' => n, 'msg'].
 */
function bcrf_pin_batch_step(PDO $pdo, int $batchId, int $chunk = BCRF_PIN_BATCH_CHUNK): array {
    $lock = 'bcrf_pin_batch_' . $batchId;
    if ((int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 0)")->fetchColumn() !== 1) {
        return ['done' => false, 'worked' => 0, 'msg' => 'Another run is working on this batch right now.'];
    }
    try {
        $st = $pdo->prepare("SELECT * FROM pinterest_batches WHERE id = ?");
        $st->execute([$batchId]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) return ['done' => true, 'worked' => 0, 'msg' => 'Batch not found'];
        if (!in_array($b['status'], ['queued', 'running'], true)) return ['done' => true, 'worked' => 0, 'msg' => 'Batch is ' . $b['status']];
        if ($b['status'] === 'queued') $pdo->prepare("UPDATE pinterest_batches SET status = 'running', started_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$batchId]);

        $types  = array_values(array_filter(explode(',', (string)$b['content_types'])));
        $cur    = json_decode((string)$b['cursor_json'], true) ?: ['type' => $types[0] ?? 'recipe', 'after_id' => 0];
        $log    = json_decode((string)$b['log_json'], true) ?: [];
        $cnt    = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $left   = max(1, $chunk);
        $done   = false;
        $ti     = array_search($cur['type'], $types, true);
        $ti     = $ti === false ? 0 : $ti;

        while ($left > 0) {
            $type = $types[$ti] ?? null;
            if ($type === null) { $done = true; break; }
            $ids = bcrf_pin_sources($pdo, $type, (int)$cur['after_id'], $left);
            if (!$ids) { $ti++; $cur = ['type' => $types[$ti] ?? $type, 'after_id' => 0]; if (!isset($types[$ti])) { $done = true; break; } continue; }
            foreach ($ids as $rid) {
                try { $r = bcrf_pin_generate($pdo, $type, $rid, ['origin' => 'bulk', 'batch_id' => $batchId, 'actor_id' => $b['created_by'] !== null ? (int)$b['created_by'] : null]); }
                catch (Throwable $e) { $r = ['result' => 'failed', 'id' => 0, 'msg' => $e->getMessage()]; }
                $cnt['processed']++;
                $k = ['created' => 'created', 'updated' => 'updated', 'unchanged' => 'skipped', 'skipped' => 'skipped', 'failed' => 'failed'][$r['result']] ?? 'failed';
                $cnt[$k]++;
                $log[] = ['t' => $type, 'id' => $rid, 'r' => $r['result'], 'm' => mb_substr((string)$r['msg'], 0, 160)];
                $cur = ['type' => $type, 'after_id' => $rid];
                $left--;
            }
        }
        $log = array_slice($log, -50);
        $pdo->prepare("UPDATE pinterest_batches SET processed = processed + ?, created_count = created_count + ?, updated_count = updated_count + ?,
                           skipped_count = skipped_count + ?, failed_count = failed_count + ?, cursor_json = ?, log_json = ?,
                           status = IF(?, 'done', status), finished_at = IF(?, UTC_TIMESTAMP(), finished_at), updated_at = UTC_TIMESTAMP()
                       WHERE id = ? AND status = 'running'")
            ->execute([$cnt['processed'], $cnt['created'], $cnt['updated'], $cnt['skipped'], $cnt['failed'],
                       json_encode($cur), json_encode($log, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), (int)$done, (int)$done, $batchId]);
        return ['done' => $done, 'worked' => $cnt['processed'], 'msg' => $done ? 'Batch finished' : 'Checkpoint saved'];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")");
    }
}

}
