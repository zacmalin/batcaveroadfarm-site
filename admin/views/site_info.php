<?php
// admin/views/site_info.php — "Site Info" central settings (accordion).
// Single source of truth for address, phones, emails, hours, season.
// POSTs back to admin.php?view=site_info with site_info_action; handled by
// handlers/site_info_save.php (wired in admin.php's POST dispatcher).

require_once __DIR__ . '/../../includes/site_info.php';

$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']; // 0..6

$tablesMissing = false;
try { $pdo->query("SELECT 1 FROM site_settings LIMIT 1"); }
catch (Throwable $e) { $tablesMissing = true; }

if (!$tablesMissing) {
    $settings = site_all_settings();
    $contacts = site_all_contacts();
    $phones   = array_values(array_filter($contacts, fn($c) => $c['contact_type'] === 'phone'));
    $emails   = array_values(array_filter($contacts, fn($c) => $c['contact_type'] === 'email'));
    $scopes   = site_all_scopes();
    $hoursFor = function (string $scope) {
        $raw = site_hours_raw($scope);
        $out = [];
        for ($d = 0; $d <= 6; $d++) {
            $r = $raw[$d][0] ?? null;
            $out[$d] = [
                'closed' => $r ? ((int)$r['is_closed'] === 1) : true,
                'open'   => ($r && $r['open_time'])  ? substr((string)$r['open_time'], 0, 5)  : '',
                'close'  => ($r && $r['close_time']) ? substr((string)$r['close_time'], 0, 5) : '',
            ];
        }
        return $out;
    };
    $g = fn($k, $d = '') => htmlspecialchars((string)($settings[$k] ?? $d), ENT_QUOTES);
}

$sectionCls = "bg-white border border-stone-200 rounded-lg mb-4 overflow-hidden";
$sumCls     = "cursor-pointer select-none px-5 py-4 font-serif font-bold text-stone-800 flex items-center justify-between hover:bg-stone-50";
$inputCls   = "w-full px-3 py-2 border border-stone-300 rounded text-xs text-stone-800 focus:outline-none focus:ring-2 focus:ring-amber-400";
$btnCls     = "mt-4 px-4 py-2 rounded bg-stone-800 text-stone-100 text-xs font-semibold hover:bg-stone-900";
$labelCls   = "block text-[10px] uppercase tracking-wider text-stone-400 font-mono mb-1";
?>

<div class="mb-6">
    <h1 class="text-2xl font-serif font-black text-stone-800">🏠 Site Info</h1>
    <p class="text-xs text-stone-400 font-mono mt-0.5">One place for address, phones, emails, hours &amp; season — edits propagate everywhere.</p>
</div>

<?php if ($tablesMissing): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded p-4 text-xs">
        ⚠️ The Site Info tables don't exist yet. Run <code class="font-mono">site_info_module.sql</code> (Sections 1 &amp; 2) in phpMyAdmin, then reload.
    </div>
<?php else: ?>
<div class="max-w-3xl">

    <!-- ── BUSINESS & ADDRESS ── -->
    <details class="<?= $sectionCls ?>" open>
        <summary class="<?= $sumCls ?>"><span>📢 Announcement Bar</span><span class="text-stone-300 text-xs">edit</span></summary>
        <form method="POST" action="/admin/admin.php?view=site_info" class="p-5">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="site_info_action" value="announcement">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <div class="sm:col-span-2">
                    <label class="<?= $labelCls ?>">Announcement text</label>
                    <input class="<?= $inputCls ?>" name="settings[announcement_text]"
                           value="<?= $g('announcement_text') ?>"
                           placeholder="e.g. 🚧 We're closed Saturday, July 18 — back Sunday at 10AM!">
                    <p class="text-[10px] text-stone-400 mt-1">Shows as a banner at the top of every public page. Leave empty to hide. Visitors can dismiss it; a new message re-appears for everyone.</p>
                </div>
                <div>
                    <label class="<?= $labelCls ?>">Show until (inclusive)</label>
                    <input class="<?= $inputCls ?>" name="settings[announcement_until]" type="date"
                           value="<?= $g('announcement_until') ?>">
                    <p class="text-[10px] text-stone-400 mt-1">Banner disappears automatically after this date. Leave blank to show indefinitely.</p>
                </div>
            </div>
            <button type="submit" class="px-5 py-2 bg-stone-800 text-white rounded text-sm font-semibold hover:bg-stone-700">Save announcement</button>
        </form>
    </details>

    <details class="<?= $sectionCls ?>" open>
        <summary class="<?= $sumCls ?>"><span>📍 Business &amp; Address</span><span class="text-stone-300 text-xs">edit</span></summary>
        <form method="POST" action="/admin/admin.php?view=site_info" class="px-5 pb-5 pt-1">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="site_info_action" value="address">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="sm:col-span-2"><label class="<?= $labelCls ?>">Business name</label><input class="<?= $inputCls ?>" name="settings[business_name]" value="<?= $g('business_name') ?>"></div>
                <div class="sm:col-span-2"><label class="<?= $labelCls ?>">Street</label><input class="<?= $inputCls ?>" name="settings[address_street]" value="<?= $g('address_street') ?>"></div>
                <div><label class="<?= $labelCls ?>">City</label><input class="<?= $inputCls ?>" name="settings[address_city]" value="<?= $g('address_city') ?>"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="<?= $labelCls ?>">State</label><input class="<?= $inputCls ?>" name="settings[address_state]" value="<?= $g('address_state') ?>"></div>
                    <div><label class="<?= $labelCls ?>">ZIP</label><input class="<?= $inputCls ?>" name="settings[address_zip]" value="<?= $g('address_zip') ?>"></div>
                </div>
                <div><label class="<?= $labelCls ?>">Country</label><input class="<?= $inputCls ?>" name="settings[address_country]" value="<?= $g('address_country', 'US') ?>"></div>
                <div><label class="<?= $labelCls ?>">Timezone</label><input class="<?= $inputCls ?>" name="settings[timezone]" value="<?= $g('timezone', 'America/New_York') ?>"></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="<?= $labelCls ?>">Latitude</label><input class="<?= $inputCls ?>" name="settings[geo_lat]" value="<?= $g('geo_lat') ?>" placeholder="35.619"></div>
                    <div><label class="<?= $labelCls ?>">Longitude</label><input class="<?= $inputCls ?>" name="settings[geo_lng]" value="<?= $g('geo_lng') ?>" placeholder="-82.259"></div>
                </div>
                <div class="sm:col-span-2">
                    <label class="<?= $labelCls ?>">Google Maps embed URL</label>
                    <textarea class="<?= $inputCls ?>" rows="2" name="settings[map_embed_url]" placeholder="https://www.google.com/maps/embed?pb=..."><?= $g('map_embed_url') ?></textarea>
                    <p class="text-[10px] text-stone-400 mt-1">The <code class="font-mono">src</code> from Google Maps &rarr; Share &rarr; Embed a map.</p>
                </div>
                <div>
                    <label class="<?= $labelCls ?>">Google star rating <span class="font-normal opacity-60">(e.g. 4.8)</span></label>
                    <input class="<?= $inputCls ?>" name="settings[google_rating]" value="<?= $g('google_rating') ?>" placeholder="4.8" step="0.1" min="1" max="5" type="number">
                    <p class="text-[10px] text-stone-400 mt-1">Your current Google Business Profile average rating. Used for schema markup (shows stars in search results when ≥ 5 reviews).</p>
                </div>
                <div>
                    <label class="<?= $labelCls ?>">Google review count</label>
                    <input class="<?= $inputCls ?>" name="settings[google_review_count]" value="<?= $g('google_review_count') ?>" placeholder="24" type="number" min="0">
                    <p class="text-[10px] text-stone-400 mt-1">Total number of Google reviews. Update this any time you check your GBP.</p>
                </div>
            </div>
            <button class="<?= $btnCls ?>" type="submit">Save business &amp; address</button>
        </form>
    </details>

    <!-- ── PHONES ── -->
    <details class="<?= $sectionCls ?>">
        <summary class="<?= $sumCls ?>"><span>📞 Phones</span><span class="text-stone-300 text-xs">edit</span></summary>
        <form method="POST" action="/admin/admin.php?view=site_info" class="px-5 pb-5 pt-1">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="site_info_action" value="phones">
            <?php foreach ($phones as $c): $isGen = $c['slot'] === 'general'; ?>
                <div class="py-2 border-b border-stone-100 last:border-0">
                    <label class="<?= $labelCls ?>"><?= htmlspecialchars($c['label'] ?: ucfirst($c['slot'])) ?></label>
                    <div class="flex items-center gap-3">
                        <input class="<?= $inputCls ?>" name="contact[<?= (int)$c['id'] ?>][value]" value="<?= htmlspecialchars((string)$c['value'], ENT_QUOTES) ?>" placeholder="<?= $isGen ? '(828) ...' : 'leave blank if same as general' ?>">
                        <?php if (!$isGen): ?>
                            <label class="flex items-center gap-1 text-[11px] text-stone-500 whitespace-nowrap">
                                <input type="checkbox" name="contact[<?= (int)$c['id'] ?>][inherit]" value="1" <?= (int)$c['inherit_general'] === 1 ? 'checked' : '' ?>> same as general
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <button class="<?= $btnCls ?>" type="submit">Save phones</button>
        </form>
    </details>

    <!-- ── EMAILS ── -->
    <details class="<?= $sectionCls ?>">
        <summary class="<?= $sumCls ?>"><span>✉️ Emails</span><span class="text-stone-300 text-xs">edit</span></summary>
        <form method="POST" action="/admin/admin.php?view=site_info" class="px-5 pb-5 pt-1">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="site_info_action" value="emails">
            <?php foreach ($emails as $c): $isGen = $c['slot'] === 'general'; ?>
                <div class="py-2 border-b border-stone-100 last:border-0">
                    <label class="<?= $labelCls ?>"><?= htmlspecialchars($c['label'] ?: ucfirst($c['slot'])) ?></label>
                    <div class="flex items-center gap-3">
                        <input class="<?= $inputCls ?>" type="email" name="contact[<?= (int)$c['id'] ?>][value]" value="<?= htmlspecialchars((string)$c['value'], ENT_QUOTES) ?>" placeholder="<?= $isGen ? 'support@...' : 'leave blank if same as general' ?>">
                        <?php if (!$isGen): ?>
                            <label class="flex items-center gap-1 text-[11px] text-stone-500 whitespace-nowrap">
                                <input type="checkbox" name="contact[<?= (int)$c['id'] ?>][inherit]" value="1" <?= (int)$c['inherit_general'] === 1 ? 'checked' : '' ?>> same as general
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <button class="<?= $btnCls ?>" type="submit">Save emails</button>
        </form>
    </details>

    <!-- ── SOCIAL LINKS ── -->
    <?php $socials = site_socials(); ?>
    <details class="<?= $sectionCls ?>">
        <summary class="<?= $sumCls ?>"><span>🔗 Social Links</span><span class="text-stone-300 text-xs">edit</span></summary>
        <form method="POST" action="/admin/admin.php?view=site_info" class="px-5 pb-5 pt-1">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="site_info_action" value="socials">
            <div id="siSocialRows" class="space-y-2">
                <?php foreach ($socials as $i => $s): ?>
                    <div class="si-social-row border border-stone-200 rounded-lg p-3 space-y-2">
                        <div class="flex items-center gap-2">
                            <div id="imgPreview_soc<?= $i ?>" class="<?= !empty($s['image']) ? '' : 'hidden' ?> w-11 h-11 border rounded overflow-hidden bg-stone-100 shrink-0">
                                <img src="<?= htmlspecialchars((string)$s['image'], ENT_QUOTES) ?>" alt="" class="w-full h-full object-contain" onerror="this.closest('div').style.display='none'">
                            </div>
                            <input class="<?= $inputCls ?> flex-1 min-w-[110px]" name="social[<?= $i ?>][name]" value="<?= htmlspecialchars((string)$s['name'], ENT_QUOTES) ?>" placeholder="Instagram">
                            <input class="<?= $inputCls ?> flex-1 min-w-[150px]" name="social[<?= $i ?>][url]" value="<?= htmlspecialchars((string)$s['url'], ENT_QUOTES) ?>" placeholder="https://...">
                            <button type="button" class="si-social-del text-stone-400 hover:text-red-600 text-xl leading-none px-1 shrink-0" title="Remove">&times;</button>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="text" id="imgUrl_soc<?= $i ?>" name="social[<?= $i ?>][image]" value="<?= htmlspecialchars((string)$s['image'], ENT_QUOTES) ?>" class="<?= $inputCls ?> flex-1 font-mono" placeholder="/img/social/icon.svg" oninput="updateImgPreview('soc<?= $i ?>')">
                            <button type="button" onclick="openVaultPicker('soc<?= $i ?>')" class="shrink-0 border border-stone-300 px-3 py-2 rounded text-xs font-semibold bg-stone-50 hover:bg-stone-900 hover:text-white transition whitespace-nowrap">📁 Vault</button>
                            <label class="shrink-0 border border-stone-300 px-3 py-2 rounded text-xs font-semibold bg-stone-50 hover:bg-stone-900 hover:text-white transition whitespace-nowrap cursor-pointer">⬆ Upload
                                <input type="file" accept="image/*" class="hidden" onchange="quickUploadImage(this,'soc<?= $i ?>')">
                            </label>
                            <span id="imgProgress_soc<?= $i ?>" class="hidden text-xs text-stone-500 animate-pulse">Uploading...</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <template id="siSocialTpl">
                <div class="si-social-row border border-stone-200 rounded-lg p-3 space-y-2">
                    <div class="flex items-center gap-2">
                        <div id="imgPreview_soc__I__" class="hidden w-11 h-11 border rounded overflow-hidden bg-stone-100 shrink-0">
                            <img src="" alt="" class="w-full h-full object-contain" onerror="this.closest('div').style.display='none'">
                        </div>
                        <input class="<?= $inputCls ?> flex-1 min-w-[110px]" name="social[__I__][name]" value="" placeholder="Instagram">
                        <input class="<?= $inputCls ?> flex-1 min-w-[150px]" name="social[__I__][url]" value="" placeholder="https://...">
                        <button type="button" class="si-social-del text-stone-400 hover:text-red-600 text-xl leading-none px-1 shrink-0" title="Remove">&times;</button>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="text" id="imgUrl_soc__I__" name="social[__I__][image]" value="" class="<?= $inputCls ?> flex-1 font-mono" placeholder="/img/social/icon.svg" oninput="updateImgPreview('soc__I__')">
                        <button type="button" onclick="openVaultPicker('soc__I__')" class="shrink-0 border border-stone-300 px-3 py-2 rounded text-xs font-semibold bg-stone-50 hover:bg-stone-900 hover:text-white transition whitespace-nowrap">📁 Vault</button>
                        <label class="shrink-0 border border-stone-300 px-3 py-2 rounded text-xs font-semibold bg-stone-50 hover:bg-stone-900 hover:text-white transition whitespace-nowrap cursor-pointer">⬆ Upload
                            <input type="file" accept="image/*" class="hidden" onchange="quickUploadImage(this,'soc__I__')">
                        </label>
                        <span id="imgProgress_soc__I__" class="hidden text-xs text-stone-500 animate-pulse">Uploading...</span>
                    </div>
                </div>
            </template>
            <div class="mt-3"><button type="button" id="siSocialAdd" class="text-xs font-semibold text-amber-700 hover:text-amber-900">+ Add link</button></div>
            <button class="<?= $btnCls ?>" type="submit">Save social links</button>
        </form>
    </details>

    <!-- ── HOURS ── -->
    <details class="<?= $sectionCls ?>">
        <summary class="<?= $sumCls ?>"><span>🕒 Hours</span><span class="text-stone-300 text-xs">edit</span></summary>
        <form method="POST" action="/admin/admin.php?view=site_info" class="px-5 pb-5 pt-1">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="site_info_action" value="hours">

            <div class="text-[11px] uppercase tracking-wider text-stone-400 font-mono mb-2">General hours</div>
            <?php $gh = $hoursFor('general'); ?>
            <div class="space-y-1.5 mb-5">
                <?php for ($d = 0; $d <= 6; $d++): ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="w-24 text-stone-600"><?= $DAYS[$d] ?></span>
                        <label class="flex items-center gap-1 text-stone-500 w-20"><input type="checkbox" name="hours[general][<?= $d ?>][closed]" value="1" <?= $gh[$d]['closed'] ? 'checked' : '' ?>> closed</label>
                        <input type="time" class="px-2 py-1 border border-stone-300 rounded" name="hours[general][<?= $d ?>][open]"  value="<?= htmlspecialchars($gh[$d]['open'], ENT_QUOTES) ?>">
                        <span class="text-stone-400">–</span>
                        <input type="time" class="px-2 py-1 border border-stone-300 rounded" name="hours[general][<?= $d ?>][close]" value="<?= htmlspecialchars($gh[$d]['close'], ENT_QUOTES) ?>">
                    </div>
                <?php endfor; ?>
            </div>

            <?php foreach ($scopes as $sc): if ($sc['scope'] === 'general') continue; $scId = htmlspecialchars($sc['scope'], ENT_QUOTES); $ah = $hoursFor($sc['scope']); ?>
                <div class="border-t border-stone-100 pt-3 mb-2">
                    <label class="flex items-center gap-2 text-xs font-semibold text-stone-700">
                        <input type="checkbox" data-scope-toggle="<?= $scId ?>" name="scope_inherit[<?= $scId ?>]" value="1" <?= (int)$sc['inherit_general'] === 1 ? 'checked' : '' ?>>
                        <?= htmlspecialchars($sc['label'] ?: $sc['scope']) ?> — same as general
                    </label>
                    <div id="hours-<?= $scId ?>" class="mt-2 space-y-1.5 pl-6">
                        <?php for ($d = 0; $d <= 6; $d++): ?>
                            <div class="flex items-center gap-2 text-xs">
                                <span class="w-24 text-stone-600"><?= $DAYS[$d] ?></span>
                                <label class="flex items-center gap-1 text-stone-500 w-20"><input type="checkbox" name="hours[<?= $scId ?>][<?= $d ?>][closed]" value="1" <?= $ah[$d]['closed'] ? 'checked' : '' ?>> closed</label>
                                <input type="time" class="px-2 py-1 border border-stone-300 rounded" name="hours[<?= $scId ?>][<?= $d ?>][open]"  value="<?= htmlspecialchars($ah[$d]['open'], ENT_QUOTES) ?>">
                                <span class="text-stone-400">–</span>
                                <input type="time" class="px-2 py-1 border border-stone-300 rounded" name="hours[<?= $scId ?>][<?= $d ?>][close]" value="<?= htmlspecialchars($ah[$d]['close'], ENT_QUOTES) ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <button class="<?= $btnCls ?>" type="submit">Save hours</button>
        </form>
    </details>

    <!-- ── SEASON ── -->
    <details class="<?= $sectionCls ?>">
        <summary class="<?= $sumCls ?>"><span>🍂 Season</span><span class="text-stone-300 text-xs">edit</span></summary>
        <form method="POST" action="/admin/admin.php?view=site_info" class="px-5 pb-5 pt-1">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="site_info_action" value="season">
            <?php $season = ($settings['season_status'] ?? 'open') === 'closed' ? 'closed' : 'open'; ?>
            <p class="text-xs text-stone-500 mb-3">When set to <strong>Closed for the season</strong>, the public Open/Closed badge and schema report closed regardless of weekly hours.</p>
            <label class="flex items-center gap-2 text-sm text-stone-700 mb-1"><input type="radio" name="season_status" value="open"   <?= $season === 'open'   ? 'checked' : '' ?>> Open for the season</label>
            <label class="flex items-center gap-2 text-sm text-stone-700"><input type="radio" name="season_status" value="closed" <?= $season === 'closed' ? 'checked' : '' ?>> Closed for the season</label>
            <div><button class="<?= $btnCls ?>" type="submit">Save season</button></div>
        </form>
    </details>

</div>

<?php require_once __DIR__ . '/layout/vault_picker.php'; ?>

<script>
(function () {
    // Reveal/hide each area's custom day editor based on its "same as general" checkbox.
    document.querySelectorAll('[data-scope-toggle]').forEach(function (cb) {
        var ed = document.getElementById('hours-' + cb.dataset.scopeToggle);
        function sync() { if (ed) ed.style.display = cb.checked ? 'none' : 'block'; }
        cb.addEventListener('change', sync);
        sync();
    });

    // Social links: add / remove rows. The per-row image widget (preview, Vault,
    // Upload) is wired by inline handlers from the shared vault picker partial.
    var rows = document.getElementById('siSocialRows');
    var tpl  = document.getElementById('siSocialTpl');
    var addB = document.getElementById('siSocialAdd');
    var nextIdx = <?= count($socials ?? []) ?>;
    if (rows) {
        rows.addEventListener('click', function (e) {
            var del = e.target.closest('.si-social-del');
            if (del) { var row = del.closest('.si-social-row'); if (row) row.remove(); }
        });
    }
    if (addB && tpl && rows) {
        addB.addEventListener('click', function () {
            var wrap = document.createElement('div');
            wrap.innerHTML = tpl.innerHTML.replace(/__I__/g, String(nextIdx++)).trim();
            var node = wrap.firstElementChild;
            rows.appendChild(node);
            var nameInp = node.querySelector('input[name$="[name]"]');
            if (nameInp) nameInp.focus();
        });
    }
})();
</script>
<?php endif; ?>
