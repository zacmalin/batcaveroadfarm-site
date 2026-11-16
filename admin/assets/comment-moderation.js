
function selectAll(state) {
    document.querySelectorAll('.mod-cb').forEach(cb => cb.checked = state);
    updateCount();
}

function updateCount() {
    const n = document.querySelectorAll('.mod-cb:checked').length;
    document.getElementById('selectedCount').textContent = n + ' selected';
}

async function submitAction(action) {
    const checked = document.querySelectorAll('.mod-cb:checked');
    if (checked.length === 0) {
        await sysAlert('Select at least one comment first.', 'Nothing Selected', 'ℹ️');
        return;
    }
    const label = action === 'approve' ? 'approve' : 'reject';
    const confirmed = await sysConfirm(`${label.charAt(0).toUpperCase() + label.slice(1)} <strong>${checked.length}</strong> comment${checked.length !== 1 ? 's' : ''}?`, 'Confirm Action', action === 'reject' ? '❌' : '✅', label.charAt(0).toUpperCase() + label.slice(1), action === 'reject');
    if (!confirmed) return;
    document.getElementById('modAction').value = action;
    document.getElementById('modForm').submit();
}

// CSP-safe event bindings.
document.querySelector('[data-mod-select-all]')?.addEventListener('click', () => selectAll(true));
document.querySelector('[data-mod-select-none]')?.addEventListener('click', () => selectAll(false));
document.querySelectorAll('.mod-cb').forEach(cb => cb.addEventListener('change', updateCount));
document.querySelectorAll('[data-mod-action]').forEach(button => button.addEventListener('click', () => submitAction(button.dataset.modAction)));
