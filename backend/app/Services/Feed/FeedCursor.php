<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Opaque keyset cursors (base64url JSON). "For You" pages by (rank_score, id), "Following" by
 * (created_at, id); both strictly descending, so a page never repeats or skips rows that did not change.
 * The cursor only positions the reader inside data they may already see, so it is not signed.
 *
 * rank_score travels as the exact text PostgreSQL returned: a PHP float would be re-printed with
 * `precision` (14 digits) when bound, the comparison would land on the wrong side and pages would repeat.
 */
final readonly class FeedCursor
{
    private function __construct(
        public ?string $score,
        public ?CarbonImmutable $time,
        public string $id,
    ) {}

    public static function forRank(string $score, string $id): string
    {
        return self::encode(['v' => 1, 's' => $score, 'id' => $id]);
    }

    public static function forTime(CarbonImmutable $time, string $id): string
    {
        return self::encode(['v' => 1, 't' => $time->utc()->format('Y-m-d\TH:i:s.uP'), 'id' => $id]);
    }

    public static function decode(string $cursor): self
    {
        try {
            $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ApiException::invalidCursor();
        }
        if (! is_array($data) || ($data['v'] ?? null) !== 1 || ! is_string($data['id'] ?? null)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $data['id']) !== 1) {
            throw ApiException::invalidCursor();
        }

        if (array_key_exists('s', $data)) {
            if (! is_string($data['s']) || preg_match('/^-?[0-9]+(\.[0-9]+)?(e[-+]?[0-9]+)?$/i', $data['s']) !== 1) {
                throw ApiException::invalidCursor();
            }

            return new self($data['s'], null, $data['id']);
        }

        if (is_string($data['t'] ?? null)) {
            try {
                return new self(null, CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $data['t']), $data['id']);
            } catch (Throwable) {
                throw ApiException::invalidCursor();
            }
        }

        throw ApiException::invalidCursor();
    }

    private static function encode(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), '+/', '-_'), '=');
    }
}
