/* Subscribe modal — opens from any [data-subscribe-open]; posts to subscribe.php via fetch (CSP-safe) */
(function(){
  var modal,form,msg,lastFocus;
  function open(){
    if(!modal) return;
    lastFocus=document.activeElement;
    modal.hidden=false; document.body.style.overflow='hidden';
    var email=document.getElementById('sub-modal-email'); if(email){email.focus();}
  }
  function close(){
    if(!modal) return;
    modal.hidden=true; document.body.style.overflow='';
    if(msg){msg.hidden=true; msg.textContent=''; msg.className='subscribe-msg';}
    if(lastFocus&&lastFocus.focus){lastFocus.focus();}
  }
  function showMsg(text,ok){
    if(!msg) return;
    msg.textContent=text; msg.className='subscribe-msg '+(ok?'is-ok':'is-err'); msg.hidden=false;
  }
  function submit(e){
    e.preventDefault();
    var btn=form.querySelector('button[type=submit]'); if(btn){btn.disabled=true;}
    var data=new FormData(form); data.append('ajax','1');
    fetch('https://batcaveroadfarm.com/subscribe.php',{method:'POST',body:data,headers:{'X-Requested-With':'fetch'}})
      .then(function(r){return r.json();})
      .then(function(j){
        showMsg(j.message||'',!!j.ok);
        if(j.ok){
          if(typeof gtag==='function'){gtag('event','newsletter_signup',{method:'modal'});}
          form.reset();
          setTimeout(close,6000);
        }
      })
      .catch(function(){ showMsg('Something went wrong — please try again.',false); })
      .finally(function(){ if(btn){btn.disabled=false;} });
  }
  function init(){
    modal=document.getElementById('subscribe-modal');
    form=document.getElementById('subscribe-form');
    msg=document.getElementById('subscribe-msg');
    document.querySelectorAll('[data-subscribe-open]').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();open();});});
    var x=document.getElementById('subscribe-close'); if(x){x.addEventListener('click',close);}
    if(modal){modal.addEventListener('click',function(e){if(e.target===modal){close();}});}
    if(form){form.addEventListener('submit',submit);}
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal&&!modal.hidden){close();}});
  }
  if(document.readyState!=='loading'){init();}else{document.addEventListener('DOMContentLoaded',init);}
})();
