<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /api/v1/feed?mode=for_you|following&cursor=...&limit=10 */
final class FeedIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'string', 'in:for_you,following'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:400', 'regex:/^[A-Za-z0-9_-]+$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('feed.max_page_size')],
        ];
    }

    public function mode(): string
    {
        return (string) $this->validated('mode', 'for_you');
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
