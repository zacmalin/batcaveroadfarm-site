/* min.comments.js — posts comments via AJAX, prepends the new one, reuses the login modal for logged-out users. */
(function () {
  'use strict';

  var DRAFT = 'bcrf_pending_comment';
  var form    = document.getElementById('commentForm');
  var listEl  = document.getElementById('commentList');
  var countEl = document.getElementById('commentCount');
  var loginBtn = document.getElementById('commentLoginBtn');

  function getSession() {
    return fetch('/account/session.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { loggedIn: false, csrf: '' }; });
  }
  function openLogin() {
    if (window.bcrfAuth && typeof window.bcrfAuth.open === 'function') window.bcrfAuth.open('login');
    else window.location.href = '/account/login.php?next=' + encodeURIComponent(location.pathname + location.search);
  }

  if (loginBtn) loginBtn.addEventListener('click', function (e) { e.preventDefault(); openLogin(); });

  if (form) {
    var ta      = form.querySelector('[name="comment_text"]');
    var btn     = form.querySelector('[data-comment-submit]');
    var msgEl   = form.querySelector('.comment-msg');
    var counter = form.querySelector('.comment-counter');
    var MAX = 2000;
    var rid = parseInt(form.getAttribute('data-recipe-id'), 10) || 0;

    function setMsg(m, ok) {
      if (!msgEl) return;
      msgEl.textContent = m || '';
      msgEl.style.display = m ? 'block' : 'none';
      msgEl.classList.toggle('is-ok', !!ok);
      msgEl.classList.toggle('is-error', !!m && !ok);
    }
    function updateCounter() { if (counter && ta) counter.textContent = (MAX - ta.value.length) + ' left'; }

    // Restore a draft stashed before a forced login.
    try {
      var d = JSON.parse(sessionStorage.getItem(DRAFT) || 'null');
      if (d && d.recipeId === rid && ta) { ta.value = d.text; sessionStorage.removeItem(DRAFT); ta.focus(); }
    } catch (e) {}

    if (ta) { ta.addEventListener('input', updateCounter); }
    updateCounter();

    function submit() {
      setMsg('');
      var text = ta ? ta.value.trim() : '';
      if (!text) { setMsg('Please write a comment first.', false); return; }
      if (text.length > MAX) { setMsg('Comments are limited to ' + MAX + ' characters.', false); return; }
      if (btn) btn.disabled = true;

      getSession().then(function (s) {
        fetch('/recipes/comment.php', {
          method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ recipe_id: rid, comment_text: text, csrf: s.csrf })
        })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (btn) btn.disabled = false;
            if (j && j.needLogin) {
              try { sessionStorage.setItem(DRAFT, JSON.stringify({ recipeId: rid, text: text })); } catch (e) {}
              openLogin();
              return;
            }
            if (j && j.ok) {
              if (ta) { ta.value = ''; updateCounter(); }
              setMsg(j.message || 'Thanks! Your comment is awaiting moderation.', true);
            } else {
              setMsg((j && j.error) || 'Could not post your comment.', false);
            }
          })
          .catch(function () { if (btn) btn.disabled = false; setMsg('A network error occurred. Please try again.', false); });
      });
    }

    if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); submit(); });
  }
})();
