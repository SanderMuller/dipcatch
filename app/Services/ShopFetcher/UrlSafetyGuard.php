<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

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
 * Both default to false, and both are ignored outright when the app runs as
 * production — the environment decides, not the variable.
 */
final class UrlSafetyGuard
{
    /**
     * @throws InvalidArgumentException when the URL or any DNS-resolved IP
     *                                  falls into a forbidden range.
     */
    public function assertSafe(string $url): void
    {
        $this->safeAddresses($url);
    }

    /**
     * The addresses the host was checked at, for the request to connect to.
     * Empty when there is nothing to pin: an IP literal, or a development
     * toggle that skipped the check.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException as {@see self::assertSafe()}
     */
    public function safeAddresses(string $url): array
    {
        if (self::allowPrivateIps()) {
            return [];
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new InvalidArgumentException("Unparseable URL: {$url}");
        }

        $host = trim($parts['host'], '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertIpPublic($host, $url);

            return [];
        }

        $ips = $this->resolveCached($host);
        if ($ips === []) {
            if (self::allowUnresolved()) {
                return [];
            }
            throw new InvalidArgumentException("Cannot resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            $this->assertIpPublic($ip, $url);
        }

        return $ips;
    }

    /**
     * Guzzle middleware that checks every request — the first and each
     * redirect hop — and makes curl connect to the addresses just checked.
     *
     * Without the pin, curl resolves the host again on its own, and a DNS
     * answer that changes between the two lookups (rebinding) sends the
     * request to an internal address the check never saw.
     *
     * @return Closure(callable): Closure
     */
    public function middleware(): Closure
    {
        return fn (callable $handler): callable => function (RequestInterface $request, array $options) use ($handler) {
            $uri = $request->getUri();
            $ips = $this->safeAddresses((string) $uri);

            // Never through a proxy from the environment: the proxy would look
            // the host up itself, and the pin below would mean nothing.
            $options['proxy'] = ['no' => ['*']];

            if ($ips !== []) {
                $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
                $addresses = implode(',', array_map(static fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip, $ips));
                $options['curl'][CURLOPT_RESOLVE] = ["{$uri->getHost()}:{$port}:{$addresses}"];
            }

            return $handler($request, $options);
        };
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

        if ($ok === false || self::inExtraReservedRange($ip)) {
            throw new InvalidArgumentException("URL resolves to a non-public address ({$ip}): {$url}");
        }
    }

    /**
     * Ranges PHP's filter lets through that still lead inside a network:
     * carrier-grade NAT (a cloud's internal addresses often sit there), the
     * benchmark range, "this network", and NAT64 or IPv4-mapped forms of any
     * IPv4 address, which are checked as that IPv4 address.
     */
    private static function inExtraReservedRange(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        if (strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);

            // ::ffff:a.b.c.d and 64:ff9b::a.b.c.d carry an IPv4 address.
            if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === "\x00\x64\xff\x9b" . str_repeat("\0", 8)) {
                $v4 = (string) inet_ntop(substr($packed, 12));

                return filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                    || self::inExtraReservedRange($v4);
            }

            return false;
        }

        $long = (int) ip2long($ip);

        foreach ([['100.64.0.0', 10], ['198.18.0.0', 15], ['0.0.0.0', 8]] as [$network, $bits]) {
            $mask = -1 << (32 - $bits);

            if (($long & $mask) === ((int) ip2long($network) & $mask)) {
                return true;
            }
        }

        return false;
    }

    private static function allowUnresolved(): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        return (bool) config('dipcatch.fetcher.allow_unresolved', false);
    }

    private static function allowPrivateIps(): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        return (bool) config('dipcatch.fetcher.allow_private_ips', false);
    }
}
