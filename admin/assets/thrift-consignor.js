// Search existing CRM customers without exposing IDs as a staff entry requirement.
document.addEventListener('DOMContentLoaded', () => {
  const input = document.querySelector('[data-consignor-search]');
  const id = document.querySelector('[data-consignor-id]');
  const results = document.querySelector('[data-consignor-results]');
  if (!input || !id || !results) return;
  let request = 0;
  if (id.value) input.placeholder = 'Customer #' + id.value + ' selected; search to change';
  input.addEventListener('input', async () => {
    const seq = ++request;
    id.value = '';
    results.replaceChildren();
    const q = input.value.trim();
    if (q.length < 2) return;
    try {
      const response = await fetch('thrift.php?consignor_search=' + encodeURIComponent(q), {credentials: 'same-origin'});
      if (!response.ok) throw new Error('Search unavailable');
      const matches = await response.json();
      if (seq !== request) return;
      if (!matches.length) { results.textContent = 'No matching customers'; return; }
      const list = document.createElement('div');
      for (const customer of matches) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn sm';
        button.style.display = 'block';
        button.style.marginTop = '0.35rem';
        button.textContent = customer.label;
        button.addEventListener('click', () => {
          ++request;
          id.value = String(customer.id);
          input.value = customer.label;
          results.replaceChildren();
        });
        list.appendChild(button);
      }
      results.replaceChildren(list);
    } catch (_) { if (seq === request) results.textContent = 'Customer search unavailable'; }
  });
});