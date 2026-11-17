// Receiving form: add rows in place without losing unsaved quantities or costs.
document.addEventListener('DOMContentLoaded', () => {
  const group = document.querySelector('[data-receive-lines]');
  const button = document.querySelector('[data-receive-add]');
  const form = group?.closest('form');
  const countInput = form?.querySelector('input[name="_lines"]');
  const countLabel = group?.querySelector('[data-receive-count]');
  if (!group || !button || !form || !countInput) return;
  button.addEventListener('click', () => {
    const rows = group.querySelectorAll('[data-receive-line]');
    const remaining = Math.max(0, 20 - rows.length);
    if (!remaining) return;
    const source = rows[rows.length - 1];
    if (!source) return;
    for (let i = 0; i < Math.min(4, remaining); i++) {
      const row = source.cloneNode(true);
      row.querySelectorAll('input, select, textarea').forEach(field => {
        if (field instanceof HTMLSelectElement) field.selectedIndex = 0;
        else if (field instanceof HTMLInputElement && (field.type === 'checkbox' || field.type === 'radio')) field.checked = false;
        else field.value = '';
      });
      const number = row.querySelector('[data-receive-number]');
      if (number) number.textContent = 'Line ' + (rows.length + i + 1);
      source.parentNode.insertBefore(row, button.closest('p'));
    }
    const total = group.querySelectorAll('[data-receive-line]').length;
    countInput.value = String(total);
    if (countLabel) countLabel.textContent = String(total);
    if (total >= 20) button.hidden = true;
  });
});
