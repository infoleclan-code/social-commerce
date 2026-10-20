<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Services\Media\ImageFetcher;
use App\Services\Media\ImageFetchFailed;
use App\Services\Media\ImageGuard;
use App\Services\Media\VisualEmbedding;
use App\Services\Media\VisualSignature;
use App\Services\Search\VectorStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Indexes a listing's photo for visual search and checks it against every other listing.
 *
 * Product spoofing: a seller who lists someone else's product photo (the same file, or a resized /
 * recompressed / lightly edited copy) for a listing that does not declare the same catalogue product
 * (GTIN / canonical_key) is caught here. Offers of the same GTIN may share the maker's photo. The newer of the two listings is flagged
 * (listing_flags, kind duplicate_image) and moved to review: it leaves visual search (searchable is
 * derived from listing_status) and cannot be bought until a person clears it. A photo that is not a
 * real image (bomb, polyglot, wrong type) also sends the listing to review.
 */
final class CatalogImageIndexer
{
    public function __construct(
        private readonly ImageFetcher $fetcher,
        private readonly VectorStore $vectors,
        private readonly ProductGraphService $graph,
    ) {}

    /** @return 'indexed'|'duplicate'|'rejected'|'unreachable'|'missing' */
    public function index(string $productId): string
    {
        $product = DB::table('products')->where('id', $productId)->first(['id', 'image_url']);
        if ($product === null) {
            return 'missing';
        }
        try {
            $bytes = $this->fetcher->fetch((string) $product->image_url);
        } catch (ImageFetchFailed) {
            $this->flag($productId, null, 'image_unreachable', null, false);

            return 'unreachable';
        }

        return $this->indexBytes($productId, $bytes);
    }

    /** @return 'indexed'|'duplicate'|'rejected'|'missing' */
    public function indexBytes(string $productId, string $bytes): string
    {
        try {
            $signature = VisualEmbedding::fromImage(ImageGuard::decode($bytes));
        } catch (ApiException) {
            $this->flag($productId, null, 'image_rejected', null, true);

            return 'rejected';
        }

        return $this->indexSignature($productId, $signature);
    }

    /** @return 'indexed'|'duplicate'|'missing' */
    public function indexSignature(string $productId, VisualSignature $signature): string
    {
        return DB::transaction(function () use ($productId, $signature): string {
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first(['id', 'seller_id', 'canonical_key', 'created_at']);
            if ($product === null) {
                return 'missing';
            }
            DB::table('products')->where('id', $productId)->update(['image_sha256' => $signature->sha256]);
            $this->vectors->upsert($productId, $signature);
            $duplicates = $this->vectors->duplicates(
                $productId,
                $signature,
                $product->seller_id === null ? null : (string) $product->seller_id,
                (int) config('commerce.visual_search.duplicate_hamming', 6),
            );
            $outcome = 'indexed';
            foreach ($duplicates as $duplicate) {
                $other = DB::table('products')->where('id', $duplicate->product_id)->first(['id', 'canonical_key', 'created_at']);
                if ($other === null) {
                    continue;
                }
                // Two sellers offering the same catalogue product (same GTIN) may share the maker's photo.
                if ($product->canonical_key !== null && $product->canonical_key === $other->canonical_key) {
                    continue;
                }
                $thisIsNewer = (string) $product->created_at > (string) $other->created_at
                    || ((string) $product->created_at === (string) $other->created_at && $productId > (string) $other->id);
                [$suspect, $original] = $thisIsNewer ? [$productId, (string) $other->id] : [(string) $other->id, $productId];
                $this->flag($suspect, $original, 'duplicate_image', round(1 - $duplicate->distance / 64, 4), true);
                if ($suspect === $productId) {
                    $outcome = 'duplicate';
                }
            }

            return $outcome;
        });
    }

    private function flag(string $productId, ?string $relatedId, string $kind, ?float $score, bool $review): void
    {
        DB::table('listing_flags')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'product_id' => $productId,
            'related_product_id' => $relatedId,
            'kind' => $kind,
            'score' => $score,
            'status' => 'open',
            'created_at' => now(),
        ]);
        if ($review && DB::table('products')->where('id', $productId)->where('listing_status', 'active')->update(['listing_status' => 'review']) === 1) {
            $this->graph->invalidate($productId);
        }
    }
}
