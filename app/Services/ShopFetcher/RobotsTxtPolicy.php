<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use App\Support\Config as DipConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * robots.txt policy per spec §3 "robots.txt policy":
 *
 *  - Fetched once per host, cached 24h.
 *  - 200 + parseable → honor disallow rules.
 *  - 404 / 403 / network error / unparseable → **fail-open** (allowed).
 *    Rationale: we're a low-volume bot identifying ourselves; treating
 *    missing robots.txt as "everything forbidden" would block half the
 *    long tail. All fail-open outcomes are logged for later audit.
 *  - 5xx on robots.txt → fail-open for this request, do not cache the failure.
 */
final readonly class RobotsTxtPolicy
{
    private const string DEFAULT_USER_AGENT = 'DipcatchBot';

    public function isAllowed(string $host, string $path, string $scheme = 'https'): bool
    {
        $cacheKey = "dipcatch:robots:{$host}";
        $ttl = DipConfig::int('dipcatch.fetcher.robots_cache_seconds', 86400);

        /** @var list<array{type: string, pattern: string}>|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === [] || $this->isPathAllowed($cached, $path);
        }

        [$rules, $cacheable] = $this->fetchAndParse($scheme . '://' . $host . '/robots.txt', $host);

        // 5xx (and other "don't cache this") outcomes skip the write so the
        // next request retries. Everything else (parsed rules, 4xx, network
        // errors) caches the resolved fail-open value for `$ttl` seconds.
        if ($cacheable) {
            Cache::put($cacheKey, $rules, $ttl);
        }

        return $rules === [] || $this->isPathAllowed($rules, $path);
    }

    /**
     * Fetch + parse robots.txt. Returns the resolved rule list plus a
     * `$cacheable` flag — false for transient 5xx so the next call retries.
     *
     * @return array{0: list<array{type: string, pattern: string}>, 1: bool}
     */
    private function fetchAndParse(string $url, string $host): array
    {
        $userAgent = DipConfig::string('dipcatch.fetcher.user_agent', self::DEFAULT_USER_AGENT);

        try {
            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(5)
                ->get($url);
        } catch (ConnectionException $e) {
            Log::info('robots.txt fetch failed; fail-open', [
                'host' => $host,
                'reason' => 'connection',
                'message' => $e->getMessage(),
            ]);

            return [[], true];
        } catch (Throwable $e) {
            Log::info('robots.txt fetch failed; fail-open', [
                'host' => $host,
                'reason' => 'exception',
                'message' => $e->getMessage(),
            ]);

            return [[], true];
        }

        $status = $response->status();

        if ($status >= 500) {
            Log::info('robots.txt 5xx; fail-open, will retry next cycle', [
                'host' => $host,
                'status' => $status,
            ]);

            return [[], false];
        }

        if ($status >= 400) {
            Log::info('robots.txt 4xx; fail-open', [
                'host' => $host,
                'status' => $status,
            ]);

            return [[], true];
        }

        $body = $response->body();
        if ($body === '') {
            return [[], true];
        }

        return [$this->parse($body), true];
    }

    /**
     * Minimal robots.txt parser: only rules in the section that targets our
     * UA (or `*`) are returned. We honor both Allow and Disallow.
     *
     * @return list<array{type: string, pattern: string}>
     */
    private function parse(string $body): array
    {
        $ourUa = DipConfig::string('dipcatch.fetcher.user_agent', self::DEFAULT_USER_AGENT);
        $ourUaLower = strtolower(self::nameFromUa($ourUa));

        $rules = [];
        $matchingSection = false;
        $sawSpecificMatch = false;
        $generalSection = [];
        $specificSection = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            $keyLower = strtolower($key);

            if ($keyLower === 'user-agent') {
                $uaLower = strtolower($value);
                if ($uaLower === '*') {
                    $matchingSection = true;
                    $rules = &$generalSection;
                } elseif ($uaLower === $ourUaLower || str_contains($ourUaLower, $uaLower)) {
                    $matchingSection = true;
                    $sawSpecificMatch = true;
                    $rules = &$specificSection;
                } else {
                    $matchingSection = false;
                }

                continue;
            }

            if (! $matchingSection) {
                continue;
            }

            if ($keyLower === 'disallow' || $keyLower === 'allow') {
                $rules[] = ['type' => $keyLower, 'pattern' => $value];
            }
        }

        return $sawSpecificMatch ? $specificSection : $generalSection;
    }

    /**
     * @param  list<array{type: string, pattern: string}>  $rules
     */
    private function isPathAllowed(array $rules, string $path): bool
    {
        $bestMatch = ['type' => 'allow', 'length' => -1];

        foreach ($rules as $rule) {
            $pattern = $rule['pattern'];
            if ($pattern === '') {
                // `Disallow:` with empty value = allow everything; ignore.
                continue;
            }

            if ($this->patternMatches($pattern, $path)) {
                $length = strlen($pattern);
                if ($length > $bestMatch['length']) {
                    $bestMatch = ['type' => $rule['type'], 'length' => $length];
                }
            }
        }

        return $bestMatch['type'] === 'allow';
    }

    private function patternMatches(string $pattern, string $path): bool
    {
        // robots.txt path matching: prefix match unless pattern ends with '$'.
        if (str_ends_with($pattern, '$')) {
            $pattern = substr($pattern, 0, -1);

            return $path === $pattern;
        }

        return str_starts_with($path, $pattern);
    }

    private static function nameFromUa(string $ua): string
    {
        // "DipcatchBot/1.0 (+https://…)" → "DipcatchBot"
        $first = explode('/', $ua, 2)[0] ?? $ua;

        return trim($first);
    }
}
