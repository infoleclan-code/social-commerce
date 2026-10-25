<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Liveness for the database and the configured queue. The body never includes an exception. */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $this->queue();
        } catch (Throwable) {
            return new JsonResponse(['status' => 'down'], 500);
        }

        return new JsonResponse(['status' => 'up']);
    }

    private function queue(): void
    {
        $driver = (string) config('queue.default');
        if ($driver === 'sync') {
            return;
        }
        if ($driver === 'redis') {
            Redis::connection()->ping();

            return;
        }
        if ($driver === 'database') {
            DB::table('jobs')->limit(1)->get();
        }
    }
}
