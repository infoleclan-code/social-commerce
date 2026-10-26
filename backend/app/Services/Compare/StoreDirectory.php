<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Support\Facades\DB;

/** Store rating from the provider, plus an admin flag or block. */
final class StoreDirectory
{
    public function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return mb_substr($host, 0, 190);
    }

    public function blocked(string $host): bool
    {
        return DB::table('store_reputations')->where('host', $host)->where('status', 'blocked')->exists();
    }

    /**
     * @param  array{rating?: float|null, review_count?: int|null}  $observed
     * @return array{rating: float|null, review_count: int|null, trust: string, status: string}
     */
    public function describe(string $host, array $observed): array
    {
        $row = DB::table('store_reputations')->where('host', $host)->first();
        $rating = $observed['rating'] ?? null;
        $reviews = $observed['review_count'] ?? null;
        if ($row !== null) {
            if ($rating !== null || $reviews !== null) {
                DB::table('store_reputations')->where('host', $host)->update([
                    'rating' => $rating ?? $row->rating,
                    'review_count' => $reviews ?? $row->review_count,
                    'updated_at' => now(),
                ]);
            }
            $rating = $rating ?? ($row->rating !== null ? (float) $row->rating : null);
            $reviews = $reviews ?? ($row->review_count !== null ? (int) $row->review_count : null);
            $status = (string) $row->status;
        } elseif ($rating !== null || $reviews !== null) {
            DB::table('store_reputations')->insert([
                'host' => $host,
                'rating' => $rating,
                'review_count' => $reviews,
                'status' => 'open',
                'updated_at' => now(),
            ]);
            $status = 'open';
        } else {
            $status = 'open';
        }

        return [
            'rating' => $rating !== null ? round((float) $rating, 2) : null,
            'review_count' => $reviews !== null ? (int) $reviews : null,
            'trust' => $this->trust($status, $rating, $reviews),
            'status' => $status,
        ];
    }

    public function setStatus(string $host, string $status, ?string $note): void
    {
        DB::table('store_reputations')->updateOrInsert(
            ['host' => $host],
            ['status' => $status, 'note' => $note, 'updated_at' => now()],
        );
    }

    private function trust(string $status, mixed $rating, mixed $reviews): string
    {
        if ($status === 'blocked') {
            return 'blocked';
        }
        if ($status === 'flagged') {
            return 'caution';
        }
        if (is_numeric($rating) && (float) $rating >= 4 && (int) $reviews >= 100) {
            return 'trusted';
        }
        if (is_numeric($rating)) {
            return 'mixed';
        }

        return 'unknown';
    }
}
