<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Launch builds answer 404 for in-app purchasing so those routes are not callable. */
final class EnsurePurchasesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('commerce.purchases_enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
