<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Compare\CompareService;
use App\Services\Compare\FxRates;
use App\Services\Compare\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Saved searches. A price drop is emailed later by prices:check-alerts. */
final class WishlistController extends Controller
{
    public function __construct(
        private readonly CompareService $compare,
        private readonly FxRates $fx,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('wishlists')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (object $row): array => $this->present($row))
            ->all();

        return new JsonResponse(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:80'],
            'size' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'country' => ['sometimes', 'nullable', 'regex:/^[A-Za-z]{2}$/'],
            'target_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'notify' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $query = trim($data['query']);
        $brand = $this->blank($data['brand'] ?? null);
        $size = $this->blank($data['size'] ?? null);
        $currency = strtoupper($data['currency'] ?? ($user->shop_currency ?: 'USD'));
        if (! $this->fx->supports($currency)) {
            throw ValidationException::withMessages(['currency' => 'Choose a currency we have a rate for.']);
        }
        $country = Region::country($data['country'] ?? $user->shop_country) ?? 'US';
        $notify = array_key_exists('notify', $data) ? $request->boolean('notify') : true;
        $target = array_key_exists('target_minor', $data) && $data['target_minor'] !== null ? (int) $data['target_minor'] : null;
        $best = $this->best($query, $brand, $size, $currency, $country);
        $existing = $this->find($user->id, $query, $brand, $size, $currency);
        $now = now();

        if ($existing === null) {
            $id = (string) Str::uuid7();
            DB::table('wishlists')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'query' => $query,
                'brand' => $brand,
                'size' => $size,
                'currency' => $currency,
                'country' => $country,
                'target_minor' => $target,
                'best_total_minor' => $best,
                'notify' => $notify,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $id = (string) $existing->id;
            DB::table('wishlists')->where('id', $id)->update([
                'best_total_minor' => $best ?? $existing->best_total_minor,
                'country' => $country,
                'target_minor' => array_key_exists('target_minor', $data) ? $target : $existing->target_minor,
                'notify' => $notify,
                'updated_at' => $now,
            ]);
        }

        $row = DB::table('wishlists')->where('id', $id)->first();

        return new JsonResponse(['data' => $this->present($row)], $existing === null ? 201 : 200);
    }

    public function destroy(Request $request, string $item): JsonResponse
    {
        if (! Str::isUuid($item)) {
            throw ApiException::wishlistNotFound();
        }
        $deleted = DB::table('wishlists')->where('id', $item)->where('user_id', $request->user()->id)->delete();
        if ($deleted !== 1) {
            throw ApiException::wishlistNotFound();
        }

        return new JsonResponse(null, 204);
    }

    private function best(string $query, ?string $brand, ?string $size, string $currency, string $country): ?int
    {
        try {
            $result = $this->compare->search($query, [
                'brand' => $brand,
                'size' => $size,
                'min_minor' => null,
                'max_minor' => null,
                'in_stock' => true,
                'sort' => 'total_asc',
                'currency' => $currency,
                'country' => $country,
            ]);
        } catch (Throwable) {
            return null;
        }
        $total = $result['products'][0]['offers'][0]['total_minor'] ?? null;

        return is_int($total) ? $total : null;
    }

    private function find(string $userId, string $query, ?string $brand, ?string $size, string $currency): ?object
    {
        return DB::table('wishlists')
            ->where('user_id', $userId)
            ->whereRaw('lower(query) = lower(?)', [$query])
            ->whereRaw("coalesce(lower(brand), '') = ?", [strtolower((string) $brand)])
            ->whereRaw("coalesce(lower(size), '') = ?", [strtolower((string) $size)])
            ->where('currency', $currency)
            ->first();
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'query' => (string) $row->query,
            'brand' => $row->brand !== null ? (string) $row->brand : null,
            'size' => $row->size !== null ? (string) $row->size : null,
            'currency' => (string) $row->currency,
            'country' => $row->country !== null ? (string) $row->country : null,
            'target_minor' => $row->target_minor !== null ? (int) $row->target_minor : null,
            'best_total_minor' => $row->best_total_minor !== null ? (int) $row->best_total_minor : null,
            'notify' => (bool) $row->notify,
            'updated_at' => (string) $row->updated_at,
        ];
    }
}
