<?php
// admin/migrations/0049_animal_photo_alt.php — Farm OS Phase 5 slice 9 follow-up (Nov 12, 2026): fill in the main photo
// description (animals.photo_alt_text, read by screen readers) for every animal that has a photo but no description,
// from its name, breed and species: "Hazel, a Nigerian Dwarf goat at Bat Cave Road Farm". Only blanks are filled;
// a description someone wrote is never changed. Edit any of them on the animal's Public tab. Safe to repeat.
return function (PDO $pdo): void {
    $rows = $pdo->query("SELECT a.id, a.name, b.breed_name, c.class_name
                         FROM animals a LEFT JOIN breeds b ON b.id = a.breed_id LEFT JOIN classifications c ON c.id = a.classification_id
                         WHERE TRIM(COALESCE(a.photo_url, '')) <> '' AND TRIM(COALESCE(a.photo_alt_text, '')) = ''")->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("UPDATE animals SET photo_alt_text = ? WHERE id = ? AND TRIM(COALESCE(photo_alt_text, '')) = ''");
    foreach ($rows as $r) {
        $name = trim((string)$r['name']);
        $breed = trim((string)$r['breed_name']);
        $kind = mb_strtolower(trim((string)$r['class_name']));
        if (in_array(mb_strtolower($breed), ['', 'unknown', 'mixed', 'mix', 'other', 'n/a'], true)) $breed = '';
        if ($breed !== '' && $kind !== '' && mb_stripos($breed, $kind) !== false) $kind = '';   // "Nigerian Dwarf Goat" already says goat
        $what = trim($breed . ' ' . $kind);
        if ($what === '') $what = 'farm animal';
        $article = preg_match('/^[aeiou]/i', $what) ? 'an' : 'a';
        $st->execute([mb_substr($name . ', ' . $article . ' ' . $what . ' at Bat Cave Road Farm', 0, 255), (int)$r['id']]);
    }
};
