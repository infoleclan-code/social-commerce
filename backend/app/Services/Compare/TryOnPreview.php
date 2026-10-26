<?php

declare(strict_types=1);

namespace App\Services\Compare;

/** Camera overlay for shoes and glasses. Other categories get a still preview. */
final class TryOnPreview
{
    /** @return array{category: string, supported: bool, mode: string, scope: string} */
    public function describe(string $category): array
    {
        $category = strtolower(trim($category));
        if (in_array($category, ['shoes', 'glasses'], true)) {
            return [
                'category' => $category,
                'supported' => true,
                'mode' => 'camera_overlay',
                'scope' => 'A guide drawn on your camera for shoes or glasses. It is not a body scan, and it is not a fit guarantee.',
            ];
        }

        return [
            'category' => $category === '' ? 'other' : mb_substr($category, 0, 40),
            'supported' => false,
            'mode' => 'image',
            'scope' => 'This category has a still preview only. Camera try-on is limited to shoes and glasses.',
        ];
    }
}
