<?php

declare(strict_types=1);

namespace App\Services\Compare;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * A photo becomes a product query, then the same top-3 compare flow.
 * The fake provider treats any real image as the demo Nike search.
 * SerpAPI uploads the image and reads the first Google Lens product title.
 */
final class VisualSearch
{
    public function __construct(
        private readonly CompareService $compare,
    ) {}

    /**
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string, country?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function search(string $bytes, string $mime, array $filters): array
    {
        if (! $this->image($bytes, $mime)) {
            throw ApiException::compareUnavailable();
        }
        $country = Region::country($filters['country'] ?? null) ?? 'US';
        $demo = (string) config('compare.provider') !== 'serpapi';
        try {
            $query = $demo ? 'nike sneakers' : $this->lens($bytes, $mime, $country);
        } catch (Throwable $exception) {
            report(new RuntimeException('Visual search failed: '.$exception::class));
            throw ApiException::compareUnavailable();
        }
        $result = $this->compare->search($query, $filters);
        $result['detected_query'] = $query;
        $result['photo_match'] = $demo ? 'demo' : 'lens';
        if ($demo) {
            $result['photo_note'] = 'Demo mode does not read the picture. It runs the Nike sneakers search so the flow can be tried.';
        }

        return $result;
    }

    private function image(string $bytes, string $mime): bool
    {
        if (strlen($bytes) < 16 || strlen($bytes) > 500_000) {
            return false;
        }
        $mime = strtolower($mime);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return false;
        }

        return str_starts_with($bytes, "\xFF\xD8\xFF")
            || str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A")
            || (str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP'));
    }

    private function lens(string $bytes, string $mime, string $country): string
    {
        $key = (string) config('compare.serpapi_key');
        if ($key === '') {
            throw new RuntimeException('SerpAPI key is not configured');
        }
        $extension = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $timeout = max(8, (int) config('compare.timeout_seconds', 12));
        $uploaded = Http::timeout($timeout)
            ->attach('image', $bytes, 'photo.'.$extension)
            ->post('https://serpapi.com/image', ['api_key' => $key]);
        $imageId = $uploaded->json('image_id');
        if (! $uploaded->successful() || ! is_string($imageId) || $imageId === '') {
            throw new RuntimeException('SerpAPI image upload failed');
        }
        $response = Http::timeout($timeout)->acceptJson()->get('https://serpapi.com/search.json', [
            'engine' => 'google_lens',
            'image_id' => $imageId,
            'api_key' => $key,
            'type' => 'products',
            'country' => Region::gl($country),
            'hl' => Region::hl($country),
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('SerpAPI lens request failed');
        }
        $title = $this->title($response->json());
        if ($title === null) {
            throw new RuntimeException('SerpAPI lens response had no product title');
        }

        return $title;
    }

    private function title(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }
        $matches = $json['visual_matches'] ?? [];
        if (is_array($matches)) {
            foreach ($matches as $match) {
                if (is_array($match) && is_string($match['title'] ?? null) && trim($match['title']) !== '') {
                    return mb_substr(trim($match['title']), 0, 120);
                }
            }
        }

        return null;
    }
}
