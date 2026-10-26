<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Compare\CouponCatalog;
use App\Services\Compare\Region;
use App\Services\Compare\StoreDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admin flags for stores and the coupon codes shown next to offers. */
final class CompareAdminController extends Controller
{
    public function stores(): JsonResponse
    {
        $rows = DB::table('store_reputations')
            ->orderBy('host')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => [
                'host' => (string) $row->host,
                'rating' => $row->rating !== null ? (float) $row->rating : null,
                'review_count' => $row->review_count !== null ? (int) $row->review_count : null,
                'status' => (string) $row->status,
                'note' => $row->note !== null ? (string) $row->note : null,
            ])
            ->all();

        return new JsonResponse(['data' => $rows]);
    }

    public function setStore(Request $request, StoreDirectory $stores): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190'],
            'status' => ['required', 'in:open,flagged,blocked'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $host = $this->host($data['host']);
        $stores->setStatus($host, $data['status'], $this->blank($data['note'] ?? null));

        return new JsonResponse(['data' => ['host' => $host, 'status' => $data['status']]]);
    }

    public function coupons(): JsonResponse
    {
        $rows = DB::table('store_coupons')
            ->where('active', true)
            ->orderBy('host')
            ->orderBy('code')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => $this->coupon($row))
            ->all();

        return new JsonResponse(['data' => $rows]);
    }

    public function storeCoupon(Request $request, CouponCatalog $coupons): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:190'],
            'code' => ['required', 'string', 'min:2', 'max:40'],
            'label' => ['required', 'string', 'min:1', 'max:120'],
            'country' => ['sometimes', 'nullable', 'regex:/^[A-Za-z]{2}$/'],
        ]);
        $country = isset($data['country']) ? Region::country($data['country']) : null;

        return new JsonResponse(['data' => $coupons->create(
            $this->host($data['host']),
            trim($data['code']),
            trim($data['label']),
            $country,
        )], 201);
    }

    public function destroyCoupon(string $coupon): JsonResponse
    {
        if (! Str::isUuid($coupon)) {
            throw new ApiException(404, 'COUPON_NOT_FOUND', 'That coupon was not found.');
        }
        $updated = DB::table('store_coupons')->where('id', $coupon)->where('active', true)->update(['active' => false]);
        if ($updated !== 1) {
            throw new ApiException(404, 'COUPON_NOT_FOUND', 'That coupon was not found.');
        }

        return new JsonResponse(null, 204);
    }

    private function host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if (preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $host) !== 1) {
            throw new ApiException(422, 'INVALID_HOST', 'Enter the store’s domain, such as finishline.com.');
        }

        return mb_substr($host, 0, 190);
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** @return array{id: string, host: string, code: string, label: string, country: string|null} */
    private function coupon(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'host' => (string) $row->host,
            'code' => (string) $row->code,
            'label' => (string) $row->label,
            'country' => $row->country !== null ? (string) $row->country : null,
        ];
    }
}
