<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('locale', config('app.locale', 'zh-Hans'));
        if (! in_array($locale, ['zh-Hans', 'en'], true)) {
            $locale = 'zh-Hans';
        }
        app()->setLocale($locale);
        return $next($request);
    }
}
