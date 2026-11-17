<?php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
// Fix 12: Segment counts passed to JS for live display
$segment_counts_json = json_encode($nl_segment_counts);

// Subscriber totals
$total_subs      = count($nl_subscribers);
$total_active    = $nl_segment_counts['all'];
$total_inactive  = $total_subs - $total_active;
?>

<div id="newsletter-root" data-segment-counts="<?= htmlspecialchars($segment_counts_json, ENT_QUOTES) ?>" class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-6">
    <h1 class="text-2xl font-bold text-stone-800">Newsletter</h1>
    <div class="flex flex-wrap gap-2 text-xxs font-bold">
        <span class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-2 py-1 rounded">✅ <?= $total_active ?> Active</span>
        <span class="bg-red-50 border border-red-200 text-red-700 px-2 py-1 rounded">🚫 <?= $total_inactive ?> Unsubscribed</span>
        <span class="bg-stone-100 border border-stone-200 text-stone-600 px-2 py-1 rounded">Total: <?= $total_subs ?></span>
    </div>
</div>

<?php
// ── Auto-newsletter send status (driven by cron/send_newsletter.php) ──
$nlActive = null; $nlTotalActive = 0; $nlSent = 0; $nlRemaining = 0; $nlRecent = [];
try {
    $nlActive = $pdo->query("SELECT id, title FROM blog_posts WHERE status='published' AND newsletter_sent_at IS NULL ORDER BY COALESCE(date_published, created_at) ASC, id ASC LIMIT 1")->fetch();
    $nlTotalActive = (int)$pdo->query("SELECT COUNT(*) FROM mailing_list WHERE is_subscribed=1")->fetchColumn();
    if ($nlActive) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM newsletter_log WHERE post_id=?");
        $st->execute([$nlActive['id']]);
        $nlSent = (int)$st->fetchColumn();
        $nlRemaining = max(0, $nlTotalActive - $nlSent);
    }
    $nlRecent = $pdo->query("SELECT nl.post_id, bp.title, COUNT(*) AS sent, MAX(nl.sent_at) AS last_sent FROM newsletter_log nl LEFT JOIN blog_posts bp ON bp.id = nl.post_id GROUP BY nl.post_id, bp.title ORDER BY last_sent DESC LIMIT 5")->fetchAll();
} catch (Throwable $e) { /* tables not migrated yet — panel just shows idle */ }
$nlPct = ($nlActive && $nlTotalActive > 0) ? (int)round($nlSent / $nlTotalActive * 100) : 0;
?>
<div class="bg-white p-5 border border-stone-200 rounded shadow-sm mb-8">
    <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider mb-3 border-b pb-2">📣 Auto-Newsletter &mdash; new blog posts</h3>
    <?php if ($nlActive): ?>
        <div class="text-sm text-stone-800 mb-2">Currently sending: <strong><?= htmlspecialchars($nlActive['title']) ?></strong></div>
        <div class="w-full bg-stone-100 rounded-full h-3 overflow-hidden mb-2">
            <div class="bg-emerald-500 h-3" style="width:<?= $nlPct ?>%"></div>
        </div>
        <div class="text-xs text-stone-600"><?= $nlSent ?> sent &middot; <?= $nlRemaining ?> remaining &middot; <?= $nlTotalActive ?> active subscribers (<?= $nlPct ?>%)</div>
    <?php else: ?>
        <div class="text-sm text-stone-500">No newsletter in progress. The next published blog post will start sending automatically (cron, in batches).</div>
    <?php endif; ?>
    <?php if (!empty($nlRecent)): ?>
        <div class="mt-4 border-t pt-3">
            <div class="font-bold text-stone-700 uppercase text-[10px] tracking-wider mb-2">Recent sends</div>
            <div class="space-y-1 text-[11px]">
                <?php foreach ($nlRecent as $r): ?>
                    <div class="flex justify-between gap-3 border-b pb-1">
                        <span class="truncate text-stone-700"><?= htmlspecialchars($r['title'] ?? ('Post #' . $r['post_id'])) ?></span>
                        <span class="text-stone-500 whitespace-nowrap"><?= (int)$r['sent'] ?> sent &middot; <?= $r['last_sent'] ? htmlspecialchars(date('M j, g:i a', strtotime($r['last_sent']))) : '' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

    <!-- ── CRM POOL ── -->
    <div class="bg-white p-5 border border-stone-200 rounded shadow-sm flex flex-col">
        <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider mb-3 border-b pb-2">
            CRM Pool (<?= $total_subs ?>)
        </h3>

        <!-- Segment filter tabs -->
        <div class="flex flex-wrap justify-end gap-2 mb-3 text-xs font-bold uppercase">
            <button type="button" data-crm-filter="all"       id="crmtab-all"       class="crm-tab min-h-11 px-3 py-2 rounded-full farmos-filter-selected border">All</button>
            <button type="button" data-crm-filter="active"    id="crmtab-active"    class="crm-tab min-h-11 px-3 py-2 rounded-full bg-stone-100 text-stone-600 border border-stone-200">Active</button>
            <button type="button" data-crm-filter="nursery"   id="crmtab-nursery"   class="crm-tab min-h-11 px-3 py-2 rounded-full bg-stone-100 text-stone-600 border border-stone-200">Nursery</button>
            <button type="button" data-crm-filter="inventory" id="crmtab-inventory" class="crm-tab min-h-11 px-3 py-2 rounded-full bg-stone-100 text-stone-600 border border-stone-200">Inv</button>
        </div>

        <input type="search" id="crmSearch"  placeholder="Search name or email…"
            class="w-full mb-2 p-2.5 border border-stone-300 rounded bg-stone-50 text-sm outline-none focus:ring-2 focus:ring-stone-400">

        <div id="crmList" class="flex-1 max-h-[55vh] overflow-y-auto space-y-1.5 pr-1">
            <?php foreach ($nl_subscribers as $sub):
                $name = trim($sub['first_name'] . ' ' . $sub['last_name']) ?: null;
            ?>
            <div class="crm-row border-b pb-1.5 text-[10px]"
                data-subscribed="<?= $sub['is_subscribed'] ? '1' : '0' ?>"
                data-nursery="<?= $sub['track_nursery_updates'] ? '1' : '0' ?>"
                data-inventory="<?= $sub['track_inventory_alerts'] ? '1' : '0' ?>"
                data-search="<?= htmlspecialchars(strtolower(trim(($name ?? '') . ' ' . $sub['email'])), ENT_QUOTES) ?>">
                <?php if ($name): ?>
                    <div class="font-bold text-stone-800 truncate"><?= htmlspecialchars($name) ?></div>
                <?php endif; ?>
                <div class="text-stone-500 truncate"><?= htmlspecialchars($sub['email']) ?></div>
                <div class="flex gap-1 mt-0.5 flex-wrap">
                    <span class="<?= $sub['is_subscribed'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-600 line-through' ?> px-1 rounded text-[8px] font-black uppercase">
                        <?= $sub['is_subscribed'] ? 'Active' : 'Unsub' ?>
                    </span>
                    <?php if ($sub['track_nursery_updates']): ?>
                        <span class="bg-blue-100 text-blue-700 px-1 rounded text-[8px] font-black uppercase">Nursery</span>
                    <?php endif; ?>
                    <?php if ($sub['track_inventory_alerts']): ?>
                        <span class="bg-amber-100 text-amber-700 px-1 rounded text-[8px] font-black uppercase">Inv</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($nl_subscribers)): ?>
                <p class="text-stone-400 italic text-xs py-4 text-center">No subscribers found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── COMPOSE PANEL ── -->
    <div class="lg:col-span-2 bg-white p-6 border border-stone-200 rounded shadow-sm">
        <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider mb-4 border-b pb-2">Compose Campaign</h3>

        <form id="campaignForm" method="POST" action="/admin/handlers/campaign_action.php" class="space-y-4 text-xs font-sans">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="body_html" id="bodyHtml">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-stone-600 mb-1">From Name</label>
                    <input type="text" name="from_name" value="Bat Cave Road Farm"
                        class="w-full p-2 border border-stone-300 bg-stone-50 rounded font-medium" required>
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Reply-To Email</label>
                    <input type="email" name="reply_to" value="<?= htmlspecialchars(bcrf_farm_email('support'), ENT_QUOTES) ?>"
                        class="w-full p-2 border bg-stone-50 rounded" required>
                </div>
            </div>

            <div>
                <label class="block font-bold text-stone-600 mb-1">Subject Line</label>
                <input type="text" name="subject" maxlength="255"
                    placeholder="e.g. 🌿 Fresh from the Farm Stand this week!"
                    class="w-full p-2 border border-stone-300 bg-stone-50 rounded font-medium" required>
            </div>

            <!-- Fix 12: segment selector with live recipient count -->
            <div>
                <label class="block font-bold text-stone-600 mb-1">Send To</label>
                <div class="flex gap-2 items-center">
                    <select name="segment" id="segmentSelect" 
                        class="flex-1 p-2 border border-stone-300 bg-white rounded font-medium">
                        <option value="all">All Active Subscribers</option>
                        <option value="nursery">Nursery Updates List</option>
                        <option value="inventory">Inventory Alerts List</option>
                    </select>
                    <span id="recipientCountBadge"
                        class="shrink-0 font-black text-xs bg-stone-100 border border-stone-200 px-3 py-2 rounded whitespace-nowrap">
                        <?= $nl_segment_counts['all'] ?> recipients
                    </span>
                </div>
            </div>

            <!-- Fix 10: test send -->
            <div class="bg-stone-50 border border-stone-200 rounded p-3 space-y-2">
                <label class="block font-bold text-stone-600 text-[11px] uppercase tracking-wider">Test Send (optional)</label>
                <div class="flex gap-2">
                    <input type="email" name="test_email" id="testEmailInput"
                        placeholder="your@email.com — sends only to this address"
                        class="flex-1 p-2 border border-stone-300 bg-white rounded text-xs">
                    <button type="button" data-nl-send="test"
                        class="shrink-0 bg-amber-700 text-white font-semibold px-4 py-2 rounded hover:bg-amber-800 transition text-xs">
                        Send Test
                    </button>
                </div>
            </div>

            <!-- Fix 13: Quill rich text editor -->
            <div>
                <label class="block font-bold text-stone-600 mb-1">Email Body</label>
                <link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
                <script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.min.js"></script>

                <div id="emailBodyEditor" class="bg-white border border-stone-200 rounded" style="min-height:260px;"></div>
            </div>

            <!-- Fix 9: preview toggle -->
            <div id="emailPreviewWrapper" class="hidden border border-stone-200 rounded bg-stone-50 p-4">
                <div class="flex justify-between items-center mb-3">
                    <span class="font-bold text-stone-600 text-[11px] uppercase tracking-wider">Preview</span>
                    <button type="button" data-nl-preview-toggle
                        class="text-[10px] text-stone-400 hover:text-stone-700 underline">Hide</button>
                </div>
                <div id="emailPreviewContent" class="bg-white border border-stone-200 rounded p-4 prose prose-sm max-w-none text-xs"></div>
            </div>

            <div class="flex gap-2 justify-between items-center pt-2 border-t">
                <button type="button" data-nl-preview-toggle
                    class="border border-stone-200 font-bold uppercase text-[10px] tracking-wider px-4 py-2 rounded text-stone-600 hover:bg-stone-100 transition">
                    👁 Preview
                </button>
                <!-- Fix 6: confirmation before blast -->
                <button type="button" data-nl-send="live"
                    class="bg-amber-700 text-white font-bold uppercase tracking-wider px-8 py-2.5 rounded hover:bg-amber-800 transition shadow-sm text-xs">
                    🚀 Send Campaign
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── CAMPAIGN HISTORY ── -->
<div class="bg-white border border-stone-200 rounded shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b bg-stone-50 flex justify-between items-center">
        <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider">Campaign History</h3>
        <span class="text-xxs text-stone-400"><?= count($nl_campaigns) ?> logged</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left" data-admin-list data-list-name-col="0" data-list-date-col="5">
            <thead class="bg-stone-50 border-b border-stone-200 text-stone-500 uppercase tracking-widest text-[9px] font-bold">
                <tr>
                    <th class="p-3">Subject</th>
                    <th class="p-3">Segment</th>
                    <th class="p-3 text-center">Recipients</th>
                    <th class="p-3 text-center">Type</th>
                    <th class="p-3 text-center">Status</th>
                    <th class="p-3">Sent</th>
                    <th class="p-3 w-16"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <?php if (empty($nl_campaigns)): ?>
                    <tr><td colspan="6" class="p-6 text-center italic text-stone-400">No campaigns sent yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($nl_campaigns as $campaign):
                    $status_cls = match($campaign['status']) {
                        'sent'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'partial' => 'bg-amber-50 text-amber-700 border-amber-200',
                        'failed'  => 'bg-red-50 text-red-700 border-red-200',
                        default   => 'bg-stone-50 text-stone-600 border-stone-200',
                    };
                ?>
                <tr class="hover:bg-stone-50">
                    <td class="p-3 font-bold text-stone-800 max-w-xs truncate"><?= htmlspecialchars($campaign['subject']) ?></td>
                    <td class="p-3 capitalize font-medium text-stone-600"><?= htmlspecialchars($campaign['segment']) ?></td>
                    <td class="p-3 text-center font-bold"><?= (int)$campaign['recipient_count'] ?></td>
                    <td class="p-3 text-center">
                        <?= $campaign['is_test']
                            ? '<span class="bg-blue-50 text-blue-700 border border-blue-200 px-1.5 py-0.5 rounded text-[9px] font-black uppercase">Test</span>'
                            : '<span class="bg-stone-100 text-stone-600 border border-stone-200 px-1.5 py-0.5 rounded text-[9px] font-black uppercase">Live</span>' ?>
                    </td>
                    <td class="p-3 text-center">
                        <span class="px-2 py-0.5 rounded border border-stone-200 text-[9px] font-black uppercase <?= $status_cls ?>">
                            <?= htmlspecialchars($campaign['status']) ?>
                        </span>
                    </td>
                    <td class="p-3 text-stone-400 text-[10px] whitespace-nowrap">
                        <?= date('M d, Y g:i A', strtotime($campaign['created_at'])) ?>
                    </td>
                    <td class="p-3 text-center">
                        <button type="button"
                            data-campaign-preview="<?= (int)$campaign['id'] ?>" data-campaign-subject="<?= htmlspecialchars($campaign['subject'], ENT_QUOTES) ?>"
                            class="text-[10px] font-bold uppercase tracking-wider border px-2 py-1 rounded bg-stone-50 hover:bg-stone-900 hover:text-white transition">
                            👁 View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>



<!-- ── CAMPAIGN PREVIEW MODAL ── -->
<div id="campaignPreviewModal"
    class="fixed inset-0 bg-stone-900/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl flex flex-col" style="max-height:90vh;">
        <div class="flex justify-between items-center px-5 py-3 border-b bg-stone-50 rounded-t-lg shrink-0">
            <span id="campaignPreviewTitle" class="font-bold text-stone-800 text-sm truncate max-w-lg"></span>
            <div class="flex gap-2 shrink-0">
                <a id="campaignPreviewNewTab" href="#" target="_blank"
                    class="text-[10px] font-bold uppercase tracking-wider border border-stone-200 px-3 py-1.5 rounded hover:bg-stone-900 hover:text-white transition">
                    Open in New Tab ↗
                </a>
                <button type="button" data-campaign-close
                    class="text-[10px] font-bold uppercase tracking-wider border border-stone-200 px-3 py-1.5 rounded hover:bg-stone-900 hover:text-white transition">
                    Close ✕
                </button>
            </div>
        </div>
        <div class="flex-1 overflow-hidden rounded-b-lg">
            <iframe id="campaignPreviewFrame"
                srcdoc=""
                class="w-full h-full border-0"
                style="min-height:500px;"
                sandbox="allow-same-origin">
            </iframe>
        </div>
    </div>
</div>



<script src="/admin/assets/newsletter.js" defer></script>
