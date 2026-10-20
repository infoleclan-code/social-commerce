<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Support\Currency;
use App\Support\SingleFlightCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product graph and multi-seller pricing, cached in Redis.
 *
 *  - offers(): every active listing of the same catalogue product (canonical_key, e.g. a GTIN) from
 *    verified sellers, with the landed total for the viewer's country, cheapest first. Key:
 *    offers:{canonical}:v{n}:{country}; fresh 60 s, served stale for another 60 s while one request
 *    refreshes; a seller price change bumps v{n} so the next read recomputes at once.
 *  - related(): same product from other sellers, products tagged in the same reels, and products
 *    bought by the same buyers (bounded samples), fresh 10 minutes.
 * These are display data. Checkout never reads them: it prices from the locked product row and refuses
 * a stale client total with 409 PRICE_CHANGED.
 */
final class ProductGraphService
{
    private const MAX_OFFERS = 20;

    public function __construct(private readonly SingleFlightCache $cache) {}

    /** @return array<string, mixed> */
    public function offers(string $productId, ?string $country): array
    {
        $product = $this->product($productId);
        $country = $country !== null && preg_match('/^[A-Za-z]{2}$/', $country) === 1 ? strtoupper($country) : 'XX';
        $namespace = $this->offersNamespace($product);
        $key = $namespace.':v'.$this->cache->version($namespace).':'.$country;
        $ttl = (int) config('commerce.cache.offers_seconds', 60);

        return $this->cache->remember($key, $ttl, $ttl, fn (): array => $this->computeOffers($product, $country === 'XX' ? null : $country));
    }

    /** @return array<string, mixed> */
    public function related(string $productId): array
    {
        $product = $this->product($productId);
        $namespace = 'product:'.$product->id;
        $key = 'graph:'.$product->id.':v'.$this->cache->version($namespace);
        $ttl = (int) config('commerce.cache.graph_seconds', 600);

        return $this->cache->remember($key, $ttl, $ttl, fn (): array => $this->computeRelated($product));
    }

    /** Call after a listing's price, stock policy, status or catalogue identity changed. */
    public function invalidate(string $productId): void
    {
        $product = DB::table('products')->where('id', $productId)->first(['id', 'canonical_key']);
        if ($product === null) {
            return;
        }
        $this->cache->bump('product:'.$product->id);
        $this->cache->bump($this->offersNamespace($product));
    }

    /** @return array<string, mixed> */
    private function computeOffers(object $product, ?string $country): array
    {
        $rows = DB::table('products as p')
            ->leftJoin('sellers as s', 's.user_id', '=', 'p.seller_id')
            ->where('p.listing_status', 'active')
            ->where(fn ($query) => $query->whereNull('p.seller_id')->orWhere('s.status', 'verified'))
            ->when(
                $product->canonical_key !== null,
                fn ($query) => $query->where('p.canonical_key', $product->canonical_key),
                fn ($query) => $query->where('p.id', $product->id),
            )
            ->orderBy('p.id')
            ->limit(200)
            ->get(['p.*']);
        $offers = $rows->map(function (object $row) use ($country): array {
            $landed = LandedCost::quote($row, $country);

            return [
                'product_id' => (string) $row->id,
                'seller_id' => $row->seller_id === null ? null : (string) $row->seller_id,
                'title' => (string) $row->title,
                'in_stock' => (int) $row->stock > 0,
                'price' => Currency::money((int) $row->price_minor, (string) $row->currency),
                'landed' => $landed,
            ];
        })->sort(function (array $a, array $b) use ($product): int {
            $base = (string) $product->currency;

            return [$a['landed']['total']['currency'] !== $base, ! $a['in_stock'], $a['landed']['total']['amount_minor'], $a['product_id']]
                <=> [$b['landed']['total']['currency'] !== $base, ! $b['in_stock'], $b['landed']['total']['amount_minor'], $b['product_id']];
        })->take(self::MAX_OFFERS)->values()->all();

        return [
            'product_id' => (string) $product->id,
            'canonical_key' => $product->canonical_key,
            'destination' => $country,
            'offers' => $offers,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function computeRelated(object $product): array
    {
        $sameProduct = $product->canonical_key === null ? [] : DB::table('products')
            ->where('canonical_key', $product->canonical_key)
            ->where('id', '<>', $product->id)
            ->where('listing_status', 'active')
            ->orderBy('price_minor')
            ->limit(10)
            ->pluck('id')
            ->all();
        $alsoTagged = DB::select(
            'SELECT t2.product_id, count(*) AS weight
               FROM reel_product_tags t1
               JOIN reel_product_tags t2 ON t2.post_id = t1.post_id AND t2.product_id <> t1.product_id
              WHERE t1.product_id = ?
              GROUP BY t2.product_id
              ORDER BY weight DESC, t2.product_id
              LIMIT 10',
            [$product->id],
        );
        $alsoBought = DB::select(
            "WITH buyers AS (
                 SELECT DISTINCT buyer_id FROM (
                     SELECT buyer_id FROM orders
                      WHERE product_id = ? AND status = 'paid' AND created_at > now() - interval '90 days'
                      ORDER BY created_at DESC
                      LIMIT 500) recent)
             SELECT o.product_id, count(DISTINCT o.buyer_id) AS weight
               FROM orders o
               JOIN buyers b ON b.buyer_id = o.buyer_id
              WHERE o.status = 'paid' AND o.product_id <> ? AND o.created_at > now() - interval '90 days'
              GROUP BY o.product_id
              ORDER BY weight DESC, o.product_id
              LIMIT 10",
            [$product->id, $product->id],
        );
        $ids = array_values(array_unique(array_merge(
            $sameProduct,
            array_map(fn (object $row): string => (string) $row->product_id, $alsoTagged),
            array_map(fn (object $row): string => (string) $row->product_id, $alsoBought),
        )));
        $cards = DB::table('products')->whereIn('id', $ids)->where('listing_status', 'active')->get(['id', 'title', 'image_url', 'price_minor', 'currency', 'stock'])->keyBy('id');
        $edge = fn (string $id, string $kind, int $weight): ?array => isset($cards[$id]) ? [
            'product_id' => $id,
            'kind' => $kind,
            'weight' => $weight,
            'title' => (string) $cards[$id]->title,
            'image_url' => (string) $cards[$id]->image_url,
            'price' => Currency::money((int) $cards[$id]->price_minor, (string) $cards[$id]->currency),
            'in_stock' => (int) $cards[$id]->stock > 0,
        ] : null;

        return [
            'product_id' => (string) $product->id,
            'edges' => array_values(array_filter(array_merge(
                array_map(fn (string $id): ?array => $edge($id, 'same_product', 1), $sameProduct),
                array_map(fn (object $row): ?array => $edge((string) $row->product_id, 'also_tagged', (int) $row->weight), $alsoTagged),
                array_map(fn (object $row): ?array => $edge((string) $row->product_id, 'also_bought', (int) $row->weight), $alsoBought),
            ))),
        ];
    }

    private function product(string $productId): object
    {
        $product = Str::isUuid($productId) ? DB::table('products')->where('id', $productId)->first(['id', 'canonical_key', 'currency']) : null;

        return $product ?? throw ApiException::productNotFound();
    }

    private function offersNamespace(object $product): string
    {
        return 'offers:'.($product->canonical_key ?? 'product:'.$product->id);
    }
}
