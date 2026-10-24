/* js/min.jobs-apply.js — Jobs application form: show guardian fields for under-18, check résumé size before upload. */
(function () {
  var form = document.getElementById('jb-apply');
  if (!form) return;
  var box = document.getElementById('jb-guardian');
  function syncAge() {
    var r = form.querySelector('input[name="age_bracket"]:checked');
    var minor = !!(r && r.value !== '18_plus');
    if (!box) return;
    box.hidden = !minor;
    box.querySelectorAll('[data-minor-req]').forEach(function (el) { el.required = minor; });
  }
  form.querySelectorAll('input[name="age_bracket"]').forEach(function (r) { r.addEventListener('change', syncAge); });
  syncAge();

  var file = document.getElementById('jb-resume');
  var max = 5 * 1024 * 1024;
  if (file) file.addEventListener('change', function () {
    var f = file.files && file.files[0];
    file.setCustomValidity('');
    if (!f) return;
    if (f.size > max) file.setCustomValidity('That file is over 5 MB. Please choose a smaller PDF.');
    else if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') file.setCustomValidity('Please choose a PDF file.');
    file.reportValidity();
  });

  var btn = form.querySelector('button[type="submit"]');
  form.addEventListener('submit', function () {
    if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; }
  });
  // Re-enable if the browser comes back to this page from history.
  window.addEventListener('pageshow', function () { if (btn) { btn.disabled = false; btn.textContent = btn.getAttribute('data-label') || 'Send application'; } });
})();
