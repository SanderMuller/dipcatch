<?php declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\Product;
use Spatie\LaravelData\Data;

final class ScrapeRequest extends Data
{
    /**
     * @param  list<string>  $fallbackSelectors
     */
    public function __construct(
        public string $url,
        public string $priceSelector,
        public array $fallbackSelectors = [],
        public ?string $imageSelector = null,
        public ?string $titleSelector = null,
        public ?string $preferredCurrency = null,
    ) {}

    public static function fromProduct(Product $product, ?string $userDefaultCurrency = null): self
    {
        return new self(
            url: $product->url,
            priceSelector: $product->price_selector,
            fallbackSelectors: array_values((array) $product->fallback_selectors),
            imageSelector: $product->image_selector,
            titleSelector: $product->title_selector,
            preferredCurrency: $product->currency ?? $userDefaultCurrency,
        );
    }
}
