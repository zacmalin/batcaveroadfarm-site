<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bat Cave Road Farm Unified ERP Engine Deck</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        @media print {
            body, html { background: #fff !important; color: #000 !important; width: 3in; height: auto; margin: 0; padding: 10px; }
            aside, header, #toastNotification, .print\:hidden { display: none !important; }
        }
        @keyframes slideUpFade {
            0%   { transform: translateY(1rem); opacity: 0; }
            10%  { transform: translateY(0);    opacity: 1; }
            90%  { transform: translateY(0);    opacity: 1; }
            100% { transform: translateY(1rem); opacity: 0; visibility: hidden; }
        }
        .animate-toast { animation: slideUpFade 4s ease-in-out forwards; }
    </style>
</head>
<!-- ── SYSTEM MODAL (replaces browser alert/confirm) ── -->
<div id="sysModal" class="fixed inset-0 z-[999] hidden" aria-modal="true" role="dialog">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-stone-900/60 backdrop-blur-sm" id="sysModalBackdrop"></div>
    <!-- Card -->
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md flex flex-col" style="max-height:80vh;">
            <!-- Header -->
            <div class="flex items-start justify-between px-6 pt-5 pb-3">
                <div class="flex items-center gap-3">
                    <span id="sysModalIcon" class="text-2xl leading-none"></span>
                    <h2 id="sysModalTitle" class="font-bold text-stone-800 font-serif text-lg leading-tight"></h2>
                </div>
                <button onclick="sysModalCancel()" id="sysModalClose"
                    class="text-stone-400 hover:text-stone-700 text-2xl font-bold leading-none ml-4 mt-0.5 shrink-0">&times;</button>
            </div>
            <!-- Body -->
            <div id="sysModalBody" class="px-6 pb-4 text-sm text-stone-600 leading-relaxed overflow-y-auto"></div>
            <!-- Footer -->
            <div id="sysModalFooter" class="px-6 pb-5 pt-3 border-t flex justify-end gap-3"></div>
        </div>
    </div>
</div>

<script>
// ── SYSTEM MODAL LIBRARY ──────────────────────────────────────────────────────
// Replaces browser alert() and confirm() with styled modals.
//
// Usage:
//   await sysAlert('Something happened.', 'Notice');
//   const yes = await sysConfirm('Are you sure?', 'Confirm Delete');

let _sysModalResolve = null;

function _sysModalShow({ icon = '', title = '', body = '', buttons = [] }) {
    document.getElementById('sysModalIcon').textContent  = icon;
    document.getElementById('sysModalTitle').textContent = title;
    document.getElementById('sysModalBody').innerHTML    = body;

    const footer = document.getElementById('sysModalFooter');
    footer.innerHTML = '';
    buttons.forEach(btn => {
        const el = document.createElement('button');
        el.textContent = btn.label;
        el.className   = btn.primary
            ? 'bg-stone-900 text-white font-mono font-bold uppercase tracking-wider px-5 py-2 rounded text-xs hover:bg-stone-700 transition shadow-xs'
            : 'border font-mono font-bold uppercase tracking-wider px-5 py-2 rounded text-xs text-stone-600 hover:bg-stone-100 transition';
        el.onclick = btn.action;
        footer.appendChild(el);
    });

    // Close button visibility — hide on alert (no cancel action)
    document.getElementById('sysModalClose').style.display = buttons.length > 1 ? '' : 'none';

    const modal = document.getElementById('sysModal');
    modal.classList.remove('hidden');
    // Focus first button
    setTimeout(() => footer.querySelector('button')?.focus(), 50);
}

function _sysModalHide() {
    document.getElementById('sysModal').classList.add('hidden');
}

function sysModalCancel() {
    _sysModalHide();
    if (_sysModalResolve) { _sysModalResolve(false); _sysModalResolve = null; }
}

// ── PUBLIC API ────────────────────────────────────────────────────────────────

function sysAlert(message, title = 'Notice', icon = 'ℹ️') {
    return new Promise(resolve => {
        _sysModalResolve = resolve;
        _sysModalShow({
            icon, title,
            body: `<p>${message}</p>`,
            buttons: [{
                label: 'OK', primary: true,
                action: () => { _sysModalHide(); resolve(true); _sysModalResolve = null; }
            }]
        });
    });
}

function sysConfirm(message, title = 'Confirm', icon = '⚠️', confirmLabel = 'Confirm', confirmDanger = false) {
    return new Promise(resolve => {
        _sysModalResolve = resolve;
        const confirmBtn = document.createElement('button');
        _sysModalShow({
            icon, title,
            body: `<p>${message}</p>`,
            buttons: [
                {
                    label: 'Cancel', primary: false,
                    action: () => { _sysModalHide(); resolve(false); _sysModalResolve = null; }
                },
                {
                    label: confirmLabel, primary: true,
                    action: () => { _sysModalHide(); resolve(true); _sysModalResolve = null; }
                }
            ]
        });
        // Style confirm button red if danger
        if (confirmDanger) {
            const btns = document.getElementById('sysModalFooter').querySelectorAll('button');
            const last = btns[btns.length - 1];
            last.className = last.className.replace('bg-stone-900', 'bg-red-700').replace('hover:bg-stone-700', 'hover:bg-red-900');
        }
    });
}

// Keyboard: Escape = cancel, Enter = confirm first primary button
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('sysModal');
    if (modal.classList.contains('hidden')) return;
    if (e.key === 'Escape') { e.preventDefault(); sysModalCancel(); }
    if (e.key === 'Enter') {
        e.preventDefault();
        const primary = document.getElementById('sysModalFooter').querySelector('button:last-child');
        if (primary) primary.click();
    }
});

// Any element with data-confirm="message" gets a sysConfirm dialog instead of
// the browser native confirm(). Works for submit buttons and links.
// Optional attributes: data-confirm-title, data-confirm-label
document.addEventListener('click', async function(e) {
    const el = e.target.closest('[data-confirm]');
    if (!el || el.dataset.confirmed === '1') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const msg   = el.dataset.confirm;
    const title = el.dataset.confirmTitle || 'Are you sure?';
    const label = el.dataset.confirmLabel || 'Confirm';
    const yes   = await sysConfirm(msg, title, '⚠️', label);
    if (yes) {
        el.dataset.confirmed = '1';
        const form = el.closest('form');
        if (form) { form.submit(); }
        else if (el.tagName === 'A' && el.href) { window.location.href = el.href; }
        else { el.click(); }
    }
}, true); // capture phase so it fires before other listeners

</script>
<!-- Admin list search + sort (external, CSP-safe) -->
<script defer src="/admin/assets/admin-list.js?v=20260615b"></script>

