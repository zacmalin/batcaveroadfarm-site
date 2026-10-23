<?php
require_once '../../config.php';

if (!function_exists('bcrfAgeLabel')) {
function bcrfAgeLabel(?string $dob): string {
    if (!$dob || $dob === '0000-00-00') return '';
    try { $d = (new DateTime())->diff(new DateTime($dob)); }
    catch (Throwable $e) { return ''; }
    if ($d->y >= 1) return $d->y . ' yr' . ($d->y > 1 ? 's' : '');
    if ($d->m >= 1) return $d->m . ' mo';
    $days = max(1, (int)$d->days);
    return $days . ' day' . ($days !== 1 ? 's' : '');
}
}
if (!function_exists('bcrfNormImg')) {
function bcrfNormImg(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('#^https?://#i', $v) || $v[0] === '/') return $v;
    return '/' . $v;
}
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$animal = false;
if ($id) {
    $st = $pdo->prepare(
        "SELECT a.id, a.name, a.tagline, a.bio, a.fun_fact, a.photo_url, a.photo_alt_text, a.date_of_birth,
                b.id AS breed_id, b.breed_name AS breed, b.slug AS breed_slug, b.tag_line AS breed_tagline,
                c.class_name AS classification
         FROM animals a
         LEFT JOIN breeds b          ON a.breed_id = b.id
         LEFT JOIN classifications c ON a.classification_id = c.id
         WHERE a.id = ? AND a.is_active = 1"
    );
    $st->execute([$id]);
    $animal = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$animal) {
    http_response_code(404);
    $pageTitle    = "Animal Not Found - Bat Cave Road Farm";
    $metaDesc     = "We couldn't find that animal.";
    $canonicalUrl = "https://batcaveroadfarm.com/petting-zoo/";
    $pageCSS      = "https://batcaveroadfarm.com/css/min.pettingzoo.css";
    require_once '../../includes/head.php';
    require_once '../../includes/header.php';
    ?>
    <div class="content-wrap" style="padding:34px">
      <h1 style="font-family:'Rye',serif;color:var(--cream-dark)">We couldn't find that animal</h1>
      <p style="font-family:'Lora',serif;color:var(--cream-dark)">Come meet everyone on the <a href="/petting-zoo/" style="color:var(--gold-bright);font-weight:700">petting zoo page →</a></p>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

$name      = (string)$animal['name'];
$img       = bcrfNormImg((string)($animal['photo_url'] ?? ''));
$imgAlt    = trim((string)($animal['photo_alt_text'] ?? '')) ?: $name;
$age       = bcrfAgeLabel($animal['date_of_birth'] ?? null);
$breedName = trim((string)($animal['breed'] ?? ''));
$breedSlug = trim((string)($animal['breed_slug'] ?? '')) !== '' ? $animal['breed_slug'] : createSlug($breedName);
$breedTag  = trim(strip_tags((string)($animal['breed_tagline'] ?? '')));
$breedHref = !empty($animal['breed_id'])
           ? '/petting-zoo/breeds/' . (int)$animal['breed_id'] . '/' . $breedSlug . '/'
           : '/petting-zoo/';
$tagline    = trim((string)($animal['tagline'] ?? ''));
$bio        = trim((string)($animal['bio'] ?? ''));
$funFact    = trim((string)($animal['fun_fact'] ?? ''));
$displayTag = $tagline !== '' ? $tagline : $breedTag;
$breadcrumbs = [['label' => 'Petting Zoo', 'url' => '/petting-zoo/']];
if ($breedName !== '') { $breadcrumbs[] = ['label' => $breedName, 'url' => $breedHref]; }
$breadcrumbs[] = ['label' => $name];

$pageTitle    = $name . " - Petting Zoo - Bat Cave Road Farm";
$metaDesc     = $tagline !== ''
    ? "Meet " . $name . " — " . $tagline . " — at the Bat Cave Road Farm petting zoo in Old Fort, NC."
    : "Meet " . $name . ($breedName !== '' ? ", one of our " . $breedName : "") . " at the Bat Cave Road Farm petting zoo in Old Fort, NC.";
$canonicalUrl = "https://batcaveroadfarm.com/petting-zoo/animals/" . (int)$animal['id'] . "/" . createSlug($name) . "/";
$ogImage      = !empty($animal['photo_url']) ? $animal['photo_url'] : "https://batcaveroadfarm.com/img/og-pettingzoo.jpg";
$pageCSS      = "https://batcaveroadfarm.com/css/min.pettingzoo.css";
if ($img !== '') { $preloadImage = $img; }
$pageJS       = ["https://batcaveroadfarm.com/js/min.lightbox.js", "https://batcaveroadfarm.com/js/min.share.js"];

// ---- Share controls (Web Share API + Facebook / X / email / copy) ----
$svgShare = [
  'share' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg>',
  'fb' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.49 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.49h-2.8V24C19.62 23.1 24 18.1 24 12.07z"/></svg>',
  'x' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.66l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.45-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117l11.966 15.644z"/></svg>',
  'mail' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
  'link' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>',
];
$renderAnimalShare = function () use ($canonicalUrl, $name, $svgShare) {
    $u    = rawurlencode($canonicalUrl);
    $t    = rawurlencode('Meet ' . $name . ' at Bat Cave Road Farm');
    $body = rawurlencode('Meet ' . $name . ' at Bat Cave Road Farm — ' . $canonicalUrl);
    $hu   = htmlspecialchars($canonicalUrl);
    $ht   = htmlspecialchars('Meet ' . $name);
    return '<div class="share-bar share-bar--full" data-share-url="' . $hu . '" data-share-title="' . $ht . '" data-share-type="animal" role="group" aria-label="Share ' . htmlspecialchars($name) . '">'
        . '<span class="share-label">Share ' . htmlspecialchars($name) . '</span>'
        . '<button type="button" class="share-btn share-native" data-share-native hidden aria-label="Share">' . $svgShare['share'] . '</button>'
        . '<a class="share-btn share-fb" href="https://www.facebook.com/sharer/sharer.php?u=' . $u . '" target="_blank" rel="noopener" data-share="facebook" aria-label="Share on Facebook">' . $svgShare['fb'] . '</a>'
        . '<a class="share-btn share-x" href="https://twitter.com/intent/tweet?url=' . $u . '&amp;text=' . $t . '" target="_blank" rel="noopener" data-share="x" aria-label="Share on X">' . $svgShare['x'] . '</a>'
        . '<a class="share-btn share-email" href="mailto:?subject=' . $t . '&amp;body=' . $body . '" data-share="email" aria-label="Share by email">' . $svgShare['mail'] . '</a>'
        . '<button type="button" class="share-btn share-copy" data-share-copy data-share="copy" aria-label="Copy link">' . $svgShare['link'] . '</button>'
        . '</div>';
};

require_once '../../includes/head.php';
require_once '../../includes/header.php';
?>
<div class="content-wrap">
  <p class="breed-back"><a href="<?= htmlspecialchars($breedHref) ?>">← Back to <?= htmlspecialchars($breedName !== '' ? $breedName : 'the animals') ?></a></p>

  <div class="animal-detail reveal">
    <div class="ad-grid">
      <?php if ($img !== ''): ?>
        <img class="ad-photo img-zoom" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($imgAlt) ?>" fetchpriority="high" decoding="async">
      <?php endif; ?>
      <div class="ad-info">
        <div class="ad-pills">
          <?php if (!empty($animal['classification'])): ?><span class="ad-pill"><?= htmlspecialchars($animal['classification']) ?></span><?php endif; ?>
          <?php if ($breedName !== ''): ?><a class="ad-pill ad-pill-link" href="<?= htmlspecialchars($breedHref) ?>"><?= htmlspecialchars($breedName) ?></a><?php endif; ?>
        </div>
        <h1><?= htmlspecialchars($name) ?></h1>
        <?php if ($displayTag !== ''): ?><p class="ad-tagline"><?= htmlspecialchars($displayTag) ?></p><?php endif; ?>
        <?php if ($age !== ''): ?><p class="ad-facts"><?= htmlspecialchars($age) ?> old</p><?php endif; ?>
        <?php if ($bio !== ''): ?><p class="ad-bio"><?= nl2br(htmlspecialchars($bio)) ?></p><?php endif; ?>
      </div>
    </div>
    <div class="ad-footer">
      <?php if ($funFact !== ''): ?><p class="ad-funfact"><strong>Fun fact:</strong> <?= htmlspecialchars($funFact) ?></p><?php endif; ?>
      <p class="ad-hello">Come say hello to <?= htmlspecialchars($name) ?> on your next visit to the farm!</p>
      <div class="ad-actions">
        <a class="cta-btn" href="<?= htmlspecialchars($breedHref) ?>">← Meet more <?= htmlspecialchars($breedName !== '' ? $breedName : 'animals') ?></a>
        <a class="cta-btn" href="/petting-zoo/">All the animals</a>
      </div>
    </div>
  </div>
  <?= $renderAnimalShare() ?>
</div>

<?php if ($img !== ''): ?>
<div class="lightbox" id="animalLightbox" role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars($name) ?>">
  <button type="button" class="lightbox-close" aria-label="Close">&times;</button>
  <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($imgAlt) ?>" loading="lazy" decoding="async">
</div>
<?php endif; ?>

<?php
require_once '../../includes/related.php';
echo bcrf_visit_cta_html((string)($name ?? ''));
?>
<?php require_once '../../includes/footer.php'; ?>
