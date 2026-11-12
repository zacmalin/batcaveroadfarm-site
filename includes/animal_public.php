<?php
// includes/animal_public.php — Farm OS Phase 5 slice 9: public profiles (Nov 12, 2026; migration 0048; 08 › Public profiles).
//   What the website shows for an animal: published or not (animals.is_active), tagline, bio, fun facts (one per line in
//   animals.fun_fact), how its age shows (animals.birthday_display), the main photo (animals.photo_url) and more photos
//   (animal_photos). Used by the Public tab on admin/animals.php, admin/animal_public.php and petting-zoo/animals/animal.php.
//   Nothing private ever comes from here: no health, weights, costs, purchase details, incidents, tags or microchips.
//   Animals that have left the farm can't be published (leaving switches the page off; see includes/animals.php).
if (!function_exists('bcrf_public_ready')) {

require_once __DIR__ . '/animals.php';

define('BCRF_PUBLIC_BIRTHDAY', ['age' => 'Age ("2 yrs old")', 'birthday' => 'Birthday ("Birthday: March 3")', 'none' => 'Neither']);
define('BCRF_PUBLIC_PHOTO_MAX', 8);       // extra photos, besides the main one
define('BCRF_PUBLIC_PHOTO_BYTES', 15 * 1024 * 1024);

function bcrf_public_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM animal_photos LIMIT 0"); $pdo->query("SELECT birthday_display FROM animals LIMIT 0"); return bcrf_animals_ready($pdo); }
    catch (Throwable $e) { return false; }
}

function bcrf_public_text($v, int $max): ?string {
    $v = trim(str_replace("\r\n", "\n", (string)$v));
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/** Fun facts: one per line, blanks dropped, up to 6. */
function bcrf_public_facts(?string $v): array {
    $out = [];
    foreach (preg_split('/\n+/', str_replace("\r\n", "\n", (string)$v)) as $l) {
        $l = trim(preg_replace('/^[-*•]\s*/u', '', trim($l)));
        if ($l !== '') $out[] = $l;
    }
    return array_slice($out, 0, 6);
}

/**
 * The age line for the website. 'age': "2 yrs old" ("About 2 yrs old" if the birth date is a guess); 'birthday':
 * "Birthday: March 3" (falls back to the age when the date is a guess); 'none': ''.
 */
function bcrf_public_age_line(?string $dob, bool $estimated, string $display, ?string $today = null): string {
    if (!$dob || $dob === '0000-00-00' || $display === 'none') return '';
    $age = preg_replace('/^(\d+ \w+) .*$/', '$1', bcrf_animal_age($dob, $today));   // "2 yrs 3 mos" → "2 yrs"
    if ($display === 'birthday' && !$estimated) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if ($d) return 'Birthday: ' . $d->format('F j') . ($age !== '' ? ' (' . $age . ' old)' : '');
    }
    if ($age === '') return '';
    return ($estimated ? 'About ' : '') . $age . ' old';
}

function bcrf_public_photos(PDO $pdo, int $animalId): array {
    $st = $pdo->prepare("SELECT * FROM animal_photos WHERE animal_id = ? ORDER BY sort_order, id");
    $st->execute([$animalId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** What's missing before the page looks finished: [label, ...]. Publishing is allowed anyway (except for animals that left). */
function bcrf_public_missing(array $a): array {
    $m = [];
    if (trim((string)($a['photo_url'] ?? '')) === '') $m[] = 'a main photo';
    elseif (trim((string)($a['photo_alt_text'] ?? '')) === '') $m[] = 'a description of the main photo (for screen readers)';
    if (trim((string)($a['bio'] ?? '')) === '') $m[] = 'a bio';
    if (!bcrf_public_facts($a['fun_fact'] ?? '')) $m[] = 'a fun fact';
    return $m;
}

/** Save the words. $in: tagline, bio, fun_fact, birthday_display, photo_alt_text. Returns the fields that changed. */
function bcrf_public_save(PDO $pdo, int $id, array $in): array {
    $a = bcrf_animal_get($pdo, $id);
    if (!$a) throw new BcrfAnimalError('That animal is not on the roster.');
    $bd = (string)($in['birthday_display'] ?? 'age');
    if (!isset(BCRF_PUBLIC_BIRTHDAY[$bd])) throw new BcrfAnimalError('Pick what the website shows for its age.');
    $facts = bcrf_public_facts($in['fun_fact'] ?? '');
    foreach ($facts as $f) if (mb_strlen($f) > 300) throw new BcrfAnimalError('Keep each fun fact under 300 characters.');
    $new = [
        'tagline'          => bcrf_public_text($in['tagline'] ?? '', 255),
        'bio'              => bcrf_public_text($in['bio'] ?? '', 5000),
        'fun_fact'         => $facts ? implode("\n", $facts) : null,
        'birthday_display' => $bd,
        'photo_alt_text'   => bcrf_public_text($in['photo_alt_text'] ?? '', 255),
    ];
    $changed = [];
    foreach ($new as $k => $v) if ((string)($a[$k] ?? '') !== (string)$v) $changed[] = $k;
    if ($changed) {
        $pdo->prepare("UPDATE animals SET tagline = ?, bio = ?, fun_fact = ?, birthday_display = ?, photo_alt_text = ? WHERE id = ?")
            ->execute([$new['tagline'], $new['bio'], $new['fun_fact'], $new['birthday_display'], $new['photo_alt_text'], $id]);
    }
    return $changed;
}

/** Put the page on the website, or take it off. An animal that has left the farm can't go on. */
function bcrf_public_set(PDO $pdo, int $id, bool $on): void {
    $a = bcrf_animal_get($pdo, $id);
    if (!$a) throw new BcrfAnimalError('That animal is not on the roster.');
    if ($on && bcrf_animal_is_leaving((string)$a['status'])) throw new BcrfAnimalError('It has left the farm, so its page stays off the website.');
    if ((int)$a['is_active'] === ($on ? 1 : 0)) throw new BcrfAnimalError($on ? 'It is already on the website.' : 'It is already off the website.');
    $pdo->prepare("UPDATE animals SET is_active = ? WHERE id = ?")->execute([$on ? 1 : 0, $id]);
}

// Photos

/** Public folder for animal photos: <site>/img/animals (BCRF_PUBLIC_IMG_DIR overrides it for tests). */
function bcrf_public_photo_dir(): string {
    $dir = getenv('BCRF_PUBLIC_IMG_DIR') ?: dirname(__DIR__) . '/img/animals';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return rtrim($dir, '/');
}

/**
 * Save one uploaded photo: checked to be a real image, turned upright, shrunk to 1600px and saved as WebP with the
 * animal's name in the file name. $main: make it the main photo (the old main moves to the extra photos). With no main
 * photo yet, the first upload becomes the main one. Returns ['path', 'main' => bool].
 * ($GLOBALS['bcrf_public_test_upload'] lets the tests pass a plain file.)
 */
function bcrf_public_photo_add(PDO $pdo, int $id, array $file, string $alt, bool $main, ?int $uid): array {
    $a = bcrf_animal_get($pdo, $id);
    if (!$a) throw new BcrfAnimalError('That animal is not on the roster.');
    $hasMain = trim((string)$a['photo_url']) !== '';
    $main = $main || !$hasMain;
    if (!$main && count(bcrf_public_photos($pdo, $id)) >= BCRF_PUBLIC_PHOTO_MAX) throw new BcrfAnimalError('Up to ' . BCRF_PUBLIC_PHOTO_MAX . ' more photos besides the main one. Remove one first.');
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) throw new BcrfAnimalError('Pick a photo first.');
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new BcrfAnimalError('That photo is too big to upload. Try a smaller one.');
    if ($err !== UPLOAD_ERR_OK) throw new BcrfAnimalError('The photo did not upload. Try again.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (empty($GLOBALS['bcrf_public_test_upload']) && !is_uploaded_file($tmp)) throw new BcrfAnimalError('The photo did not upload. Try again.');
    $size = (int)@filesize($tmp);
    if ($size <= 0 || $size > BCRF_PUBLIC_PHOTO_BYTES) throw new BcrfAnimalError('Photos can be up to 15 MB.');
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) throw new BcrfAnimalError('That file is not a photo (JPEG, PNG or WebP).');
    if ($info[0] * $info[1] > 50000000) throw new BcrfAnimalError('That photo is too large to open.');
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
        default        => false,
    };
    if (!$im) throw new BcrfAnimalError('That photo could not be opened.');
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) $im = $r; }
    }
    $w = imagesx($im); $h = imagesy($im);
    $scale = min(1, 1600 / max($w, $h));
    if ($scale < 1) {
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $c = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($c, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $im = $c; $w = $nw; $h = $nh;
    }
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$a['name'])), '-') ?: 'animal';
    $file = substr($slug, 0, 40) . '-' . $id . '-' . bin2hex(random_bytes(4)) . '.webp';
    $dir = bcrf_public_photo_dir();
    if (!is_dir($dir)) throw new BcrfAnimalError('Could not save the photo (folder).');
    if (!@imagewebp($im, $dir . '/' . $file, 82)) throw new BcrfAnimalError('Could not save the photo.');
    @chmod($dir . '/' . $file, 0644);
    $path = '/img/animals/' . $file;
    $alt = (string)bcrf_public_text($alt, 255);
    if ($main) {
        $pdo->beginTransaction();
        try {
            if ($hasMain) bcrf_public_photo_insert($pdo, $id, (string)$a['photo_url'], (string)($a['photo_alt_text'] ?? ''), 0, 0, $uid, true);
            $pdo->prepare("UPDATE animals SET photo_url = ?, photo_alt_text = ? WHERE id = ?")
                ->execute([$path, $alt !== '' ? $alt : ($a['photo_alt_text'] ?? null), $id]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    } else {
        bcrf_public_photo_insert($pdo, $id, $path, $alt, $w, $h, $uid);
    }
    return ['path' => $path, 'main' => $main];
}

function bcrf_public_photo_insert(PDO $pdo, int $id, string $path, string $alt, int $w, int $h, ?int $uid, bool $first = false): int {
    $st = $pdo->prepare("SELECT COALESCE(" . ($first ? "MIN(sort_order) - 1" : "MAX(sort_order) + 1") . ", 0) FROM animal_photos WHERE animal_id = ?");
    $st->execute([$id]);
    $sort = (int)$st->fetchColumn();
    $pdo->prepare("INSERT INTO animal_photos (animal_id, path, alt_text, width, height, sort_order, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([$id, $path, $alt !== '' ? $alt : null, $w, $h, $sort, $uid]);
    return (int)$pdo->lastInsertId();
}

function bcrf_public_photo_get(PDO $pdo, int $animalId, int $photoId): array {
    $st = $pdo->prepare("SELECT * FROM animal_photos WHERE id = ? AND animal_id = ?");
    $st->execute([$photoId, $animalId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new BcrfAnimalError('That photo was not found. Reload the page.');
    return $p;
}

/** Make an extra photo the main one; the old main photo takes its place in the list. */
function bcrf_public_photo_make_main(PDO $pdo, int $id, int $photoId): void {
    $a = bcrf_animal_get($pdo, $id);
    if (!$a) throw new BcrfAnimalError('That animal is not on the roster.');
    $p = bcrf_public_photo_get($pdo, $id, $photoId);
    $pdo->beginTransaction();
    try {
        if (trim((string)$a['photo_url']) !== '') {
            $pdo->prepare("UPDATE animal_photos SET path = ?, alt_text = ? WHERE id = ?")
                ->execute([(string)$a['photo_url'], ($a['photo_alt_text'] ?? '') !== '' ? $a['photo_alt_text'] : null, $photoId]);
        } else {
            $pdo->prepare("DELETE FROM animal_photos WHERE id = ?")->execute([$photoId]);
        }
        $pdo->prepare("UPDATE animals SET photo_url = ?, photo_alt_text = ? WHERE id = ?")->execute([$p['path'], $p['alt_text'], $id]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/** Change an extra photo's description. */
function bcrf_public_photo_alt(PDO $pdo, int $id, int $photoId, string $alt): void {
    bcrf_public_photo_get($pdo, $id, $photoId);
    $alt = bcrf_public_text($alt, 255);
    $pdo->prepare("UPDATE animal_photos SET alt_text = ? WHERE id = ?")->execute([$alt, $photoId]);
}

/** Take an extra photo off the page. The picture file is kept (old social posts may still point to it). */
function bcrf_public_photo_remove(PDO $pdo, int $id, int $photoId): void {
    bcrf_public_photo_get($pdo, $id, $photoId);
    $pdo->prepare("DELETE FROM animal_photos WHERE id = ?")->execute([$photoId]);
}

/** Move an extra photo one place earlier (-1) or later (+1). */
function bcrf_public_photo_move(PDO $pdo, int $id, int $photoId, int $dir): void {
    bcrf_public_photo_get($pdo, $id, $photoId);
    $ids = array_map(fn($p) => (int)$p['id'], bcrf_public_photos($pdo, $id));
    $i = array_search($photoId, $ids, true);
    $j = $i + ($dir < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) return;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $st = $pdo->prepare("UPDATE animal_photos SET sort_order = ? WHERE id = ?");
    foreach ($ids as $n => $pid) $st->execute([$n, $pid]);
}

/** Every animal still on the farm, with whether its page is up and what's missing. For the Public pages list. */
function bcrf_public_overview(PDO $pdo): array {
    $rows = $pdo->query("SELECT a.id, a.name, a.status, a.is_active, a.photo_url, a.photo_alt_text, a.bio, a.fun_fact, a.tagline,
                                c.class_name, b.breed_name, (SELECT COUNT(*) FROM animal_photos p WHERE p.animal_id = a.id) AS photo_count
                         FROM animals a LEFT JOIN classifications c ON c.id = a.classification_id LEFT JOIN breeds b ON b.id = a.breed_id
                         WHERE a.status NOT IN ('" . implode("','", BCRF_ANIMAL_LEAVING) . "')
                         ORDER BY a.is_active DESC, a.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['missing'] = bcrf_public_missing($r);
    return $rows;
}

}
