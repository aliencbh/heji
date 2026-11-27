<div class="translate-box" data-testid="translate-box">
  <span class="translate-icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
  </span>
  <a href="{{ route('locale.switch', 'zh-Hans') }}" class="translate-link {{ app()->getLocale() === 'zh-Hans' ? 'is-active' : '' }}">{{ __(''messages.chinese') }}</a>
  <span class="translate-sep">|</span>
  <a href="{{ route('locale.switch', 'en') }}" class="translate-link {{ app()->getLocale() === 'en' ? 'is-active' : '' }}">English</a>
</div>
<style>
.translate-box{display:flex;align-items:center;gap:8px;margin-top:16px;padding:10px 12px;border-top:1px solid rgba(0,0,0,.08)}
.translate-icon{display:inline-flex;color:inherit}
.translate-link{font-size:14px;text-decoration:none;opacity:.7}
.translate-link.is-active{font-weight:700;opacity:1;text-decoration:underline}
.translate-sep{opacity:.4}
</style>
