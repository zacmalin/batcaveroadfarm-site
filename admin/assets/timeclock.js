// Time clock: confirm clock-out before posting, preserving the submit button value.
(function () {
    'use strict';
    const button = document.querySelector('[data-clock-out-confirm]');
    if (!button) return;
    const form = button.form;
    let confirming = false;
    form.addEventListener('submit', async function (event) {
        if (event.submitter !== button && event.submitter !== null) return;
        if (form.dataset.clockOutConfirmed === '1') return;
        event.preventDefault();
        if (confirming) return;
        confirming = true;
        try {
            const confirmed = await sysConfirm('Clock out and end your current shift?', 'Clock Out', '🕒', 'Clock Out');
            if (!confirmed) return;
            form.dataset.clockOutConfirmed = '1';
            button.disabled = true;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = button.name;
            hidden.value = button.value;
            form.appendChild(hidden);
            form.submit();
        } finally {
            confirming = false;
        }
    });

    const display = document.getElementById('elapsedDisplay');
    let elapsedSecs = Number(display?.dataset.elapsedSeconds || 0);
    function fmtDuration(s) {
        if (s <= 0) return '0m';
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        return (h > 0 ? h + 'h ' : '') + m + 'm';
    }
    function tick() {
        elapsedSecs += 60;
        for (const id of ['elapsedDisplay', 'weeklyElapsedExtra', 'rowElapsed']) {
            const element = document.getElementById(id);
            if (element) element.textContent = fmtDuration(elapsedSecs);
        }
    }
    setInterval(tick, 60000);
}());
