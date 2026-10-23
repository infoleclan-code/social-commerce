<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API is token-based (Sanctum bearer tokens from the mobile app); no session/CSRF on /api.
        // There is no web login route. A guest hitting /api must get 401, not a redirect that 500s.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') || $request->expectsJson() ? null : '/');
        $middleware->alias(['idempotent' => EnforceIdempotency::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // App\Exceptions\ApiException renders itself ({message, code, errors?}).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Never report validation-level refusals as errors.
        $exceptions->dontReport([ApiException::class]);
    })->create();
