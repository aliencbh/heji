<?php

use App\Http\Middleware\SiteContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['page' => App\Http\Middleware\PageAccess::class]);
		$middleware->web(append: [
		    SiteContext::class,
            \App\Http\Middleware\SetLocale::class
        ]);
        $middleware->redirectGuestsTo(fn () => '/login');
        $middleware->redirectUsersTo(fn () => '/admin');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (Throwable $e) {
            // Write to PHP error_log so docker logs can see it before ops downs the isolation stack
            error_log('[HEJI-EXCEPTION] '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            // First 5 frames are enough, avoid flooding with full trace
            $trace = $e->getTrace();
            for ($i = 0; $i < min(5, count($trace)); $i++) {
                $f = $trace[$i];
                error_log('[HEJI-TRACE] #'.$i.' '.($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? '').' '.($f['file'] ?? '').':'.($f['line'] ?? ''));
            }
        });
    })
    ->create();
