const form=document.querySelector("#contact"),submitBtn=document.querySelector(".form-submit");form.addEventListener("submit",function(){submitBtn.textContent="Sending...\uD83C\uDF3F",submitBtn.disabled=!0}),document.getElementById("phone").addEventListener("input",function(t){var e=t.target.value.replace(/\D/g,"").match(/(\d{0,3})(\d{0,3})(\d{0,4})/);t.target.value=e[2]?"("+e[1]+") "+e[2]+(e[3]?"-"+e[3]:""):e[1]});
/* dismiss the post-submit modal (close button, backdrop, Escape) and tidy the URL */
function bcrfThanks(){var m=document.getElementById("thanksModal");if(!m)return;
function close(){m.style.display="none";if(history.replaceState){history.replaceState(null,"",location.pathname);}}
m.addEventListener("click",function(e){if(e.target===m||e.target.classList.contains("thanks-close")){e.preventDefault();close();}});
var b=m.querySelector(".cta-btn");if(b){b.addEventListener("click",function(e){e.preventDefault();close();});}
document.addEventListener("keydown",function(e){if(e.key==="Escape"){close();}});
var c=m.querySelector(".thanks-close");if(c){c.focus();}}
if(document.readyState!=="loading"){bcrfThanks();}else{document.addEventListener("DOMContentLoaded",bcrfThanks);}
