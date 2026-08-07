<?php

use App\Http\Middleware\RedirectIfNotSecure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            RedirectIfNotSecure::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Answer in the format the caller asked for. Restricting this to
        // api/* meant every XHR endpoint outside that prefix got an HTML
        // redirect for a validation failure however it framed the request:
        // the compose autosave posts to /drafts with Accept: application/json
        // and reads r.json(), so a rejected save threw a parse error in the
        // browser and the draft silently stopped saving (ZERO-112).
        //
        // expectsJson() is Laravel's own default and already leaves ordinary
        // browser form posts redirecting back with errors in the session.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
