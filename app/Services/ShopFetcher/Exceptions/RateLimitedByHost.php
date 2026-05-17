<?php declare(strict_types=1);

namespace App\Services\ShopFetcher\Exceptions;

final class RateLimitedByHost extends FetchException
{
    public const string SOURCE_LOCAL = 'local';        // our own per-host throttle

    public const string SOURCE_UPSTREAM = 'upstream';  // shop returned HTTP 429

    public function __construct(
        public int $retryAfterSeconds = 5,
        public string $source = self::SOURCE_UPSTREAM,
    ) {
        parent::__construct(
            $source === self::SOURCE_LOCAL
                ? "Local per-host throttle hit, retry in {$retryAfterSeconds}s."
                : "Host rate-limited (HTTP 429), retry in {$retryAfterSeconds}s."
        );
    }

    public function code(): string
    {
        return 'rate_limited';
    }
}
