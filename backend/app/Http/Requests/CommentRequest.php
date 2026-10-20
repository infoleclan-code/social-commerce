<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET  /api/v1/feed/{post}/comments?parent_id=&cursor=&limit=   (list)
 * POST /api/v1/feed/{post}/comments  { body, parent_id? }          (create)
 */
final class CommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [
                'parent_id' => ['sometimes', 'nullable', 'uuid'],
                'cursor' => ['sometimes', 'nullable', 'string', 'max:400', 'regex:/^[A-Za-z0-9_-]+$/'],
                'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            ];
        }

        return [
            'body' => ['required', 'string', 'max:'.(int) config('feed.comments.max_length'), 'regex:/\S/u'],
            'parent_id' => ['nullable', 'uuid'],
        ];
    }

    public function parentId(): ?string
    {
        $parent = $this->validated('parent_id');

        return is_string($parent) && $parent !== '' ? $parent : null;
    }
}
