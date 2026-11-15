/* js/min.phone.js — phone boxes tidy themselves to (828) 555-0100 as you type (Nov 15, 2026).
   Works on every input type="tel" (and any input with data-phone). Bundled into min.site.js for the
   public site; Farm OS pages load this file on its own. Only US numbers are touched: anything that starts
   with + (except +1 and 10 digits), has letters (ext. 12, x5) or has more than 11 digits stays exactly as typed.
   Backspace works normally; the box is tidied again when you leave it. Saved numbers are left alone until edited. Add data-phone="off" to opt out. */
(function(){
  'use strict';
  function fmt(v){
    var s=String(v||''), d=s.replace(/\D/g,'');
    if(/[a-z]/i.test(s)) return s;                              // extension or words: leave it
    if(s.trim().charAt(0)==='+'){ if(!(d.length===11&&d.charAt(0)==='1')) return s; d=d.slice(1); }
    else if(d.length===11&&d.charAt(0)==='1') d=d.slice(1);       // 1-828-555-0100
    if(d.length>10) return s;
    if(!d) return '';
    if(d.length<4) return '('+d;
    if(d.length<7) return '('+d.slice(0,3)+') '+d.slice(3);
    return '('+d.slice(0,3)+') '+d.slice(3,6)+'-'+d.slice(6);
  }
  function want(el){
    return el&&el.tagName==='INPUT'&&((el.type||'').toLowerCase()==='tel'||el.hasAttribute('data-phone'))&&el.getAttribute('data-phone')!=='off'&&!el.readOnly;
  }
  function apply(el){
    var old=el.value, nv=fmt(old);
    if(nv===old) return;
    var pos=el.selectionStart, digitsBefore=-1;
    if(pos!==null&&pos<old.length) digitsBefore=old.slice(0,pos).replace(/\D/g,'').length;
    if(el.maxLength>0&&nv.length>el.maxLength) return;
    el.value=nv;
    if(digitsBefore>=0&&document.activeElement===el){
      // keep the cursor after the same digit when fixing the middle of a number
      var n=0,i=0; if(old.trim().charAt(0)==='+'||(old.replace(/\D/g,'').length===11)) digitsBefore=Math.max(0,digitsBefore-1);
      for(;i<nv.length&&n<digitsBefore;i++){ if(/\d/.test(nv.charAt(i))) n++; }
      try{ el.setSelectionRange(i,i); }catch(e){}
    }
  }
  document.addEventListener('input',function(e){
    var el=e.target;
    if(!want(el)) return;
    if(e.inputType&&e.inputType.indexOf('delete')===0) return;   // let backspace remove ( ) - freely
    if(/\s$/.test(el.value)) return;                              // a space after the number: maybe "x12" next
    apply(el);
  },true);
  document.addEventListener('blur',function(e){ if(want(e.target)) apply(e.target); },true);
  window.bcrfPhoneFormat=fmt;
})();
