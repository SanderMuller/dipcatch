<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Rejects URLs whose resolved IPs land in private / loopback / link-local
 * ranges. Used by `ShopFetcher` before issuing the outbound request, and
 * applied to every redirect target so a public URL can't bounce us to
 * `127.0.0.1` or `169.254.169.254` (AWS metadata).
 *
 * Two environment toggles for development / test environments:
 *  - `DIPCATCH_FETCHER_ALLOW_UNRESOLVED=true` — DNS misses fail-open (so the
 *    suite can use synthetic hostnames like `shop.example.com`).
 *  - `DIPCATCH_FETCHER_ALLOW_PRIVATE_IPS=true` — private/loopback IPs pass
 *    the check (so Herd's `.test` hosts resolving to 127.0.0.1 work locally).
 *
 * Both default to false. Never enable in production.
 */
final class UrlSafetyGuard
{
    /**
     * @throws InvalidArgumentException when the URL or any DNS-resolved IP
     *                                  falls into a forbidden range.
     */
    public function assertSafe(string $url): void
    {
        if (self::allowPrivateIps()) {
            return;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidArgumentException("Unparseable URL: {$url}");
        }

        $host = $parts['host'];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertIpPublic($host, $url);

            return;
        }

        $ips = $this->resolveCached($host);
        if ($ips === []) {
            if (self::allowUnresolved()) {
                return;
            }
            throw new InvalidArgumentException("Cannot resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            $this->assertIpPublic($ip, $url);
        }
    }

    /**
     * Cache DNS lookups for 5 minutes — the same host is resolved repeatedly
     * by the synchronous probe path plus every recheck job.
     *
     * @return list<string>
     */
    private function resolveCached(string $host): array
    {
        return Cache::remember("dipcatch:dns:{$host}", 300, static function () use ($host): array {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if ($records === false || $records === []) {
                return [];
            }

            $out = [];
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && $ip !== '') {
                    $out[] = $ip;
                }
            }

            return $out;
        });
    }

    private function assertIpPublic(string $ip, string $url): void
    {
        $ok = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($ok === false) {
            throw new InvalidArgumentException("URL resolves to a non-public address ({$ip}): {$url}");
        }
    }

    private static function allowUnresolved(): bool
    {
        return (bool) config('dipcatch.fetcher.allow_unresolved', false);
    }

    private static function allowPrivateIps(): bool
    {
        return (bool) config('dipcatch.fetcher.allow_private_ips', false);
    }
}
