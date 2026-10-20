<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Hashtag;
use Illuminate\Foundation\Http\FormRequest;

/** GET /api/v1/hashtags/posts?tag=&cursor=&limit= */
final class HashtagPostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'tag' => ['required', 'string', 'max:41'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:400', 'regex:/^[A-Za-z0-9_-]+$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('feed.max_page_size')],
        ];
    }

    public function tag(): ?string
    {
        return Hashtag::normalize((string) $this->validated('tag'));
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function limit(): int
    {
        return (int) $this->validated('limit', (int) config('feed.page_size'));
    }
}
