/* Share controls — Web Share API + Facebook/X/email/copy-link fallback (CSP-safe) */
(function(){
  function ga(method,type){ if(typeof gtag==='function'){ gtag('event','share',{method:method,content_type:type||'blog'}); } }
  function flash(btn){
    btn.classList.add('is-copied');
    setTimeout(function(){ btn.classList.remove('is-copied'); }, 1600);
  }
  function legacyCopy(text,cb){
    try{
      var t=document.createElement('textarea');
      t.value=text; t.setAttribute('readonly',''); t.style.position='absolute'; t.style.left='-9999px';
      document.body.appendChild(t); t.select(); document.execCommand('copy'); document.body.removeChild(t); cb();
    }catch(e){}
  }
  function copy(text,btn,type){
    function done(){ ga('copy',type); flash(btn); }
    if(navigator.clipboard&&navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(done).catch(function(){ legacyCopy(text,done); });
    } else { legacyCopy(text,done); }
  }
  function init(){
    var bars=document.querySelectorAll('.share-bar'); if(!bars.length) return;
    var canNative = typeof navigator!=='undefined' && typeof navigator.share==='function';
    bars.forEach(function(bar){
      var url=bar.getAttribute('data-share-url')||location.href;
      var title=bar.getAttribute('data-share-title')||document.title;
      var type=bar.getAttribute('data-share-type')||'blog';
      var nb=bar.querySelector('[data-share-native]');
      if(nb && canNative){
        nb.hidden=false;
        nb.addEventListener('click',function(){
          navigator.share({title:title,url:url}).then(function(){ga('native',type);}).catch(function(){});
        });
      }
      var cb=bar.querySelector('[data-share-copy]');
      if(cb){ cb.addEventListener('click',function(){ copy(url,cb,type); }); }
      bar.querySelectorAll('a[data-share]').forEach(function(a){
        a.addEventListener('click',function(){ ga(a.getAttribute('data-share'),type); });
      });
    });
  }
  if(document.readyState!=='loading'){init();}else{document.addEventListener('DOMContentLoaded',init);}
})();
