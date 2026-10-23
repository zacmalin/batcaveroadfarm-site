<?php
// Fix 12: Segment counts passed to JS for live display
$segment_counts_json = json_encode($nl_segment_counts);

// Subscriber totals
$total_subs      = count($nl_subscribers);
$total_active    = $nl_segment_counts['all'];
$total_inactive  = $total_subs - $total_active;
?>

<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-serif font-black text-stone-800">Email Campaign Console</h1>
    <div class="flex gap-3 font-mono text-xxs font-bold">
        <span class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-2 py-1 rounded">✅ <?= $total_active ?> Active</span>
        <span class="bg-red-50 border border-red-200 text-red-700 px-2 py-1 rounded">🚫 <?= $total_inactive ?> Unsubscribed</span>
        <span class="bg-stone-100 border text-stone-600 px-2 py-1 rounded">Total: <?= $total_subs ?></span>
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
<div class="bg-white p-5 border rounded shadow-xs mb-8">
    <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider mb-3 border-b pb-2">📣 Auto-Newsletter &mdash; new blog posts</h3>
    <?php if ($nlActive): ?>
        <div class="text-sm text-stone-800 mb-2">Currently sending: <strong><?= htmlspecialchars($nlActive['title']) ?></strong></div>
        <div class="w-full bg-stone-100 rounded-full h-3 overflow-hidden mb-2">
            <div class="bg-emerald-500 h-3" style="width:<?= $nlPct ?>%"></div>
        </div>
        <div class="font-mono text-xs text-stone-600"><?= $nlSent ?> sent &middot; <?= $nlRemaining ?> remaining &middot; <?= $nlTotalActive ?> active subscribers (<?= $nlPct ?>%)</div>
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
                        <span class="font-mono text-stone-500 whitespace-nowrap"><?= (int)$r['sent'] ?> sent &middot; <?= $r['last_sent'] ? htmlspecialchars(date('M j, g:i a', strtotime($r['last_sent']))) : '' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

    <!-- ── CRM POOL ── -->
    <div class="bg-white p-5 border rounded shadow-xs flex flex-col">
        <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider mb-3 border-b pb-2">
            CRM Pool (<?= $total_subs ?>)
        </h3>

        <!-- Segment filter tabs -->
        <div class="flex gap-1 mb-3 font-mono text-[9px] font-bold uppercase">
            <button type="button" onclick="filterCRM('all')"       id="crmtab-all"       class="crm-tab flex-1 py-1 rounded bg-stone-900 text-white border">All</button>
            <button type="button" onclick="filterCRM('active')"    id="crmtab-active"    class="crm-tab flex-1 py-1 rounded bg-stone-100 text-stone-600 border">Active</button>
            <button type="button" onclick="filterCRM('nursery')"   id="crmtab-nursery"   class="crm-tab flex-1 py-1 rounded bg-stone-100 text-stone-600 border">Nursery</button>
            <button type="button" onclick="filterCRM('inventory')" id="crmtab-inventory" class="crm-tab flex-1 py-1 rounded bg-stone-100 text-stone-600 border">Inv</button>
        </div>

        <input type="search" id="crmSearch" oninput="searchCRM(this.value)" placeholder="Search name or email…"
            class="w-full mb-2 p-1.5 border border-stone-300 rounded bg-stone-50 text-[10px] outline-none focus:ring-2 focus:ring-stone-400">

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
                <div class="font-mono text-stone-500 truncate"><?= htmlspecialchars($sub['email']) ?></div>
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
    <div class="lg:col-span-2 bg-white p-6 border rounded shadow-xs">
        <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider mb-4 border-b pb-2">Compose Campaign</h3>

        <form id="campaignForm" method="POST" action="/admin/handlers/campaign_action.php" class="space-y-4 text-xs font-sans">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="body_html" id="bodyHtml">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-stone-600 mb-1">From Name</label>
                    <input type="text" name="from_name" value="Bat Cave Road Farm"
                        class="w-full p-2 border bg-stone-50 rounded font-medium" required>
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-1">Reply-To Email</label>
                    <input type="email" name="reply_to" value="support@batcaveroadfarm.com"
                        class="w-full p-2 border bg-stone-50 rounded font-mono" required>
                </div>
            </div>

            <div>
                <label class="block font-bold text-stone-600 mb-1">Subject Line</label>
                <input type="text" name="subject" maxlength="255"
                    placeholder="e.g. 🌿 Fresh from the Farm Stand this week!"
                    class="w-full p-2 border bg-stone-50 rounded font-medium" required>
            </div>

            <!-- Fix 12: segment selector with live recipient count -->
            <div>
                <label class="block font-bold text-stone-600 mb-1">Send To</label>
                <div class="flex gap-2 items-center">
                    <select name="segment" id="segmentSelect" onchange="updateRecipientCount()"
                        class="flex-1 p-2 border bg-white rounded font-medium">
                        <option value="all">All Active Subscribers</option>
                        <option value="nursery">Nursery Updates List</option>
                        <option value="inventory">Inventory Alerts List</option>
                    </select>
                    <span id="recipientCountBadge"
                        class="shrink-0 font-mono font-black text-xs bg-stone-100 border px-3 py-2 rounded whitespace-nowrap">
                        <?= $nl_segment_counts['all'] ?> recipients
                    </span>
                </div>
            </div>

            <!-- Fix 10: test send -->
            <div class="bg-stone-50 border rounded p-3 space-y-2">
                <label class="block font-bold text-stone-600 text-[11px] uppercase tracking-wider">Test Send (optional)</label>
                <div class="flex gap-2">
                    <input type="email" name="test_email" id="testEmailInput"
                        placeholder="your@email.com — sends only to this address"
                        class="flex-1 p-2 border bg-white rounded font-mono text-xs">
                    <button type="button" onclick="submitCampaign(true)"
                        class="shrink-0 bg-stone-700 text-white font-mono font-bold uppercase px-4 py-2 rounded hover:bg-stone-900 transition text-[10px] tracking-wider">
                        Send Test
                    </button>
                </div>
            </div>

            <!-- Fix 13: Quill rich text editor -->
            <div>
                <label class="block font-bold text-stone-600 mb-1">Email Body</label>
                <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
                <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.min.js"></script>

                <div id="emailBodyEditor" class="bg-white border rounded" style="min-height:260px;"></div>
            </div>

            <!-- Fix 9: preview toggle -->
            <div id="emailPreviewWrapper" class="hidden border rounded bg-stone-50 p-4">
                <div class="flex justify-between items-center mb-3">
                    <span class="font-bold text-stone-600 text-[11px] uppercase tracking-wider">Preview</span>
                    <button type="button" onclick="togglePreview()"
                        class="text-[10px] text-stone-400 hover:text-stone-700 underline">Hide</button>
                </div>
                <div id="emailPreviewContent" class="bg-white border rounded p-4 prose prose-sm max-w-none text-xs"></div>
            </div>

            <div class="flex gap-2 justify-between items-center pt-2 border-t">
                <button type="button" onclick="togglePreview()"
                    class="border font-mono font-bold uppercase text-[10px] tracking-wider px-4 py-2 rounded text-stone-600 hover:bg-stone-100 transition">
                    👁 Preview
                </button>
                <!-- Fix 6: confirmation before blast -->
                <button type="button" onclick="submitCampaign(false)"
                    class="bg-stone-900 text-white font-mono font-bold uppercase tracking-wider px-8 py-2.5 rounded hover:bg-stone-700 transition shadow-xs text-xs">
                    🚀 Send Campaign
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── CAMPAIGN HISTORY ── -->
<div class="bg-white border rounded shadow-xs overflow-hidden">
    <div class="px-5 py-3 border-b bg-stone-50 flex justify-between items-center">
        <h3 class="font-bold text-stone-800 uppercase text-xs tracking-wider">Campaign History</h3>
        <span class="font-mono text-xxs text-stone-400"><?= count($nl_campaigns) ?> logged</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left" data-admin-list data-list-name-col="0" data-list-date-col="5">
            <thead class="bg-stone-900 text-stone-100 uppercase tracking-widest text-[9px] font-bold">
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
                    <td class="p-3 text-center font-mono font-bold"><?= (int)$campaign['recipient_count'] ?></td>
                    <td class="p-3 text-center">
                        <?= $campaign['is_test']
                            ? '<span class="bg-blue-50 text-blue-700 border border-blue-200 px-1.5 py-0.5 rounded text-[9px] font-black uppercase">Test</span>'
                            : '<span class="bg-stone-100 text-stone-600 border px-1.5 py-0.5 rounded text-[9px] font-black uppercase">Live</span>' ?>
                    </td>
                    <td class="p-3 text-center">
                        <span class="px-2 py-0.5 rounded border text-[9px] font-black uppercase <?= $status_cls ?>">
                            <?= htmlspecialchars($campaign['status']) ?>
                        </span>
                    </td>
                    <td class="p-3 font-mono text-stone-400 text-[10px] whitespace-nowrap">
                        <?= date('M d, Y g:i A', strtotime($campaign['created_at'])) ?>
                    </td>
                    <td class="p-3 text-center">
                        <button type="button"
                            onclick="openCampaignPreview(<?= (int)$campaign['id'] ?>, <?= htmlspecialchars(json_encode($campaign['subject']), ENT_QUOTES) ?>)"
                            class="text-[10px] font-bold uppercase tracking-wider font-mono border px-2 py-1 rounded bg-stone-50 hover:bg-stone-900 hover:text-white transition">
                            👁 View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// ── QUILL SETUP ───────────────────────────────────────────────────────────────
const emailQuill = new Quill('#emailBodyEditor', {
    theme: 'snow',
    modules: { toolbar: [
        [{ header: [1, 2, 3, false] }],
        ['bold', 'italic', 'underline'],
        [{ color: [] }, { background: [] }],
        ['link', 'image'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['blockquote'],
        ['clean']
    ]}
});

// ── SEGMENT COUNTS ────────────────────────────────────────────────────────────
const segmentCounts = <?= $segment_counts_json ?>;

function updateRecipientCount() {
    const seg   = document.getElementById('segmentSelect').value;
    const count = segmentCounts[seg] ?? 0;
    document.getElementById('recipientCountBadge').textContent = count + ' recipient' + (count !== 1 ? 's' : '');
}

// ── CRM FILTER + SEARCH ─────────────────────────────────────────────────────────
var crmSegment = 'all';
var crmQuery   = '';

function crmApply() {
    document.querySelectorAll('.crm-row').forEach(row => {
        let seg = true;
        if (crmSegment === 'active')    seg = row.dataset.subscribed === '1';
        if (crmSegment === 'nursery')   seg = row.dataset.nursery    === '1' && row.dataset.subscribed === '1';
        if (crmSegment === 'inventory') seg = row.dataset.inventory  === '1' && row.dataset.subscribed === '1';
        const txt = !crmQuery || (row.dataset.search || '').includes(crmQuery);
        row.style.display = (seg && txt) ? '' : 'none';
    });
}

function filterCRM(filter) {
    document.querySelectorAll('.crm-tab').forEach(t => {
        t.className = 'crm-tab flex-1 py-1 rounded bg-stone-100 text-stone-600 border text-[9px] font-bold uppercase font-mono';
    });
    const active = document.getElementById('crmtab-' + filter);
    if (active) active.className = 'crm-tab flex-1 py-1 rounded bg-stone-900 text-white border text-[9px] font-bold uppercase font-mono';
    crmSegment = filter;
    crmApply();
}

function searchCRM(val) {
    crmQuery = (val || '').trim().toLowerCase();
    crmApply();
}

// ── PREVIEW ───────────────────────────────────────────────────────────────────
function togglePreview() {
    const wrapper = document.getElementById('emailPreviewWrapper');
    const hidden  = wrapper.classList.contains('hidden');
    if (hidden) {
        document.getElementById('emailPreviewContent').innerHTML = emailQuill.root.innerHTML;
    }
    wrapper.classList.toggle('hidden');
}

// ── SEND WITH CONFIRMATION ────────────────────────────────────────────────────
async function submitCampaign(isTest) {
    const html = emailQuill.root.innerHTML.trim();
    if (!html || html === '<p><br></p>') {
        await sysAlert('Please write an email body before sending.', 'Missing Content', '✉️');
        return;
    }

    const form       = document.getElementById('campaignForm');
    const subject    = form.querySelector('[name="subject"]').value.trim();
    const fromName   = form.querySelector('[name="from_name"]').value.trim();
    const replyTo    = form.querySelector('[name="reply_to"]').value.trim();
    const segment    = form.querySelector('[name="segment"]').value;
    const testEmail  = document.getElementById('testEmailInput').value.trim();
    const csrfToken  = form.querySelector('[name="csrf_token"]').value;

    if (!subject) { await sysAlert('Please enter a subject line.', 'Missing Subject', '✉️'); return; }

    if (isTest) {
        if (!testEmail) { await sysAlert('Enter a test email address first.', 'Missing Email', '📧'); return; }

        // ── TEST SEND: AJAX so form stays intact ──────────────────────────────
        const sendBtn = form.querySelector('[onclick="submitCampaign(true)"]');
        const origTxt = sendBtn ? sendBtn.textContent : '';
        if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = 'Sending…'; }

        try {
            const res = await fetch('/admin/handlers/campaign_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    subject, from_name: fromName, reply_to: replyTo,
                    segment, body_html: html,
                    is_test: '1', test_email: testEmail,
                    ajax: '1',
                }),
            });

            const rawText = await res.text();
            let data;
            try { data = JSON.parse(rawText); }
            catch { console.error('Non-JSON response:', rawText.substring(0, 300)); await sysAlert('Server error — check the browser console for details.', 'Server Error', '⚠️'); return; }

            if (data.ok) {
                showNLToast('✅ ' + data.message);
            } else {
                showNLToast('❌ ' + data.message, true);
            }
        } catch (err) {
            showNLToast('❌ Network error: ' + err.message, true);
        } finally {
            if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = origTxt; }
        }

    } else {
        // ── LIVE SEND: full form POST with confirmation ───────────────────────
        const seg   = segment;
        const count = segmentCounts[seg] ?? 0;
        const label = { all: 'all active subscribers', nursery: 'the Nursery list', inventory: 'the Inventory Alerts list' }[seg];
        const ok = await sysConfirm(`Send this campaign to <strong>${count}</strong> people on <strong>${label}</strong>?<br><br>This cannot be undone.`, 'Send Campaign', '📧', 'Send Now');
        if (!ok) return;

        document.getElementById('bodyHtml').value = html;
        const isTestInput  = document.createElement('input');
        isTestInput.type   = 'hidden';
        isTestInput.name   = 'is_test';
        isTestInput.value  = '0';
        form.appendChild(isTestInput);
        form.submit();
    }
}

// Lightweight inline toast for test send feedback
function showNLToast(msg, isError = false) {
    let t = document.getElementById('nlToast');
    if (!t) {
        t = document.createElement('div');
        t.id        = 'nlToast';
        t.className = 'fixed bottom-5 right-5 z-50 px-4 py-2 rounded shadow-lg font-mono text-xs font-bold transition-opacity duration-300';
        document.body.appendChild(t);
    }
    t.textContent        = msg;
    t.style.background   = isError ? '#fef2f2' : '#f0fdf4';
    t.style.color        = isError ? '#991b1b' : '#166534';
    t.style.border       = isError ? '1px solid #fecaca' : '1px solid #bbf7d0';
    t.style.opacity      = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.style.opacity = '0', 4000);
}
</script>

<!-- ── CAMPAIGN PREVIEW MODAL ── -->
<div id="campaignPreviewModal"
    class="fixed inset-0 bg-stone-900/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl flex flex-col" style="max-height:90vh;">
        <div class="flex justify-between items-center px-5 py-3 border-b bg-stone-50 rounded-t-lg shrink-0">
            <span id="campaignPreviewTitle" class="font-bold text-stone-800 text-sm font-serif truncate max-w-lg"></span>
            <div class="flex gap-2 shrink-0">
                <a id="campaignPreviewNewTab" href="#" target="_blank"
                    class="text-[10px] font-bold uppercase tracking-wider font-mono border px-3 py-1.5 rounded hover:bg-stone-900 hover:text-white transition">
                    Open in New Tab ↗
                </a>
                <button type="button" onclick="closeCampaignPreview()"
                    class="text-[10px] font-bold uppercase tracking-wider font-mono border px-3 py-1.5 rounded hover:bg-stone-900 hover:text-white transition">
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

<script>
async function openCampaignPreview(id, subject) {
    const url   = '/admin/handlers/view_campaign.php?id=' + id;
    const frame = document.getElementById('campaignPreviewFrame');

    document.getElementById('campaignPreviewTitle').textContent = subject;
    document.getElementById('campaignPreviewNewTab').href       = url;
    document.getElementById('campaignPreviewModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    frame.srcdoc = '<body style="display:flex;align-items:center;justify-content:center;height:100%;font-family:sans-serif;font-size:14px;color:#999;">Loading preview&hellip;</body>';

    try {
        const res  = await fetch(url);
        const html = await res.text();
        frame.srcdoc = html;
    } catch (err) {
        frame.srcdoc = '<body style="padding:24px;font-family:sans-serif;color:#cc0000;font-size:14px;">Failed to load preview: ' + err.message + '</body>';
    }
}

function closeCampaignPreview() {
    document.getElementById('campaignPreviewModal').classList.add('hidden');
    document.getElementById('campaignPreviewFrame').srcdoc = '';
    document.body.style.overflow = '';
}

document.getElementById('campaignPreviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeCampaignPreview();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeCampaignPreview();
});
</script>
