<?php declare(strict_types=1);

namespace App\Services\Scraper;

use App\Support\Config as ScraperConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final readonly class FetchHtml
{
    private const int CACHE_TTL_SECONDS = 60;

    public function __construct(
        private HttpFactory $http,
    ) {}

    public function fetch(string $url): FetchResult
    {
        $cacheKey = 'fetch:html:' . hash('xxh128', $url);

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $this->parse($url, $cached);
        }

        try {
            $response = $this->http
                ->withUserAgent(ScraperConfig::string('scraper.user_agent', 'DipCatchBot/1.0'))
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->timeout(ScraperConfig::int('scraper.timeout', 15))
                ->get($url);
        } catch (ConnectionException $e) {
            return FetchResult::failure($e->getMessage());
        }

        if ($response->failed()) {
            return FetchResult::failure('HTTP ' . $response->status());
        }

        $body = $response->body();
        Cache::put($cacheKey, $body, self::CACHE_TTL_SECONDS);

        return $this->parse($url, $body);
    }

    private function parse(string $url, string $body): FetchResult
    {
        try {
            $crawler = new Crawler($body, $url);
        } catch (Throwable $e) {
            return FetchResult::failure($e->getMessage());
        }

        return FetchResult::okWith($crawler, $body);
    }
}
