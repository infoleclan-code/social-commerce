<?php

declare(strict_types=1);

namespace App\Services\Assistant;

/** Deterministic shopping notes so chat tests do not call a model. */
final class FakeLlmClient implements LlmClient
{
    public function complete(string $system, string $user): string
    {
        $casual = preg_match('/samba|casual|adidas/i', $user) === 1;
        $budget = null;
        if (preg_match('/\$(\d+(?:\.\d{1,2})?)/', $user, $match) === 1 || preg_match('/(\d+(?:\.\d{1,2})?)\s*dollars/i', $user, $match) === 1) {
            $budget = (int) round(((float) $match[1]) * 100);
        }

        return json_encode([
            'reply' => 'These match that request. The prices are the latest check, not a promise.',
            'products' => [[
                'query' => $casual ? 'adidas samba' : 'nike sneakers',
                'brand' => $casual ? 'Adidas' : 'Nike',
                'summary' => $casual
                    ? 'A leather court shoe people wear every day.'
                    : 'A cushioned court sneaker for daily wear.',
                'pros' => ['Widely stocked', 'Easy to compare across stores'],
                'cons' => ['Popular sizes sell out'],
                'max_minor' => $budget,
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
