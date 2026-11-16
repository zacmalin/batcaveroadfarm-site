(function () {
    var toggle = document.getElementById('staffPasswordToggle');
    var password = document.getElementById('password');
    if (!toggle || !password) return;
    toggle.addEventListener('click', function () {
        var showing = password.type === 'password';
        password.type = showing ? 'text' : 'password';
        toggle.textContent = showing ? 'Hide' : 'Show';
        toggle.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', showing ? 'true' : 'false');
    });
})();
