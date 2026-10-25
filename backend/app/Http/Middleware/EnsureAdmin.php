<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin guard. The token must be the admin ability issued after the one-time code.
 * A normal sign-in token, including one for an admin user, is not enough.
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;
        if (! $user instanceof User || $user->role !== UserRole::Admin || $token === null || ! $token->can('admin')) {
            throw ApiException::adminRequired();
        }

        return $next($request);
    }
}
