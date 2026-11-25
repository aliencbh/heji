<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Support\Facades\Gate;
class CatalogAccess {
    public function handle($request,Closure $next) {
        $resource=$request->route('resource');
        $key=$resource==='products'?'catalog':$resource;
        Gate::authorize('page',$key);
        $action=match($request->route()->getActionMethod()) {
            'save','form'=>$request->route('id')?'edit':'create',
            'delete'=>'delete',
            default=>$request->boolean('csv')?'export':null,
        };
        if ($action) Gate::authorize('page',$key.'.'.$action);
        return $next($request);
    }
}