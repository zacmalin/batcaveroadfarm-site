/* min.formcheck.js — form error text, shown only after someone has filled in a field or tried to send (Nov 15, 2026).
   The red border comes from CSS :user-invalid (min.style.css). This adds a short message under the field
   (the browser's own wording, e.g. "Please include an '@' in the email address"), links it with
   aria-describedby and sets aria-invalid, so screen readers hear the same thing people see.
   Messages update quietly as you type and vanish the moment the field is valid. Bundled into min.site.js. */
(function(){
  'use strict';
  var n=0;
  function skip(el){
    if(!el||!el.form||!el.willValidate||el.form.noValidate) return true;
    var t=(el.type||'').toLowerCase();
    if(t==='hidden'||t==='submit'||t==='button'||t==='reset'||t==='image'||t==='file') return true;
    if(el.tabIndex<0||el.closest('.hp,.auth-hp,[aria-hidden="true"]')) return true;   // honeypots
    return false;
  }
  function userInvalid(el){
    try{ return el.matches(':user-invalid'); }catch(e){ return el.classList.contains('is-user-invalid'); }
  }
  function host(el){
    // Put the message under the whole row when the field sits in a side-by-side row (e.g. + / − buttons).
    var t=(el.type||'').toLowerCase(), p=el.parentElement;
    if(t==='checkbox'||t==='radio'){ var l=el.closest('label'); if(l) return l; }
    if(p&&p.tagName!=='FORM'){ var cs=getComputedStyle(p); if(cs.display.indexOf('flex')>-1&&cs.flexDirection.indexOf('row')===0) return p; }
    return el;
  }
  function msgFor(el){ var id=el.getAttribute('data-error-id'); return id?document.getElementById(id):null; }
  function show(el){
    var m=msgFor(el);
    if(!m){
      m=document.createElement('span'); m.className='field-error'; m.id='fe-'+(++n);
      el.setAttribute('data-error-id',m.id);
      var h=host(el); h.insertAdjacentElement('afterend',m);
      var d=(el.getAttribute('aria-describedby')||'').trim();
      el.setAttribute('aria-describedby',(d?d+' ':'')+m.id);
    }
    if(m.textContent!==el.validationMessage) m.textContent=el.validationMessage;
    el.setAttribute('aria-invalid','true');
  }
  function hide(el){
    var m=msgFor(el); if(!m) return;
    var d=(el.getAttribute('aria-describedby')||'').split(/\s+/).filter(function(x){return x&&x!==m.id;}).join(' ');
    if(d) el.setAttribute('aria-describedby',d); else el.removeAttribute('aria-describedby');
    el.removeAttribute('data-error-id'); el.removeAttribute('aria-invalid'); m.remove();
  }
  function check(el,committed){
    if(skip(el)) return;
    if(el.validity.valid){ el.classList.remove('is-user-invalid'); hide(el); return; }
    if(committed) el.classList.add('is-user-invalid');            // fallback where :user-invalid isn't known
    if(msgFor(el)||(committed&&userInvalid(el))) show(el);        // while typing: only update a message already showing
  }
  document.addEventListener('focusout',function(e){ var el=e.target; if(el&&el.validity&&el.value!=='') check(el,true); },true);
  document.addEventListener('input',function(e){ var el=e.target; if(el&&el.validity) check(el,false); },true);
  document.addEventListener('change',function(e){ var el=e.target; if(el&&el.validity) check(el,true); },true);
  // Tried to send: the browser fires "invalid" on each bad field and focuses the first one itself.
  document.addEventListener('invalid',function(e){ var el=e.target; if(skip(el)) return; el.classList.add('is-user-invalid'); show(el); },true);
})();
