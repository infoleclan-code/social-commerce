<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Expo push targets. The token is stored, never logged, and revoked on logout or DELETE. */
final class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:ios,android,web'],
            'expo_push_token' => ['required', 'string', 'min:16', 'max:200', 'regex:/^[A-Za-z0-9_\-\[\]:]+$/'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:40'],
            'device_fingerprint_hash' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);
        $user = $request->user();
        $existing = DB::table('user_devices')->where('expo_push_token', $data['expo_push_token'])->first();
        if ($existing !== null && (string) $existing->user_id !== (string) $user->id) {
            throw ApiException::deviceTokenTaken();
        }
        if ($existing === null) {
            $id = (string) Str::uuid7();
            DB::table('user_devices')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'expo_push_token' => $data['expo_push_token'],
                'app_version' => $data['app_version'] ?? null,
                'device_fingerprint_hash' => $data['device_fingerprint_hash'] ?? null,
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $id = (string) $existing->id;
            DB::table('user_devices')->where('id', $id)->update([
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? $existing->app_version,
                'device_fingerprint_hash' => $data['device_fingerprint_hash'] ?? $existing->device_fingerprint_hash,
                'revoked_at' => null,
                'last_seen_at' => now(),
            ]);
        }

        return new JsonResponse(['data' => ['id' => $id, 'platform' => $data['platform']]], $existing === null ? 201 : 200);
    }

    public function destroy(Request $request, string $device): JsonResponse
    {
        $updated = Str::isUuid($device)
            ? DB::table('user_devices')->where('id', $device)->where('user_id', $request->user()->id)->whereNull('revoked_at')->update(['revoked_at' => now()])
            : 0;
        if ($updated !== 1) {
            abort(404);
        }

        return new JsonResponse(null, 204);
    }
}
