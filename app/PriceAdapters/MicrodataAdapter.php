<?php declare(strict_types=1);

namespace App\PriceAdapters;

use Symfony\Component\DomCrawler\Crawler;

/**
 * schema.org microdata extraction via `itemprop` attributes.
 */
final readonly class MicrodataAdapter implements ShopAdapter
{
    public function key(): string
    {
        return 'microdata';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $crawler = self::crawler($html);
        $priceNode = $crawler->filter('[itemprop="price"]')->first();

        if ($priceNode->count() === 0) {
            return ExtractionResult::skip();
        }

        $price = self::readValue($priceNode);
        if ($price === null) {
            return ExtractionResult::failed('microdata_no_price');
        }

        $price = PriceNormalizer::fromMixed($price);
        if ($price === null) {
            return ExtractionResult::failed('microdata_invalid_price');
        }

        $scope = MicrodataScope::around($priceNode);

        $currency = $scope->read('priceCurrency', $crawler);
        if ($currency === null) {
            return ExtractionResult::failed('microdata_no_currency');
        }

        $title = $scope->read('name', $crawler) ?? 'Unknown';
        $image = $scope->read('image', $crawler);
        $availability = $scope->read('availability', $crawler);

        return ExtractionResult::success(new ShopSnapshot(
            title: $title,
            imageUrl: $image,
            price: $price,
            currency: strtoupper($currency),
            inStock: self::availabilityInStock($availability),
            gtin: $scope->gtin(),
            gtinAuthoritative: true,
            raw: ['source' => 'microdata'],
        ));
    }

    private static function crawler(string $html): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return $crawler;
    }

    private static function readValue(Crawler $node): ?string
    {
        // Prefer the `content` attribute (microdata convention), fall back to text.
        $content = $node->attr('content');
        if (is_string($content) && $content !== '') {
            return $content;
        }

        $text = trim($node->text(''));

        return $text === '' ? null : $text;
    }

    private static function availabilityInStock(?string $availability): bool
    {
        if (! is_string($availability)) {
            return true;
        }

        $availability = strtolower($availability);

        if (str_contains($availability, 'outofstock') || str_contains($availability, 'soldout') || str_contains($availability, 'discontinued')) {
            return false;
        }

        return true;
    }
}
