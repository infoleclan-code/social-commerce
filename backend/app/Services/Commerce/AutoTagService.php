<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Services\Media\ImageGuard;
use App\Services\Media\VisualEmbedding;
use App\Services\Search\VectorStore;
use Illuminate\Support\Facades\DB;

/**
 * Matches a video frame to the catalog and attaches a product only when the match is unambiguous.
 *
 *  - the frame passes ImageGuard (type, size, pixel budget) before it is decoded;
 *  - a candidate's score is min(luma, structure): both independent views must agree;
 *  - only searchable listings are candidates: active, from a verified seller, not under review (a
 *    listing that copied another seller's photo is in review, so it cannot hijack a creator's reel);
 *  - the best match attaches only if it clears attach_at AND beats the runner-up by attach_margin;
 *    otherwise everything above suggest_at stays a suggestion for the creator to confirm.
 * There is no fallback to an unrelated in-stock product.
 */
final class AutoTagService
{
    public function __construct(private readonly VectorStore $vectors) {}

    /** @return list<array{product_id: string, title: string, score: float, status: string}> */
    public function match(string $postId, string $jpegBytes): array
    {
        $signature = VisualEmbedding::fromImage(ImageGuard::decode($jpegBytes));
        $attachAt = (float) config('commerce.auto_tag.attach_at', 0.85);
        $suggestAt = (float) config('commerce.auto_tag.suggest_at', 0.72);
        $margin = (float) config('commerce.auto_tag.attach_margin', 0.03);

        $scored = [];
        foreach ($this->vectors->nearest($signature, 10) as $candidate) {
            $score = round(max(0.0, min(1.0, min($candidate->luma_score, $candidate->structure_score))), 4);
            if ($score >= $suggestAt) {
                $scored[] = ['product_id' => $candidate->product_id, 'score' => $score];
            }
        }
        usort($scored, fn (array $a, array $b): int => [$b['score'], $a['product_id']] <=> [$a['score'], $b['product_id']]);
        if ($scored === []) {
            return [];
        }
        $titles = DB::table('products')->whereIn('id', array_column($scored, 'product_id'))->pluck('title', 'id');
        $runnerUp = $scored[1]['score'] ?? 0.0;
        $attach = $scored[0]['score'] >= $attachAt && $scored[0]['score'] - $runnerUp >= $margin;

        $suggestions = [];
        foreach ($scored as $index => $row) {
            $status = $attach && $index === 0 ? 'attached' : 'suggested';
            DB::table('product_tag_suggestions')->upsert([[
                'post_id' => $postId,
                'product_id' => $row['product_id'],
                'score' => $row['score'],
                'status' => $status,
                'created_at' => now(),
            ]], ['post_id', 'product_id'], ['score', 'status']);
            if ($status === 'attached') {
                DB::table('reel_product_tags')->insertOrIgnore([
                    'post_id' => $postId,
                    'product_id' => $row['product_id'],
                    'source' => 'auto',
                    'created_at' => now(),
                ]);
            }
            $suggestions[] = [
                'product_id' => $row['product_id'],
                'title' => (string) ($titles[$row['product_id']] ?? ''),
                'score' => $row['score'],
                'status' => $status,
            ];
        }

        return $suggestions;
    }
}
