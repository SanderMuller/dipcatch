<?php declare(strict_types=1);

namespace App\Services\Scraper;

use App\Enums\ScrapeStatus;
use Spatie\LaravelData\Data;

final class ScrapeResult extends Data
{
    public function __construct(
        public ScrapeStatus $status,
        public ?string $rawPrice = null,
        public ?string $price = null,
        public ?string $currency = null,
        public ?string $title = null,
        public ?string $imageUrl = null,
        public ?string $error = null,
    ) {}

    public static function ok(?string $rawPrice, ?string $price, ?string $currency, ?string $title, ?string $imageUrl): self
    {
        return new self(ScrapeStatus::Ok, $rawPrice, $price, $currency, $title, $imageUrl);
    }

    public static function failure(ScrapeStatus $status, string $error): self
    {
        return new self($status, error: $error);
    }
}
