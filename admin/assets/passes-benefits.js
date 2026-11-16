(function () {
    var list = document.getElementById('perks-list');
    var add = document.getElementById('addPerkButton');
    if (list && add) {
        add.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'flex gap-2 items-center perk-row';
            row.innerHTML = '<input type="text" name="pt[perks][]" placeholder="e.g. 10% off farmstand purchases" class="flex-1 p-2 border border-stone-300 rounded bg-stone-50 text-sm">' +
                '<button type="button" data-perk-remove class="text-stone-400 hover:text-red-500 text-lg leading-none px-1" title="Remove benefit" aria-label="Remove benefit">×</button>';
            list.appendChild(row);
            row.querySelector('input').focus();
        });
        list.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-perk-remove]');
            if (remove) remove.closest('.perk-row').remove();
        });
    }
    var limit = document.getElementById('has-limit-cb');
    var quantity = document.getElementById('limit-qty');
    if (limit && quantity) {
        limit.addEventListener('change', function () {
            quantity.style.display = limit.checked ? '' : 'none';
        });
        document.getElementById('passLimitQuantity')?.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }
})();
