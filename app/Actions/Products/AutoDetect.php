<?php declare(strict_types=1);

namespace App\Actions\Products;

use App\Services\Scraper\FetchHtml;
use App\Services\Scraper\MetadataExtractor;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final readonly class AutoDetect
{
    public function __construct(
        private FetchHtml $fetcher,
        private MetadataExtractor $metadata,
    ) {}

    public function __invoke(string $url): AutoDetectResult
    {
        $fetch = $this->fetcher->fetch($url);
        if (! $fetch->ok || $fetch->crawler === null) {
            return AutoDetectResult::failure($fetch->error ?? 'Could not fetch URL.');
        }

        return new AutoDetectResult(
            selectors: $this->collectSelectors($fetch->crawler),
            title: $this->metadata->title($fetch->crawler, $url, null),
            imageUrl: $this->metadata->image($fetch->crawler, $url, null),
        );
    }

    /**
     * Heuristic ranking of price-selector candidates, best-first.
     *
     * @return list<string>
     */
    private function collectSelectors(Crawler $crawler): array
    {
        $candidates = [];

        // 1. Microdata — Schema.org `itemprop="price"`.
        if ($this->matches($crawler, '[itemprop="price"]')) {
            $candidates[] = '[itemprop="price"]';
        }

        // 2. OpenGraph product price.
        if ($this->matches($crawler, 'meta[property="product:price:amount"]')) {
            $candidates[] = 'meta[property="product:price:amount"]';
        }

        // 3. Common class / data-attr patterns.
        foreach (['.product-price', '[data-price]', '.price'] as $selector) {
            if ($this->matches($crawler, $selector)) {
                $candidates[] = $selector;
            }
        }

        return array_values(array_unique($candidates));
    }

    private function matches(Crawler $crawler, string $selector): bool
    {
        try {
            return $crawler->filter($selector)->count() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
