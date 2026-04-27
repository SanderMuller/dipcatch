<?php declare(strict_types=1);

namespace App\Services\Scraper;

use App\Enums\ScrapeStatus;
use App\Support\Config as ScraperConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class HtmlScraper implements Scraper
{
    private const int MAX_BODY_BYTES = 5 * 1024 * 1024;

    private const int RETRY_TIMES = 3; // initial + 2 retries

    private const int RETRY_BASE_MS = 200;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly PriceParser $priceParser,
        private readonly CurrencyDetector $currencyDetector,
        private readonly RobotsGate $robotsGate,
        private readonly HostThrottle $hostThrottle,
        private readonly MetadataExtractor $metadata,
        private readonly JsonLdPriceExtractor $jsonLdPrices,
    ) {}

    public function scrape(ScrapeRequest $request): ScrapeResult
    {
        if (! $this->robotsGate->allows($request->url)) {
            return ScrapeResult::failure(ScrapeStatus::RobotsBlocked, 'Disallowed by robots.txt for our user-agent.');
        }

        $crawlDelay = $this->robotsGate->crawlDelaySeconds($request->url);
        $wait = $this->hostThrottle->shouldWaitSeconds($request->url, $crawlDelay);
        if ($wait > 0) {
            return ScrapeResult::failure(ScrapeStatus::Throttled, "Throttled — wait {$wait}s before retry.");
        }

        $lock = $this->hostThrottle->acquireLock($request->url);
        if ($lock === null) {
            return ScrapeResult::failure(ScrapeStatus::Throttled, 'Throttled — concurrent request to same host in flight.');
        }

        try {
            $this->hostThrottle->markStarted($request->url);

            return $this->fetchAndExtract($request);
        } finally {
            $lock->release();
        }
    }

    private function fetchAndExtract(ScrapeRequest $request): ScrapeResult
    {
        try {
            $response = $this->http
                ->withUserAgent(ScraperConfig::string('scraper.user_agent', 'DipCatchBot/1.0'))
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->timeout(ScraperConfig::int('scraper.timeout', 15))
                ->withOptions(['allow_redirects' => ['max' => ScraperConfig::int('scraper.max_redirects', 5)]])
                // Retry transient connection errors only (DNS / timeout / reset).
                // 4xx and 5xx are final → HttpError; the 24h scheduler retries on next tick.
                ->retry(
                    self::RETRY_TIMES,
                    fn (int $attempt): int => self::RETRY_BASE_MS * (2 ** ($attempt - 1)),
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                    throw: false,
                )
                ->get($request->url);
        } catch (ConnectionException $e) {
            return ScrapeResult::failure(ScrapeStatus::HttpError, $e->getMessage());
        }

        if ($response->failed()) {
            return ScrapeResult::failure(ScrapeStatus::HttpError, 'HTTP ' . $response->status());
        }

        $sizeError = $this->guardBodySize($response->header('Content-Length'), $response->body());
        if ($sizeError !== null) {
            return $sizeError;
        }

        try {
            $crawler = new Crawler($response->body(), $request->url);
        } catch (Throwable $e) {
            return ScrapeResult::failure(ScrapeStatus::ParseError, $e->getMessage());
        }

        $this->stripNoiseNodes($crawler);

        return $this->extract($request, $crawler);
    }

    private function guardBodySize(?string $announcedHeader, string $body): ?ScrapeResult
    {
        $announced = $announcedHeader === null ? 0 : (int) $announcedHeader;
        if ($announced > self::MAX_BODY_BYTES) {
            return ScrapeResult::failure(ScrapeStatus::HttpError, "Response too large: announced {$announced} bytes (cap " . self::MAX_BODY_BYTES . ').');
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return ScrapeResult::failure(ScrapeStatus::HttpError, 'Response body exceeds 5MB cap.');
        }

        return null;
    }

    private function extract(ScrapeRequest $request, Crawler $crawler): ScrapeResult
    {
        $rawPrice = $this->extractRawPrice($crawler, $request);

        if ($rawPrice === null) {
            return ScrapeResult::failure(ScrapeStatus::NeedsJs, 'Price selector returned no match.');
        }

        $price = $this->priceParser->parse($rawPrice);
        if ($price === null) {
            return ScrapeResult::failure(ScrapeStatus::ParseError, "Could not normalize price: '{$rawPrice}'.");
        }

        $currency = $this->currencyDetector->detect($rawPrice, $crawler, $request->preferredCurrency);

        return ScrapeResult::ok(
            rawPrice: $rawPrice,
            price: $price,
            currency: $currency,
            title: $this->metadata->title($crawler, $request->url, $request->titleSelector),
            imageUrl: $this->metadata->image($crawler, $request->url, $request->imageSelector),
        );
    }

    private function extractRawPrice(Crawler $crawler, ScrapeRequest $request): ?string
    {
        $selectors = [$request->priceSelector, ...$request->fallbackSelectors];

        foreach ($selectors as $selector) {
            $value = $this->metadata->firstMatchText($crawler, $selector);
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->jsonLdPrices->extract($crawler);
    }

    private function stripNoiseNodes(Crawler $crawler): void
    {
        foreach ($crawler->filter('style, script:not([type="application/ld+json"])') as $node) {
            $node->parentNode?->removeChild($node);
        }
    }
}
