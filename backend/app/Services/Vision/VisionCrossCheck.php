<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Services\Media\ColorPalette;

/** A catalog row is kept only when its category, brand, and colors agree with the scan. */
final class VisionCrossCheck
{
    public const CONFIDENT = 0.75;

    /**
     * @param  array{category: ?string, colors: list<string>, brand: ?string, features: list<string>, confidence: float, source: string}  $vision
     */
    public function accepts(array $vision, object $product): bool
    {
        $confident = (float) $vision['confidence'] >= self::CONFIDENT;
        $category = $this->text($product->vision_category ?? null);
        $brand = $this->text($product->vision_brand ?? null);
        if ($confident && $vision['category'] !== null && $category !== null && $vision['category'] !== $category) {
            return false;
        }
        if ($confident && $vision['brand'] !== null && $brand !== null && $vision['brand'] !== $brand) {
            return false;
        }
        $productColors = ColorPalette::parse($product->vision_colors ?? null);
        $scanColors = $vision['colors'];
        if ($productColors !== [] && $scanColors !== [] && array_intersect($productColors, $scanColors) === []) {
            return false;
        }
        if ($confident && $vision['category'] !== null && $category === null) {
            return false;
        }

        return true;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return strtolower($value);
    }
}
