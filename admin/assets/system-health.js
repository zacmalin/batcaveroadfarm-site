// admin/assets/system-health.js
// System Health: one section open at a time; #errors etc. in the address opens that section (moved out of the page for CSP, Nov 17, 2026).
(function () {
  document.querySelectorAll('.fs-health .health-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
      var opening = button.getAttribute('aria-expanded') !== 'true';
      document.querySelectorAll('.fs-health .health-toggle').forEach(function (other) {
        other.setAttribute('aria-expanded', 'false');
        document.getElementById(other.getAttribute('aria-controls')).hidden = true;
      });
      if (opening) {
        button.setAttribute('aria-expanded', 'true');
        document.getElementById(button.getAttribute('aria-controls')).hidden = false;
      }
    });
  });
  if (location.hash) {
    var section = document.getElementById(location.hash.slice(1));
    var button = section && section.querySelector('.health-toggle');
    if (button && button.getAttribute('aria-expanded') !== 'true') button.click();
  }
}());
