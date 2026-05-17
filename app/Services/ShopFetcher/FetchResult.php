<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

final readonly class FetchResult
{
    public function __construct(
        public string $finalUrl,
        public string $host,
        public string $html,
        public int $statusCode,
    ) {}
}
