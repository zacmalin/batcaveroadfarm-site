// admin/assets/report.js — print button on the drawer report (register.php?view=drawer); strict CSP, so no inline handler.
document.addEventListener('DOMContentLoaded', function () {
  var b = document.getElementById('btnPrint');
  if (b) b.addEventListener('click', function () { window.print(); });
});
