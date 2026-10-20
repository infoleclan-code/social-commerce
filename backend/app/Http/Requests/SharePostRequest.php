<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ShareChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SharePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['channel' => ['sometimes', Rule::enum(ShareChannel::class)]];
    }

    public function channel(): ShareChannel
    {
        return ShareChannel::from((string) $this->validated('channel', ShareChannel::CopyLink->value));
    }
}
