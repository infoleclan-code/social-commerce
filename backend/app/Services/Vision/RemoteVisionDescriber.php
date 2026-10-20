<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Services\Media\ColorPalette;
use Illuminate\Support\Facades\Http;

/**
 * Sends the scan image to a vision model and keeps only the product attributes it returns.
 *
 * The bytes have already passed ImageGuard (type, size, pixel budget) when this runs. The model's
 * answer is untrusted text (a photo can carry instructions): it is reduced to a known category, known
 * color names, and short plain strings, and any failure or timeout falls back to local colors.
 */
final class RemoteVisionDescriber implements VisionDescriber
{
    private const MAX_BRAND = 40;

    private const MAX_FEATURE = 60;

    private const PROMPT = <<<'TXT'
Describe only the product in this photo. Reply with JSON and nothing else:
{"brand":"Nike","category":"Footwear/Slides","main_color":"Grey","pattern_details":"Orange bubble sole"}
category must be one of Footwear/Slides, Footwear/Sneakers, Footwear/Running, Apparel/Jacket, Apparel/Coat, Apparel/Scarf, or Other.
brand is a logo you can see, otherwise null. main_color is the dominant color. pattern_details is the distinctive physical pattern, such as an orange bubble sole. Do not invent a brand, color, or pattern that is not visible.
TXT;

    public function __construct(
        private readonly string $provider,
        private readonly string $key,
        private readonly string $model,
        private readonly int $timeoutSeconds = 8,
        private readonly int $connectTimeoutSeconds = 3,
    ) {}

    public function describe(string $bytes): array
    {
        $local = (new LocalVisionDescriber)->describe($bytes);
        try {
            $parsed = $this->provider === 'anthropic' ? $this->anthropic($bytes) : $this->openai($bytes);
        } catch (\Throwable) {
            return $local;
        }
        if ($parsed === null) {
            return $local;
        }

        $normalized = self::normalize($parsed, $local);
        $normalized['source'] = $this->provider;

        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function openai(string $bytes): ?array
    {
        $response = Http::withToken($this->key)->connectTimeout($this->connectTimeoutSeconds)->timeout($this->timeoutSeconds)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'response_format' => ['type' => 'json_object'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::PROMPT],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:'.self::mediaType($bytes).';base64,'.base64_encode($bytes)]],
                ],
            ]],
        ]);
        if (! $response->successful()) {
            return null;
        }
        $content = $response->json('choices.0.message.content');

        return is_string($content) ? self::decode($content) : null;
    }

    /** @return array<string, mixed>|null */
    private function anthropic(string $bytes): ?array
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->key,
            'anthropic-version' => '2023-06-01',
        ])->connectTimeout($this->connectTimeoutSeconds)->timeout($this->timeoutSeconds)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => 400,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => self::mediaType($bytes), 'data' => base64_encode($bytes)]],
                    ['type' => 'text', 'text' => self::PROMPT],
                ],
            ]],
        ]);
        if (! $response->successful()) {
            return null;
        }
        $content = $response->json('content.0.text');

        return is_string($content) ? self::decode($content) : null;
    }

    /** The real type of the (already validated) bytes: providers refuse a PNG labelled as JPEG. */
    private static function mediaType(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** Plain, short, lower-case text from an untrusted model answer, or null. */
    private static function plain(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/[^\p{L}\p{N} &\'.\-]+/u', ' ', $value));
        $clean = (string) preg_replace('/\s+/u', ' ', $clean);
        if ($clean === '' || mb_strlen($clean) > $max) {
            return null;
        }

        return mb_strtolower($clean);
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $content): ?array
    {
        $content = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($content)) ?? $content);
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array{category: ?string, colors: list<string>, brand: ?string, features: list<string>, confidence: float, source: string}  $local
     * @return array{category: ?string, colors: list<string>, brand: ?string, features: list<string>, confidence: float, source: string}
     */
    public static function normalize(array $parsed, array $local): array
    {
        $colors = ColorPalette::parse($parsed['colors'] ?? []);
        $main = is_string($parsed['main_color'] ?? null) ? strtolower($parsed['main_color']) : null;
        if ($main !== null && in_array($main, ColorPalette::NAMES, true) && ! in_array($main, $colors, true)) {
            array_unshift($colors, $main);
        }
        $pattern = self::plain($parsed['pattern_details'] ?? null, self::MAX_FEATURE) ?? '';
        foreach (ColorPalette::NAMES as $name) {
            if ($pattern !== '' && str_contains($pattern, $name) && ! in_array($name, $colors, true)) {
                $colors[] = $name;
            }
        }
        $features = [];
        if ($pattern !== '') {
            $features[] = $pattern;
        }
        foreach (is_array($parsed['features'] ?? null) ? $parsed['features'] : [] as $feature) {
            $feature = self::plain($feature, self::MAX_FEATURE);
            if ($feature !== null) {
                $features[] = $feature;
            }
        }
        $brand = self::plain($parsed['brand'] ?? null, self::MAX_BRAND);
        if ($brand === 'null' || $brand === 'none' || $brand === 'unknown') {
            $brand = null;
        }

        return [
            'category' => self::category(is_string($parsed['category'] ?? null) ? $parsed['category'] : null),
            'colors' => $colors !== [] ? $colors : $local['colors'],
            'brand' => $brand,
            'features' => array_slice($features, 0, 6),
            'confidence' => is_numeric($parsed['confidence'] ?? null) ? max(0.0, min(1.0, (float) $parsed['confidence'])) : 0.9,
            'source' => $local['source'] === 'local' ? 'vision' : $local['source'],
        ];
    }

    public static function category(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $key = strtolower(str_replace([' ', '_', '/'], ['', '', '.'], $raw));
        $key = str_replace('sliders', 'slides', $key);
        $known = ['footwear.slides', 'footwear.running', 'footwear.sneakers', 'apparel.jacket', 'apparel.coat', 'apparel.scarf'];
        if (in_array($key, $known, true)) {
            return $key;
        }
        if (str_contains($key, 'slide')) {
            return 'footwear.slides';
        }
        if (str_contains($key, 'sneaker')) {
            return 'footwear.sneakers';
        }
        if (str_contains($key, 'running') || str_contains($key, 'runner')) {
            return 'footwear.running';
        }
        if (str_contains($key, 'jacket')) {
            return 'apparel.jacket';
        }
        if (str_contains($key, 'coat')) {
            return 'apparel.coat';
        }
        if (str_contains($key, 'scarf')) {
            return 'apparel.scarf';
        }

        return null;
    }
}
