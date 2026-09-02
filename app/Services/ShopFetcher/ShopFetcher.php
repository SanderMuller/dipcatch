<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use App\Services\ShopFetcher\Exceptions\Blocked;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Services\ShopFetcher\Exceptions\NotServable;
use App\Services\ShopFetcher\Exceptions\RateLimitedByHost;
use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\Exceptions\TemporaryFailure;
use App\Support\Config as DipConfig;
use App\Support\UnservableShops;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Single fetch entry-point used by both the synchronous add-shop probe
 * (Phase 3) and the queued recheck job (Phase 4). Per spec §3:
 *
 *  - Honor robots.txt (fail-open on missing/error).
 *  - Detect CF/Akamai/PerimeterX challenge pages → Blocked.
 *  - 401 → Blocked; 429 → RateLimitedByHost; 5xx → TemporaryFailure.
 *  - Per-host rate limit enforced INSIDE the fetcher (probe path can't
 *    bypass), keyed on normalized host.
 *  - Body cap 2 MB; charset → UTF-8.
 */
final readonly class ShopFetcher
{
    // Cloudflare / Akamai blanket-block anything that admits to being a bot,
    // even when robots.txt would allow us. We still honor robots.txt, throttle
    // per host, and respect Retry-After — we just don't announce as a bot.
    // Override via DIPCATCH_FETCHER_USER_AGENT when a shop demands a real
    // bot UA in robots.txt.
    private const string DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';

    private const int DEFAULT_TIMEOUT = 10;

    private const int DEFAULT_BODY_CAP_BYTES = 2_000_000;

    private const int DEFAULT_RATE_LIMIT_PER_MINUTE = 12;

    /** @var list<string> Lowercased substrings that indicate WAF challenge pages. */
    private const array BLOCK_MARKERS = [
        'cf-mitigated',
        'just a moment',
        'access denied',
        'attention required! | cloudflare',
        'akamai reference',
        'perimeterx',
        'px-captcha',
        // Imperva/Incapsula serves a 200 with a tiny iframe shell. Without
        // this marker the generic adapter can read a number out of the
        // challenge page and store it as a price (hoogvliet.com, 2026-09-01).
        'incapsula incident id',
        '_incapsula_resource',
    ];

    public function __construct(
        private RobotsTxtPolicy $robots,
        private UrlSafetyGuard $safety,
    ) {}

    public function fetch(string $url): FetchResult
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new InvalidArgumentException("Invalid URL: '{$url}'.");
        }

        $scheme = strtolower($parsed['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException("Unsupported scheme '{$scheme}' in '{$url}'.");
        }

        $host = UrlNormalizer::normalizeHost($parsed['host']);
        $path = $parsed['path'] ?? '/';

        try {
            $this->safety->assertSafe($url);
        } catch (InvalidArgumentException) {
            throw new HttpError(0);
        }

        if (! $this->robots->isAllowed($host, $path, $scheme)) {
            throw new RobotsDisallowed("robots.txt disallows {$host}{$path}");
        }

        $this->throttle($host);

        $response = $this->sendRequest($url);

        $this->classify($response);

        $html = $this->prepareBody($response);

        // Follow redirects to the page the body actually came from — host
        // adapters key on the host of `finalUrl`, so without this a shop URL
        // that 30x'es to a different domain would be matched against the
        // pre-redirect host.
        $effective = $response->effectiveUri();
        $finalUrl = $effective !== null ? (string) $effective : $url;
        $finalHost = $host;
        $effectiveHost = parse_url($finalUrl, PHP_URL_HOST);
        if (is_string($effectiveHost) && $effectiveHost !== '') {
            $finalHost = UrlNormalizer::normalizeHost($effectiveHost);
        }

        // The host that served the body decides, not the one the user
        // pasted: coop.nl redirects every path to the plus.nl home page,
        // which would otherwise reach the adapter chain as if it were a
        // product page (verified 2026-09-02).
        $unservable = UnservableShops::reasonFor($finalHost);

        if ($unservable !== null) {
            throw new NotServable($finalHost, $unservable);
        }

        return new FetchResult(
            finalUrl: $finalUrl,
            host: $finalHost,
            html: $html,
            statusCode: $response->status(),
        );
    }

    /**
     * Canonical cache key for the per-host rate-limit bucket. Exposed so the
     * queue path (CheckShopPrice) and tests can target the same bucket.
     */
    public static function throttleKey(string $host): string
    {
        return "dipcatch:fetcher:host:{$host}";
    }

    private function throttle(string $host): void
    {
        $limit = DipConfig::int('dipcatch.fetcher.rate_limit_per_minute', self::DEFAULT_RATE_LIMIT_PER_MINUTE);
        $key = self::throttleKey($host);

        // Use attempt() so the check + hit happen as a single atomic operation
        // against the cache store. Without this, two workers can both pass
        // tooManyAttempts() before either hits the counter.
        $granted = RateLimiter::attempt(
            $key,
            $limit,
            static fn (): bool => true,
        );

        if ($granted === false) {
            $retryIn = RateLimiter::availableIn($key);
            throw new RateLimitedByHost($retryIn, RateLimitedByHost::SOURCE_LOCAL);
        }
    }

    private function sendRequest(string $url): Response
    {
        $ua = DipConfig::string('dipcatch.fetcher.user_agent', self::DEFAULT_USER_AGENT);
        $timeout = DipConfig::int('dipcatch.fetcher.timeout_seconds', self::DEFAULT_TIMEOUT);

        try {
            $safety = $this->safety;

            return Http::withHeaders([
                'User-Agent' => $ua,
                'Accept-Language' => 'en, *;q=0.5',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
                ->timeout($timeout)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => true,
                        // Validate redirect targets against the SSRF guard so a
                        // public URL can't bounce us to 127.0.0.1 or AWS metadata,
                        // and against the target's own robots.txt — the rules
                        // checked before the request belong to the host that was
                        // asked, not to the host the redirect points at.
                        'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($safety): void {
                            $safety->assertSafe((string) $uri);
                            $this->assertRobotsAllows((string) $uri);
                        },
                    ],
                ])
                ->get($url);
        } catch (ConnectionException) {
            throw new TemporaryFailure(599);
        } catch (FetchException $e) {
            // A redirect the callbacks refused: keep the reason, which the
            // caller turns into its own outcome.
            throw $e;
        } catch (Throwable) {
            throw new HttpError(0);
        }
    }

    /**
     * Guard one redirect hop against the target host's robots.txt, before
     * that request goes out.
     */
    private function assertRobotsAllows(string $url): void
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            return;
        }

        $host = UrlNormalizer::normalizeHost($parsed['host']);
        $path = $parsed['path'] ?? '/';
        $scheme = strtolower($parsed['scheme'] ?? 'https');

        if (! $this->robots->isAllowed($host, $path, $scheme)) {
            throw new RobotsDisallowed("robots.txt disallows {$host}{$path}");
        }
    }

    private function classify(Response $response): void
    {
        $status = $response->status();
        $body = $response->body();

        if ($status === 200) {
            if ($this->bodyIndicatesChallenge($body)) {
                throw new Blocked('challenge page on 200');
            }

            return;
        }

        if ($status === 401) {
            throw new Blocked('401 Unauthorized');
        }

        if ($status === 403) {
            if ($this->bodyIndicatesChallenge($body)) {
                throw new Blocked('challenge markers + 403');
            }
            throw new Blocked('403 Forbidden');
        }

        if ($status === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 60);
            throw new RateLimitedByHost($retryAfter);
        }

        if ($status >= 500 && $status <= 599) {
            throw new TemporaryFailure($status);
        }

        if ($status >= 400) {
            throw new HttpError($status);
        }
    }

    private function bodyIndicatesChallenge(string $body): bool
    {
        if ($body === '') {
            return false;
        }

        $head = strtolower(substr($body, 0, 4096));

        return array_any(self::BLOCK_MARKERS, fn (string $marker): bool => str_contains($head, $marker));
    }

    private function prepareBody(Response $response): string
    {
        $body = $response->body();
        $cap = DipConfig::int('dipcatch.fetcher.body_cap_bytes', self::DEFAULT_BODY_CAP_BYTES);

        if (strlen($body) > $cap) {
            // Truncating produced false `no_adapter_matched` failures and
            // sometimes partial snapshots from the first chunk. Fail the
            // fetch outright with a 413 — the caller surfaces it like any
            // other HTTP error.
            throw new HttpError(413);
        }

        // Charset handling: read Content-Type, convert to UTF-8 when needed.
        $contentType = (string) ($response->header('Content-Type') ?: '');
        $charset = $this->detectCharset($contentType, $body);

        if ($charset !== null && strcasecmp($charset, 'utf-8') !== 0 && strcasecmp($charset, 'utf8') !== 0) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if (is_string($converted)) {
                $body = $converted;
            }
        }

        // Strip invalid UTF-8 byte sequences via a round-trip convert; without
        // this the Crawler's libxml parser bails on the first bad byte.
        if (! mb_check_encoding($body, 'UTF-8')) {
            return mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }

        return $body;
    }

    private function detectCharset(string $contentType, string $body): ?string
    {
        if (preg_match('/charset=([\w-]+)/i', $contentType, $m)) {
            return $m[1];
        }

        // Try meta tag.
        if (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($body, 0, 4096), $m)) {
            return $m[1];
        }

        return null;
    }
}
