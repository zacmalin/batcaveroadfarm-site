/* js/min.gtag.js — GA4 setup. Emails and private tokens are removed from page addresses before GA sees
   them (unsubscribe, subscribe confirm, password reset, account verify, pass and booking links), and
   signed-in staff visits are tagged internal (<html data-ga-internal="1">, set by includes/ga.php). */
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());
(function(){
  var drop=/^(e|email|t|token|ts|sig|key|code|session_id)$/i, cfg={};
  function clean(href){
    try{
      var u=new URL(href), keys=[], changed=false;
      u.searchParams.forEach(function(v,k){keys.push(k);});
      keys.forEach(function(k){ if(drop.test(k)){ u.searchParams.delete(k); changed=true; } });
      return changed?u.toString():null;
    }catch(x){ return null; }
  }
  var loc=clean(location.href); if(loc) cfg.page_location=loc;
  if(document.referrer){
    try{ if(new URL(document.referrer).origin===location.origin){ var ref=clean(document.referrer); if(ref) cfg.page_referrer=ref; } }catch(x){}
  }
  if(document.documentElement.getAttribute('data-ga-internal')==='1') cfg.traffic_type='internal';
  gtag('config','G-DLB6LEFWQZ',cfg);
})();
