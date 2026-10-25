<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateFromCookie;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePurchasesEnabled;
use App\Http\Middleware\RejectBanned;
use App\Support\SentryReporter;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API is token-based (Sanctum bearer tokens from the mobile app); no session/CSRF on /api.
        // The web client sends the same token in an HttpOnly cookie. A guest hitting /api must get
        // 401, not a redirect that 500s.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') || $request->expectsJson() ? null : '/');
        $middleware->api(prepend: [
            AuthenticateFromCookie::class,
        ]);
        // Auth is in the priority list, so it would run before the purchases gate and answer 401.
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            EnsurePurchasesEnabled::class,
        );
        $middleware->alias([
            'idempotent' => EnforceIdempotency::class,
            'admin' => EnsureAdmin::class,
            'banned' => RejectBanned::class,
            'purchases' => EnsurePurchasesEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // App\Exceptions\ApiException renders itself ({message, code, errors?}).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Never report validation-level refusals as errors.
        $exceptions->dontReport([ApiException::class]);
        $exceptions->reportable(function (Throwable $exception): void {
            app(SentryReporter::class)->capture($exception);
        });
    })->create();
