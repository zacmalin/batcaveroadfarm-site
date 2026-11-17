
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
const segmentCounts = JSON.parse(document.getElementById('newsletter-root').dataset.segmentCounts || '{}');

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
        t.className = 'crm-tab flex-1 py-1 rounded bg-stone-100 text-stone-600 border text-[9px] font-bold uppercase';
    });
    const active = document.getElementById('crmtab-' + filter);
    if (active) active.className = 'crm-tab flex-1 py-1 rounded bg-amber-700 text-white border text-[9px] font-bold uppercase';
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
        const sendBtn = form.querySelector('[data-nl-send="test"]');
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

// Use the shared Farm OS bottom-center toast for test-send feedback.
function showNLToast(msg, isError = false) {
    let t = document.getElementById('nlToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'nlToast';
        t.setAttribute('aria-live', 'polite');
        document.body.appendChild(t);
    }
    clearTimeout(t._timer);
    t.replaceChildren();
    t.className = 'ui-toast' + (isError ? ' is-err' : '');
    t.setAttribute('role', isError ? 'alert' : 'status');
    const message = document.createElement('span');
    message.textContent = msg;
    t.appendChild(message);
    if (isError) {
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'ui-toast-x';
        close.setAttribute('aria-label', 'Close');
        close.textContent = '✕';
        close.addEventListener('click', () => t.classList.remove('is-on'));
        t.appendChild(close);
    }
    t.classList.add('is-on');
    if (!isError) t._timer = setTimeout(() => t.classList.remove('is-on'), 5000);
}

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

// Bind controls without inline event handlers.
document.querySelectorAll('[data-crm-filter]').forEach(button => button.addEventListener('click', () => filterCRM(button.dataset.crmFilter)));
document.getElementById('crmSearch')?.addEventListener('input', e => searchCRM(e.target.value));
document.getElementById('segmentSelect')?.addEventListener('change', updateRecipientCount);
document.querySelectorAll('[data-nl-preview-toggle]').forEach(button => button.addEventListener('click', togglePreview));
document.querySelectorAll('[data-nl-send]').forEach(button => button.addEventListener('click', () => submitCampaign(button.dataset.nlSend === 'test')));
document.querySelectorAll('[data-campaign-preview]').forEach(button => button.addEventListener('click', () => openCampaignPreview(button.dataset.campaignPreview, button.dataset.campaignSubject)));
document.querySelector('[data-campaign-close]')?.addEventListener('click', closeCampaignPreview);
