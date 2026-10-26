<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\RiskStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A banned account can still log out. Every other authenticated call is refused. */
final class RejectBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->risk_status === RiskStatus::Banned && ! $request->is('api/v1/auth/logout')) {
            throw ApiException::accountBanned();
        }

        return $next($request);
    }
}
