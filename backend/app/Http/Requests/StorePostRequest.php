<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FeedAudience;
use App\Services\Feed\CreateMediaPost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/feed (multipart/form-data)
 *   media        required  image (jpeg/png/webp) or video (mp4/mov)
 *   thumbnail    optional  poster image for a video (extracted server-side when absent and ffmpeg runs)
 *   caption      optional  <= 2000 chars
 *   tags[]       optional  up to 10, "#Streetwear" -> "streetwear"
 *   audience     optional  friends (default, privacy by default) | public
 *   duration_ms  optional  client-measured duration (only used when the server cannot probe)
 */
final class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $tags = $this->input('tags');
        if (is_array($tags)) {
            $this->merge(['tags' => array_values(array_unique(array_map(
                static fn (mixed $tag): string => mb_strtolower(ltrim(trim((string) $tag), '#')),
                $tags,
            )))]);
        }
    }

    public function rules(): array
    {
        $imageTypes = (array) config('feed.image.mimetypes');
        $videoTypes = (array) config('feed.video.mimetypes');

        return [
            'media' => ['required', 'file', 'mimetypes:'.implode(',', [...$imageTypes, ...$videoTypes]), 'max:'.max((int) config('feed.video.max_kb'), (int) config('feed.image.max_kb'))],
            'thumbnail' => ['nullable', 'file', 'mimetypes:'.implode(',', $imageTypes), 'max:5120'],
            'caption' => ['nullable', 'string', 'max:'.(int) config('feed.caption_max')],
            'tags' => ['sometimes', 'array', 'max:'.(int) config('feed.max_tags')],
            'tags.*' => ['string', 'regex:/^[\pL\pN_]{1,40}$/u'],
            'audience' => ['sometimes', Rule::enum(FeedAudience::class)],
            'duration_ms' => ['nullable', 'integer', 'min:1', 'max:600000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('media');
                if ($file === null || $validator->errors()->has('media')) {
                    return;
                }
                $isImage = str_starts_with((string) $file->getMimeType(), 'image/');
                $limitKb = $isImage ? (int) config('feed.image.max_kb') : (int) config('feed.video.max_kb');
                if ($file->getSize() > $limitKb * 1024) {
                    $validator->errors()->add('media', 'The file is larger than '.intdiv($limitKb, 1024).' MB.');
                }
            },
        ];
    }

    public function toInput(): CreateMediaPost
    {
        return new CreateMediaPost(
            media: $this->file('media'),
            thumbnail: $this->file('thumbnail'),
            caption: $this->filled('caption') ? trim((string) $this->validated('caption')) : null,
            tags: array_values((array) $this->validated('tags', [])),
            audience: FeedAudience::from((string) $this->validated('audience', FeedAudience::Friends->value)),
            clientDurationMs: $this->validated('duration_ms') !== null ? (int) $this->validated('duration_ms') : null,
        );
    }
}
