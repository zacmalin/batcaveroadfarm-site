/* Recipe detail: serving scaler + image lightbox + print — replaces inline JS (CSP-safe) */
(function(){
  var FR=[[0.125,'\u215B'],[0.25,'\u00BC'],[0.333,'\u2153'],[0.5,'\u00BD'],[0.667,'\u2154'],[0.75,'\u00BE']];
  function fmtQty(q,metric){
    if(!isFinite(q)){return '';}
    if(metric){var dp=q>=100?0:(q>=10?1:2);return q.toFixed(dp).replace(/\.?0+$/,'');} // metric: clean decimal, no fractions
    var whole=Math.floor(q),rem=q-whole;
    for(var i=0;i<FR.length;i++){if(Math.abs(rem-FR[i][0])<0.02){return (whole>0?whole+' ':'')+FR[i][1];}}
    return q.toFixed(2).replace(/\.?0+$/,'');
  }
  function pickBest(cands,qty){
    // cands: [[factor,abbr],...]; choose the friendliest magnitude for this qty.
    var best=null,bv=0;
    for(var i=0;i<cands.length;i++){
      var f=parseFloat(cands[i][0]);if(!isFinite(f)||f===0){continue;}
      var val=qty*f;if(val<=0){continue;}
      if(best===null){best=cands[i];bv=val;continue;}
      var cGe=(val>=1),bGe=(bv>=1);
      if(cGe&&bGe){if(val<bv){best=cands[i];bv=val;}}        // both readable -> larger unit
      else if(cGe&&!bGe){best=cands[i];bv=val;}              // prefer the one >= 1
      else if(!cGe&&!bGe){if(val>bv){best=cands[i];bv=val;}} // both tiny -> closest to 1
    }
    return best?{qty:bv,unit:best[1]}:null;
  }
  function parseCands(row,attr){
    var raw=row.getAttribute(attr);if(!raw){return [];}
    try{var a=JSON.parse(raw);return Array.isArray(a)?a:[];}catch(e){return [];}
  }
  function recompute(){
    var uSel=document.getElementById('recipeUnitSelector');
    var mSel=document.getElementById('recipeMultiplierSelector');
    var sys=(uSel&&uSel.value)?uSel.value:'orig';
    var factor=parseFloat((mSel&&mSel.value)?mSel.value:'1')||1;
    document.querySelectorAll('.ingredient-row').forEach(function(row){
      var origQty=parseFloat(row.getAttribute('data-orig-qty'));if(isNaN(origQty)){origQty=0;}
      var scaled=origQty*factor;
      var qty=scaled,unit=row.getAttribute('data-orig-unit')||'';
      if(sys==='metric'||sys==='us'){
        var b=pickBest(parseCands(row,'data-'+sys+'-cands'),scaled);
        if(b){qty=b.qty;unit=b.unit;}                        // else no conversion -> keep original
      }
      var q=row.querySelector('.qty-display');if(q){q.textContent=fmtQty(qty,sys==='metric');}
      var u=row.querySelector('.unit');if(u){u.textContent=unit;}
      var t=row.querySelector('.title-display');
      if(t){var sing=row.getAttribute('data-singular-title'),plur=row.getAttribute('data-plural-title');t.textContent=(qty<=1.00)?sing:plur;}
    });
  }
  function init(){
    var mSel=document.getElementById('recipeMultiplierSelector');
    if(mSel){mSel.addEventListener('change',recompute);}
    var uSel=document.getElementById('recipeUnitSelector');
    if(uSel){uSel.addEventListener('change',recompute);}
    recompute();
    var lb=document.getElementById('recipeLightbox');
    function openLB(){if(lb){lb.classList.add('open');document.body.style.overflow='hidden';var c=lb.querySelector('.recipe-lightbox-close');if(c){c.focus();}}}
    function closeLB(){if(lb){lb.classList.remove('open');document.body.style.overflow='';}}
    var btn=document.querySelector('.recipe-img-btn');
    if(btn){btn.addEventListener('click',openLB);}
    if(lb){lb.addEventListener('click',function(e){if(e.target===lb||e.target.classList.contains('recipe-lightbox-close')){closeLB();}});}
    var pr=document.querySelector('.recipe-print-btn');
    if(pr){pr.addEventListener('click',function(){window.print();});}
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeLB();}});
  }
  if(document.readyState!=='loading'){init();}else{document.addEventListener('DOMContentLoaded',init);}
})();
