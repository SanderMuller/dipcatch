<?php declare(strict_types=1);

namespace App\Services\Scraper;

use App\Support\Config as ScraperConfig;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Spatie\Robots\RobotsTxt;
use Throwable;

class RobotsGate
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function allows(string $url): bool
    {
        return $this->robotsFor($url)?->allows($url, $this->userAgent()) ?? true;
    }

    /**
     * Crawl-Delay from robots.txt in seconds, or null when unset / unparseable.
     * Honored as-is (no upper cap) per spec.
     */
    public function crawlDelaySeconds(string $url): ?int
    {
        $value = $this->robotsFor($url)?->crawlDelay($this->userAgent());
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $seconds = (int) round((float) $value);

        return $seconds > 0 ? $seconds : null;
    }

    private function robotsFor(string $url): ?RobotsTxt
    {
        $robotsUrl = $this->robotsUrlFor($url);
        if ($robotsUrl === null) {
            return null;
        }

        $cacheKey = 'scrape:robots:' . parse_url($url, PHP_URL_HOST);

        $content = Cache::remember(
            $cacheKey,
            ScraperConfig::int('scraper.robots.cache_ttl_seconds', 3600),
            fn (): string => $this->fetchRobotsTxt($robotsUrl),
        );

        return new RobotsTxt($content);
    }

    private function robotsUrlFor(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . '/robots.txt';
    }

    private function fetchRobotsTxt(string $robotsUrl): string
    {
        try {
            $response = $this->http
                ->withUserAgent($this->userAgent())
                ->timeout(10)
                ->get($robotsUrl);
        } catch (Throwable) {
            return '';
        }

        return $response->successful() ? $response->body() : '';
    }

    private function userAgent(): string
    {
        return ScraperConfig::string('scraper.user_agent', 'DipCatchBot/1.0');
    }
}
