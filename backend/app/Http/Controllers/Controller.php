<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The request's Idempotency-Key (also enforced by the idempotent middleware on money routes). */
    protected function idempotencyKey(Request $request): string
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key) !== 1) {
            throw ApiException::idempotencyKeyRequired();
        }

        return $key;
    }
}
