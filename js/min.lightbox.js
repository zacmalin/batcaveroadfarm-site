/* Shared lightbox for item/animal detail pages — replaces inline scripts + onclick (CSP-safe) */
(function(){
  function init(){
    var lb=document.querySelector('.lightbox');if(!lb)return;
    var photo=document.querySelector('.img-zoom');
    function open(){lb.classList.add('open');document.body.style.overflow='hidden';var c=lb.querySelector('.lightbox-close');if(c){c.focus();}}
    function close(){lb.classList.remove('open');document.body.style.overflow='';}
    if(photo){photo.addEventListener('click',open);photo.style.cursor='zoom-in';}
    lb.addEventListener('click',function(e){if(e.target===lb||e.target.classList.contains('lightbox-close')){close();}});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){close();}});
  }
  if(document.readyState!=='loading'){init();}else{document.addEventListener('DOMContentLoaded',init);}
})();
