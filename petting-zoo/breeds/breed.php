<?php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
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
$breed = false;
if ($id) {
    $st = $pdo->prepare("SELECT * FROM breeds WHERE id = ? AND is_active = 1");
    $st->execute([$id]);
    $breed = $st->fetch(PDO::FETCH_ASSOC);
}

if (!$breed) {
    http_response_code(404);
    $pageTitle    = "Breed Not Found - Bat Cave Road Farm";
    $metaDesc     = "We couldn't find that breed.";
    $canonicalUrl = (bcrf_farm_url() . "/petting-zoo/");
    $pageCSS      = (bcrf_farm_url() . "/css/min.pettingzoo.css");
    require_once '../../includes/head.php';
    require_once '../../includes/header.php';
    ?>
    <div class="content-wrap" style="padding:34px">
      <h1 style="font-family:'Rye',serif;color:var(--cream-dark)">We couldn't find that breed</h1>
      <p style="font-family:'Lora',serif;color:var(--cream-dark)">Come meet everyone on the <a href="/petting-zoo/" style="color:var(--gold-bright);font-weight:700">petting zoo page →</a></p>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

$animals = [];
try {
    $ast = $pdo->prepare(
        "SELECT a.id, a.name, a.tagline, a.photo_url, a.date_of_birth,
                c.class_name AS classification
         FROM animals a
         LEFT JOIN classifications c ON a.classification_id = c.id
         WHERE a.breed_id = ? AND a.is_active = 1
         ORDER BY a.name ASC"
    );
    $ast->execute([$id]);
    $animals = $ast->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $animals = []; }

$breedName = (string)$breed['breed_name'];
// "About <breed>" facts from Wikipedia/Wikidata (includes/wiki_facts.php; cached, hidden if nothing found).
$wiki = null;
try {
    require_once __DIR__ . '/../../includes/wiki_facts.php';
    $__kind = '';
    try {
        $__k = $pdo->prepare("SELECT c.class_name FROM breed_classifications bc JOIN classifications c ON c.id = bc.classification_id WHERE bc.breed_id = ? LIMIT 1");
        $__k->execute([(int)$breed['id']]);
        $__kind = (string)$__k->fetchColumn();
    } catch (Throwable $e) { $__kind = ''; }
    if ($__kind === '' && !empty($animals[0]['classification'])) $__kind = (string)$animals[0]['classification'];
    $wiki = bcrf_wiki_facts($pdo, (string)($breed['wiki_title'] ?? ''), $breedName, $__kind);
} catch (Throwable $e) { error_log('[wiki] ' . $e->getMessage()); $wiki = null; }
$breedSlug = trim((string)($breed['slug'] ?? '')) !== '' ? $breed['slug'] : createSlug($breedName);
$breadcrumbs = [
    ['label' => 'Petting Zoo', 'url' => '/petting-zoo/'],
    ['label' => $breedName],
];
$pageTitle    = "Meet Our " . $breedName . " - Bat Cave Road Farm";
$metaDesc     = mb_strimwidth(trim(strip_tags((string)($breed['description'] ?? ''))) ?: ("Meet our " . $breedName . " at the Bat Cave Road Farm petting zoo in Old Fort, NC."), 0, 155, '…');
$canonicalUrl = (bcrf_farm_url() . "/petting-zoo/breeds/") . (int)$breed['id'] . "/" . $breedSlug . "/";
$ogImage      = !empty($breed['image_url']) ? $breed['image_url'] : (bcrf_farm_url() . "/img/og-pettingzoo.jpg");
$pageCSS      = (bcrf_farm_url() . "/css/min.pettingzoo.css");

require_once '../../includes/head.php';
require_once '../../includes/header.php';
?>
<div class="content-wrap">
  <p class="breed-back"><a href="/petting-zoo/">← All the animals</a></p>
  <h1 class="section-heading reveal">Meet Our <?= htmlspecialchars($breedName) ?></h1>

  <?php if (empty($animals)): ?>
    <p class="stand-empty">No <?= htmlspecialchars($breedName) ?> are out to visit right now — check back soon!</p>
  <?php else: ?>
    <div class="animal-card-grid">
      <?php foreach ($animals as $a):
          $aImg  = bcrfNormImg((string)($a['photo_url'] ?? ''));
          $aSlug = createSlug((string)($a['name'] ?? ''));
          $aHref = '/petting-zoo/animals/' . (int)$a['id'] . '/' . $aSlug . '/';
          $age   = bcrfAgeLabel($a['date_of_birth'] ?? null);
          $meta  = array_filter([$a['classification'] ?? '', $age]);
      ?>
        <a class="animal-card reveal" href="<?= htmlspecialchars($aHref) ?>">
          <?php if ($aImg !== ''): ?>
            <img src="<?= htmlspecialchars($aImg) ?>" alt="<?= htmlspecialchars($a['name'] . ', a ' . $breedName . ' at the Bat Cave Road Farm petting zoo in Old Fort, NC') ?>" loading="lazy" decoding="async">
          <?php endif; ?>
          <div class="ac-body">
            <h2><?= htmlspecialchars($a['name']) ?></h2>
            <?php $aTag = trim((string)($a['tagline'] ?? '')); if ($aTag !== ''): ?><p class="ac-tagline"><?= htmlspecialchars($aTag) ?></p><?php endif; ?>
            <?php if ($meta): ?><p class="ac-meta"><?= htmlspecialchars(implode(' · ', $meta)) ?></p><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php
    $descHtml = (string)($breed['description'] ?? '');
    if (trim($descHtml) !== ''):
      // shift each heading down one level so the page <h1> ("Meet Our X") stays the only top-level heading
      $descHtml = preg_replace_callback('/<(\/?)(h)([1-5])\b/i', static function ($m) {
          return '<' . $m[1] . $m[2] . ((int)$m[3] + 1);
      }, $descHtml);
  ?>
    <div class="breed-history reveal">
      <?= $descHtml ?>
    </div>
  <?php endif; ?>

  <?php if ($wiki): ?>
    <section class="ad-wiki breed-wiki reveal" aria-labelledby="brWikiH">
      <h2 id="brWikiH">About <?= htmlspecialchars($wiki['title']) ?></h2>
      <p><?= htmlspecialchars(bcrf_wiki_short($wiki['extract'])) ?></p>
      <?php if (!empty($wiki['facts'])): ?>
      <dl class="ad-wiki-facts">
        <?php foreach ($wiki['facts'] as $k => $v): ?><div><dt><?= htmlspecialchars($k) ?></dt><dd><?= htmlspecialchars($v) ?></dd></div><?php endforeach; ?>
      </dl>
      <?php endif; ?>
      <p class="ad-wiki-src">Source: <a href="<?= htmlspecialchars($wiki['url']) ?>" target="_blank" rel="noopener">Wikipedia</a> &amp; Wikidata, <a href="https://creativecommons.org/licenses/by-sa/4.0/" target="_blank" rel="noopener license">CC BY-SA 4.0</a></p>
    </section>
  <?php endif; ?>

  <div class="ctaDiv" style="text-align:center;padding-top:8px">
    <a href="/petting-zoo/" class="cta-btn">Back to the Petting Zoo</a>
  </div>
</div>

<?php
require_once '../../includes/related.php';
echo bcrf_visit_cta_html((string)($breedName ?? ''));
?>
<?php require_once '../../includes/footer.php'; ?>
