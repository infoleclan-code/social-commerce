<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web app keeps its Sanctum token in an HttpOnly cookie. A custom header is
 * required so a cross-site form post cannot spend that cookie. Native clients
 * send Authorization and never hit this path.
 */
final class AuthenticateFromCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = $request->cookies->get('auth_token');
        if (! $request->bearerToken() && is_string($cookie) && $cookie !== '' && $request->header('X-Requested-With') === 'XMLHttpRequest') {
            $request->headers->set('Authorization', 'Bearer '.$cookie);
        }

        return $next($request);
    }
}
