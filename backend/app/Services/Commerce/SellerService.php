<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Jobs\IndexProductImage;
use App\Models\User;
use App\Services\Payments\SellerPayouts;
use App\Support\Gtin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Seller registration, Stripe Connect status, and catalogue upserts. */
final class SellerService
{
    public function __construct(
        private readonly SellerPayouts $payouts,
        private readonly ProductGraphService $graph,
    ) {}

    /** @return array<string, mixed> */
    public function register(User $user, string $legalName, string $country, string $registrationNumber): array
    {
        $country = strtoupper($country);
        $existing = DB::table('sellers')->where('user_id', $user->id)->first();
        // One Express account per user even under double-submits: Stripe dedupes on the key.
        $accountId = $existing->stripe_account_id ?? $this->payouts->createExpressAccount((string) $user->email, $country, 'express-account:'.$user->id);
        if ($existing === null) {
            DB::table('sellers')->insert([
                'user_id' => $user->id,
                'legal_name' => $legalName,
                'country' => $country,
                'registration_number' => $registrationNumber,
                'status' => 'pending',
                'stripe_account_id' => $accountId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('sellers')->where('user_id', $user->id)->update([
                'legal_name' => $legalName,
                'country' => $country,
                'registration_number' => $registrationNumber,
                'stripe_account_id' => $accountId,
                'updated_at' => now(),
            ]);
        }

        return $this->profile($user) + ['onboarding_url' => $this->payouts->onboardingUrl($accountId)];
    }

    /** @return array<string, mixed> */
    public function refresh(User $user): array
    {
        $seller = $this->requireSeller($user);
        $status = $this->payouts->status((string) $seller->stripe_account_id);
        $verified = $status['charges_enabled'] && $status['details_submitted'];
        DB::table('sellers')->where('user_id', $user->id)->update([
            'charges_enabled' => $status['charges_enabled'],
            'payouts_enabled' => $status['payouts_enabled'],
            'status' => $verified ? 'verified' : 'pending',
            'updated_at' => now(),
        ]);

        return $this->profile($user);
    }

    /**
     * Upserts a verified seller's catalogue. Every photo is fetched and checked off the request path
     * (IndexProductImage): SSRF-safe download, image validation, visual-search indexing and duplicate
     * detection against other sellers' listings. A listing's currency never changes after creation
     * (price protection and offers compare like with like), and a GTIN links offers of the same
     * product across sellers (canonical_key).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{upserted: int, indexing: int}
     */
    public function sync(User $user, array $items): array
    {
        $seller = $this->requireSeller($user);
        if (! $seller->charges_enabled || $seller->status !== 'verified') {
            throw ApiException::accountPaused();
        }
        $count = 0;
        $toIndex = [];
        $touched = [];
        DB::transaction(function () use ($user, $seller, $items, &$count, &$toIndex, &$touched): void {
            foreach ($items as $item) {
                $sku = (string) $item['sku'];
                $existing = DB::table('products')->where('seller_id', $user->id)->where('sku', $sku)->lockForUpdate()->first();
                $price = (int) $item['price_minor'];
                $currency = strtoupper((string) ($item['currency'] ?? ($existing->currency ?? 'USD')));
                if ($existing !== null && $currency !== (string) $existing->currency) {
                    throw ApiException::currencyLocked();
                }
                if (isset($item['gtin']) && ! Gtin::valid((string) $item['gtin'])) {
                    throw ApiException::gtinInvalid();
                }
                $row = [
                    'title' => $item['title'],
                    'brand' => $item['brand'],
                    'image_url' => $item['image_url'],
                    'price_minor' => $price,
                    'squad_price_minor' => intdiv($price * 70, 100),
                    'stock' => (int) $item['stock'],
                    'ships_from' => strtoupper((string) ($item['ships_from'] ?? $seller->country)),
                    'shipping_minor' => (int) ($item['shipping_minor'] ?? 500),
                    'vat_bps' => (int) ($item['vat_bps'] ?? 2000),
                    'duty_bps' => (int) ($item['duty_bps'] ?? 400),
                    'handling_days' => (int) ($item['handling_days'] ?? 2),
                    'canonical_key' => isset($item['gtin']) ? 'gtin:'.$item['gtin'] : ($existing->canonical_key ?? null),
                    'updated_at' => now(),
                ];
                if ($existing === null) {
                    $id = (string) Str::uuid7();
                    DB::table('products')->insert($row + [
                        'id' => $id,
                        'seller_id' => $user->id,
                        'sku' => $sku,
                        'slug' => 'sku-'.strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $sku) ?? 'item').'-'.substr(str_replace('-', '', (string) Str::uuid7()), 0, 8),
                        'currency' => $currency,
                        'created_at' => now(),
                    ]);
                    $toIndex[] = $id;
                    $touched[] = $id;
                } else {
                    DB::table('products')->where('id', $existing->id)->update($row);
                    $touched[] = (string) $existing->id;
                    if ((string) $existing->image_url !== (string) $item['image_url']) {
                        $toIndex[] = (string) $existing->id;
                    }
                }
                $count++;
            }
        });
        foreach ($touched as $productId) {
            $this->graph->invalidate($productId);
        }
        foreach ($toIndex as $productId) {
            IndexProductImage::dispatch($productId)->afterCommit();
        }

        return ['upserted' => $count, 'indexing' => count($toIndex)];
    }

    /** @return array<string, mixed> */
    public function profile(User $user): array
    {
        $seller = DB::table('sellers')->where('user_id', $user->id)->first();
        if ($seller === null) {
            return ['registered' => false];
        }

        return [
            'registered' => true,
            'legal_name' => $seller->legal_name,
            'country' => $seller->country,
            'status' => $seller->status,
            'charges_enabled' => (bool) $seller->charges_enabled,
            'payouts_enabled' => (bool) $seller->payouts_enabled,
        ];
    }

    private function requireSeller(User $user): object
    {
        return DB::table('sellers')->where('user_id', $user->id)->first() ?? throw ApiException::profileNotFound();
    }
}
