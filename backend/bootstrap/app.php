<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Middleware\AuthenticateFromCookie;
use App\Http\Middleware\EnforceIdempotency;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePurchasesEnabled;
use App\Http\Middleware\RejectBanned;
use App\Support\SentryReporter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        // The API is stateless. A database session store would persist IP and user agent, and
        // /up must answer even when that store is absent.
        $middleware->web(remove: [
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
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
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            if ($exception instanceof ApiException
                || $exception instanceof ValidationException
                || $exception instanceof AuthenticationException
                || $exception instanceof HttpResponseException) {
                return null;
            }
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            if ($status === 401 || $status === 422) {
                return null;
            }
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
            $code = match (true) {
                $status === 404 => 'NOT_FOUND',
                $status === 403 => 'FORBIDDEN',
                $status === 429 => 'RATE_LIMITED',
                $status >= 500 => 'SERVER_ERROR',
                default => 'ERROR',
            };
            $message = match (true) {
                $status === 404 => 'Not found.',
                $status >= 500 => 'Something went wrong.',
                default => 'Request failed.',
            };

            return new JsonResponse(['message' => $message, 'code' => $code], $status);
        });
        $exceptions->reportable(function (Throwable $exception): void {
            app(SentryReporter::class)->capture($exception);
        });
    })->create();
