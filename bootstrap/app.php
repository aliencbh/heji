<?php
use App\Http\Middleware\SiteContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['page'=>App\Http\Middleware\PageAccess::class]);
        $middleware->web(append: [SiteContext::class]);
        $middleware->redirectGuestsTo(fn () => '/login');
        $middleware->redirectUsersTo(fn () => '/admin');
    })->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {})->create();