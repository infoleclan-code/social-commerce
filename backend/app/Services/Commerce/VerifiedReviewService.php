<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Media\Contracts\VideoProcessor;
use App\Support\MediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Reviews and owner answers exist only for a paid order of that product. */
final class VerifiedReviewService
{
    public function __construct(
        private readonly VideoProcessor $videos,
        private readonly MediaUrl $urls,
    ) {}

    /** @return array<string, mixed> */
    public function thread(User $viewer, string $productId): array
    {
        $reviewOrder = DB::table('orders as o')
            ->where('o.buyer_id', $viewer->id)
            ->where('o.product_id', $productId)
            ->where('o.status', 'paid')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('item_reviews as r')->whereColumn('r.order_id', 'o.id');
            })
            ->value('o.id');
        $owns = DB::table('orders')
            ->where('buyer_id', $viewer->id)
            ->where('product_id', $productId)
            ->where('status', 'paid')
            ->exists();

        return [
            'can_review_order_id' => $reviewOrder,
            'can_answer' => $owns,
            'reviews' => $this->reviews($productId),
            'questions' => $this->questions($productId),
        ];
    }

    /** @return array<string, mixed> */
    public function review(User $author, string $productId, string $orderId, string $body, ?UploadedFile $video): array
    {
        $paid = DB::table('orders')
            ->where('id', $orderId)
            ->where('buyer_id', $author->id)
            ->where('product_id', $productId)
            ->where('status', 'paid')
            ->exists();
        if (! $paid) {
            throw ApiException::reviewNotVerified();
        }
        $path = $video === null ? null : $this->storeClip($author, $video);
        DB::table('item_reviews')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $productId,
            'order_id' => $orderId,
            'author_id' => $author->id,
            'body' => $body,
            'video_path' => $path,
            'created_at' => now(),
        ]);

        return ['reviews' => $this->reviews($productId)];
    }

    /** @return array<string, mixed> */
    public function ask(User $asker, string $productId, string $body): array
    {
        if (! DB::table('products')->where('id', $productId)->exists()) {
            throw ApiException::postNotFound();
        }
        DB::table('item_questions')->insert([
            'id' => (string) Str::uuid7(),
            'product_id' => $productId,
            'asker_id' => $asker->id,
            'body' => $body,
            'created_at' => now(),
        ]);

        return ['questions' => $this->questions($productId)];
    }

    /** @return array<string, mixed> */
    public function answer(User $owner, string $questionId, string $body): array
    {
        $question = DB::table('item_questions')->where('id', $questionId)->first();
        if ($question === null) {
            throw ApiException::postNotFound();
        }
        $owns = DB::table('orders')
            ->where('buyer_id', $owner->id)
            ->where('product_id', $question->product_id)
            ->where('status', 'paid')
            ->exists();
        if (! $owns) {
            throw ApiException::answerNotVerified();
        }
        $exists = DB::table('item_answers')->where('question_id', $questionId)->exists();
        if (! $exists) {
            DB::table('item_answers')->insert([
                'id' => (string) Str::uuid7(),
                'question_id' => $questionId,
                'owner_id' => $owner->id,
                'body' => $body,
                'created_at' => now(),
            ]);
        }

        return ['questions' => $this->questions((string) $question->product_id)];
    }

    /** @return list<array<string, mixed>> */
    private function reviews(string $productId): array
    {
        return DB::table('item_reviews as r')
            ->join('users as u', 'u.id', '=', 'r.author_id')
            ->where('r.product_id', $productId)
            ->orderByDesc('r.created_at')
            ->get(['r.body', 'r.video_path', 'r.created_at', 'u.username', 'u.display_name'])
            ->map(fn (object $row): array => [
                'body' => $row->body,
                'video_url' => $row->video_path === null ? null : $this->urls->for((string) $row->video_path),
                'created_at' => (string) $row->created_at,
                'author' => [
                    'username' => $row->username,
                    'display_name' => $row->display_name,
                ],
                'verified_purchase' => true,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function questions(string $productId): array
    {
        $questions = DB::table('item_questions as q')
            ->join('users as u', 'u.id', '=', 'q.asker_id')
            ->where('q.product_id', $productId)
            ->orderBy('q.created_at')
            ->get(['q.id', 'q.body', 'q.created_at', 'u.username']);
        $answers = DB::table('item_answers as a')
            ->join('users as u', 'u.id', '=', 'a.owner_id')
            ->whereIn('a.question_id', $questions->pluck('id'))
            ->get(['a.question_id', 'a.body', 'u.username'])
            ->keyBy('question_id');

        return $questions->map(function (object $question) use ($answers): array {
            $answer = $answers->get($question->id);

            return [
                'id' => $question->id,
                'body' => $question->body,
                'asker' => $question->username,
                'created_at' => (string) $question->created_at,
                'answer' => $answer === null ? null : [
                    'body' => $answer->body,
                    'owner' => $answer->username,
                    'verified_purchase' => true,
                ],
            ];
        })->all();
    }

    private function storeClip(User $author, UploadedFile $file): string
    {
        $processed = $this->videos->process($file->getRealPath(), null);
        $key = 'reviews/'.$author->id.'/'.Str::uuid7().'.mp4';
        Storage::disk((string) config('feed.media_disk'))->put($key, (string) file_get_contents($processed->path), [
            'visibility' => 'public',
            'ContentType' => 'video/mp4',
        ]);
        if ($processed->path !== $file->getRealPath()) {
            @unlink($processed->path);
        }
        if ($processed->posterPath !== null) {
            @unlink($processed->posterPath);
        }

        return $key;
    }
}
