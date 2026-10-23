/* js/min.analytics.js — Lightweight GA4 intent events (CSP-safe; uses the global gtag from min.gtag.js) */
(function(){
  function ev(name,params){ if(typeof gtag==='function'){ gtag('event',name,params||{}); } }
  document.addEventListener('click',function(e){
    var a=e.target.closest?e.target.closest('a'):null; if(!a) return;
    var href=a.getAttribute('href')||'';
    var dga=a.getAttribute('data-ga');
    if(dga==='get_directions' || /(?:maps\.google|google\.[a-z.]+\/maps)/i.test(href)){
      ev('get_directions');
    } else if(dga==='recipe_to_stand'){
      ev('recipe_to_stand',{content_type:'recipe_ingredient'});
    } else if(href.indexOf('tel:')===0){
      ev('click_to_call');
    }
  });
  function leadCheck(){
    try{
      var b=document.body;
      var name=b?b.getAttribute('data-ga-event'):'';
      if(name){
        var form=b.getAttribute('data-ga-form');
        ev(name, form?{form_name:form}:{});
      }
    }catch(e){}
    if(/^\/contact-us\/?/.test(location.pathname) && /[?&]sent=1(?:&|$)/.test(location.search)){
      ev('generate_lead',{form_name:'contact'});
    }
  }
  if(document.readyState!=='loading'){leadCheck();}else{document.addEventListener('DOMContentLoaded',leadCheck);}
})();
