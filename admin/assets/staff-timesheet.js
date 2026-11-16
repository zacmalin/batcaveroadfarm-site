(function () {
    if (new URLSearchParams(window.location.search).get('print') === '1') {
        window.addEventListener('load', function () { window.print(); });
        return;
    }
    document.getElementById('staffTimesheetPrint')?.addEventListener('click', function () {
        var params = new URLSearchParams(window.location.search);
        params.set('view', 'timesheet');
        params.set('print', '1');
        window.open('/admin/admin.php?' + params.toString(), '_blank');
    });
    document.getElementById('staffTimesheetPreset')?.addEventListener('change', function (event) {
        var show = event.target.value === 'custom';
        var from = document.getElementById('customFrom');
        var to = document.getElementById('customTo');
        if (from) from.style.display = show ? '' : 'none';
        if (to) to.style.display = show ? '' : 'none';
    });
})();
