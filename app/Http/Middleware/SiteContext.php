<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class SiteContext {
    public function handle(Request $request, Closure $next) {
        $site = explode('.', $request->getHost())[0];
        abort_unless(isset(config('heji.sites')[$site]) && $request->getHost()===$site.'.laravel.demo',404);
        $request->attributes->set('site',$site);
        DB::selectOne("select set_config('heji.actor', ?, false)",[(string) ($request->user()?->id ?? '')]);
        try { return $next($request); }
        finally { DB::selectOne("select set_config('heji.actor','',false)"); }
    }
}