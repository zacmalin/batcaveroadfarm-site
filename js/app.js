
/* Scroll Reveal */
function initReveal() {
  const obs = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
  }, { threshold: 0.1 });
  document.querySelectorAll('.reveal:not(.visible)').forEach(el => obs.observe(el));
}
document.addEventListener('DOMContentLoaded', () => setTimeout(initReveal, 300));

/* Mobile nav toggle — keeps aria-expanded in sync for screen readers */
function toggleNav() {
  const nav = document.getElementById('main-nav');
  const btn = document.getElementById('hamburger-btn');
  const open = nav.classList.toggle('open');
  if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
function closeNav() {
  const nav = document.getElementById('main-nav');
  const btn = document.getElementById('hamburger-btn');
  nav.classList.remove('open');
  if (btn) btn.setAttribute('aria-expanded', 'false');
}

/* FAQ Accordion */