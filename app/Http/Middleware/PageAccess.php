<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Support\Facades\Gate;
class PageAccess {
    public function handle($request,Closure $next,string $page) {
        Gate::authorize('page',$page);
        if ($request->boolean('csv')) Gate::authorize('page',$page.'.export');
        return $next($request);
    }
}