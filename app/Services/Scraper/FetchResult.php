<?php declare(strict_types=1);

namespace App\Services\Scraper;

use Spatie\LaravelData\Data;
use Symfony\Component\DomCrawler\Crawler;

final class FetchResult extends Data
{
    public function __construct(
        public bool $ok,
        public ?Crawler $crawler = null,
        public ?string $body = null,
        public ?string $error = null,
    ) {}

    public static function okWith(Crawler $crawler, string $body): self
    {
        return new self(true, $crawler, $body);
    }

    public static function failure(string $error): self
    {
        return new self(false, error: $error);
    }
}
