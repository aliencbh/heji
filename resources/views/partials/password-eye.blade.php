<style>
.password-wrap{position:relative;display:block;width:100%}
.password-wrap input[type=password],.password-wrap input[type=text]{width:100%;padding-right:40px;box-sizing:border-box}
.password-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border:0;background:transparent;cursor:pointer;color:inherit;opacity:.7;padding:0}
.password-eye:hover{opacity:1}
.password-eye .eye-off{display:none}
.password-eye.is-shown .eye-on{display:none}
.password-eye.is-shown .eye-off{display:block}
</style>
<script>
(function(){
  function makeEye(){
    var btn=document.createElement('button');
    btn.type='button';
    btn.className='password-eye';
    btn.setAttribute('aria-label','\u663e\u793a\u5bc6\u7801');
    btn.setAttribute('aria-pressed','false');
    btn.setAttribute('data-testid','password-eye');
    btn.innerHTML='<svg class="eye-on" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    btn.addEventListener('click',function(){
      var input=btn.parentElement.querySelector('input');
      if(!input) return;
      var shown=input.type==='text';
      input.type=shown?'password':'text';
      btn.classList.toggle('is-shown',!shown);
      btn.setAttribute('aria-pressed',String(!shown));
      btn.setAttribute('aria-label',shown?'\u663e\u793a\u5bc6\u7801':'\u9690\u85cf\u5bc6\u7801');
      input.focus();
    });
    return btn;
  }
  function enhance(){
    document.querySelectorAll('input[type=password]').forEach(function(input){
      if(input.closest('.password-wrap')) return;
      var wrap=document.createElement('span');
      wrap.className='password-wrap';
      input.parentNode.insertBefore(wrap,input);
      wrap.appendChild(input);
      wrap.appendChild(makeEye());
    });
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',enhance);}else{enhance();}
  new MutationObserver(enhance).observe(document.body,{childList:true,subtree:true});
})();
</script>
