<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteContext
{
    public function handle(Request $request, Closure $next)
    {
        $site = explode('.', $request->getHost())[0];
        abort_unless(isset(config('heji.sites')[$site]) && $request->getHost() === $site . '.laravel.demo', 404);
        $request->attributes->set('site', $site);

        // /health must not depend on DB / Redis, bypass DB entirely
        if ($request->is('health')) {
            return $next($request);
        }

        DB::selectOne("select set_config('heji.actor', ?, false)", [(string) ($request->user()?->id ?? '')]);
        try {
            return $next($request);
        } finally {
            try {
                DB::selectOne("select set_config('heji.actor','',false)");
            } catch (\Throwable $e) {
                // Do not mask the original exception with a cleanup DB failure
            }
        }
    }
}
