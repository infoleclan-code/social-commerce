<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key for every request that moves money or reserves stock.
 *
 *  - The key is claimed atomically (INSERT .. ON CONFLICT DO NOTHING) per user, before the controller
 *    runs, so two concurrent requests with one key cannot both execute: the second gets 409
 *    REQUEST_IN_PROGRESS.
 *  - The request is fingerprinted (method, path, canonical body, uploaded file hashes). The same key
 *    with a different request is refused with 422 IDEMPOTENCY_KEY_REUSED.
 *  - A completed response (2xx and deterministic 4xx) is stored and replayed byte-for-byte with
 *    Idempotent-Replayed: true. 5xx, 408, 409, 423, 425 and 429 are not stored: the key is released and
 *    the client may retry with it.
 *  - A claim whose request died (process killed) expires after 60 seconds and can be taken over.
 *  - Client secrets are never stored: next_action is blanked in the stored copy; the app fetches a
 *    fresh one with POST /orders/{id}/sync.
 *
 * Services keep their own idempotency (orders.idempotency_key, ledger keys, Stripe keys); this layer
 * makes the HTTP contract exact and stops duplicate work before it starts.
 */
final class EnforceIdempotency
{
    private const LOCK_SECONDS = 60;

    private const NOT_STORED = [408, 409, 423, 425, 429];

    private const MAX_STORED_BYTES = 65_536;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }
        $key = (string) $request->header('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key) !== 1) {
            throw ApiException::idempotencyKeyRequired();
        }
        $userId = (string) $user->getAuthIdentifier();
        $route = mb_substr($request->method().' '.($request->route()?->uri() ?? $request->path()), 0, 120);
        $fingerprint = hash('sha256', implode("\n", [$userId, $request->method(), $request->path(), $this->canonicalBody($request)]));

        $claimed = DB::selectOne(
            "INSERT INTO idempotency_keys (user_id, key, route, fingerprint, status, locked_until, created_at)
             VALUES (?, ?, ?, ?, 'in_progress', clock_timestamp() + make_interval(secs => ?), clock_timestamp())
             ON CONFLICT (user_id, key) DO NOTHING
             RETURNING key",
            [$userId, $key, $route, $fingerprint, self::LOCK_SECONDS],
        );
        if ($claimed === null) {
            $replay = $this->existing($userId, $key, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
        }

        $response = $next($request);
        $status = $response->getStatusCode();
        if ($status >= 500 || in_array($status, self::NOT_STORED, true)) {
            DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->where('status', 'in_progress')->delete();

            return $response;
        }
        DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->update([
            'status' => 'completed',
            'response_status' => $status,
            'response_body' => $this->storable($response),
        ]);

        return $response;
    }

    /** A replay, or null when this request may proceed (it took over an expired claim). */
    private function existing(string $userId, string $key, string $fingerprint): ?Response
    {
        $row = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->first();
        if ($row === null) {
            throw ApiException::requestInProgress();
        }
        if (! hash_equals((string) $row->fingerprint, $fingerprint)) {
            throw ApiException::idempotencyKeyReused();
        }
        if ($row->status === 'completed') {
            $body = $row->response_body === null ? null : json_decode((string) $row->response_body, true);

            return new JsonResponse($body, (int) $row->response_status, ['Idempotent-Replayed' => 'true']);
        }
        $takenOver = DB::update(
            "UPDATE idempotency_keys SET locked_until = clock_timestamp() + make_interval(secs => ?)
              WHERE user_id = ? AND key = ? AND status = 'in_progress' AND locked_until < clock_timestamp()",
            [self::LOCK_SECONDS, $userId, $key],
        );
        if ($takenOver !== 1) {
            throw ApiException::requestInProgress();
        }

        return null;
    }

    private function canonicalBody(Request $request): string
    {
        // input() never contains uploaded files (they are hashed below); encoding them would fail and
        // make every multipart request look the same.
        $input = $request->isJson() ? (array) $request->json()->all() : (array) $request->input();
        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            foreach (is_array($file) ? $file : [$file] as $index => $one) {
                if ($one instanceof UploadedFile && $one->isValid()) {
                    $files[$name.'.'.$index] = (string) hash_file('sha256', (string) $one->getRealPath());
                }
            }
        }
        $canonical = ['input' => $this->sorted($input), 'files' => $this->sorted($files)];

        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->sorted($item), $value);
    }

    private function storable(Response $response): ?string
    {
        $content = (string) $response->getContent();
        if ($content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return null;
        }
        $scrubbed = $this->scrub($decoded);
        $encoded = (string) json_encode($scrubbed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strlen($encoded) > self::MAX_STORED_BYTES
            ? (string) json_encode(['message' => 'This request already completed.', 'code' => 'IDEMPOTENT_REPLAY'])
            : $encoded;
    }

    /** Client secrets (client_secret, ephemeral_key_secret, ...) and next actions are never persisted. */
    private function scrub(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($key === 'next_action' || (is_string($key) && str_ends_with(strtolower($key), 'secret'))) {
                $value[$key] = null;
            } elseif (is_array($item)) {
                $value[$key] = $this->scrub($item);
            }
        }

        return $value;
    }
}
