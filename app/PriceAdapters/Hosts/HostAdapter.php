<?php declare(strict_types=1);

namespace App\PriceAdapters\Hosts;

use App\PriceAdapters\AdapterContext;
use App\PriceAdapters\ExtractionResult;
use App\PriceAdapters\HostSpecificAdapter;
use App\PriceAdapters\JsonLdAdapter;
use App\PriceAdapters\ShopAdapter;
use App\PriceAdapters\ShopSnapshot;
use App\Support\UrlNormalizer;

/**
 * Base for host-specific adapters. Subclass declares a host→currency map and a
 * CSS-fallback extractor; this class wires the common skeleton: skip on
 * unknown host, delegate to JSON-LD on the happy path, fall back to CSS, and
 * surface a host-specific failure code when both fail.
 */
abstract readonly class HostAdapter implements HostSpecificAdapter, ShopAdapter
{
    /**
     * Normalized host (no `www.`) → ISO 4217 currency code.
     *
     * @return array<string, string>
     */
    abstract protected function hosts(): array;

    /**
     * CSS-fallback extractor. Receives the matched host's currency so the
     * subclass doesn't need to redo the host lookup.
     */
    abstract protected function extractFromHtml(string $html, string $currency): ?ShopSnapshot;

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $host = self::hostFor($url);

        if ($host === null) {
            return ExtractionResult::skip();
        }

        $currency = $this->currencyForHost($host);

        if ($currency === null) {
            return ExtractionResult::skip();
        }

        $jsonLd = new JsonLdAdapter()->extract($url, $html);

        if ($jsonLd->isSuccess()) {
            return $jsonLd;
        }

        $snapshot = $this->extractFromHtml($html, $currency);
        if ($snapshot !== null) {
            return ExtractionResult::success($snapshot);
        }

        return ExtractionResult::failed($this->key() . '_extraction_failed');
    }

    private function currencyForHost(string $host): ?string
    {
        $hosts = $this->hosts();

        if (isset($hosts[$host])) {
            return $hosts[$host];
        }

        // Allow subdomains like smile.amazon.com to match amazon.com. Iterate
        // longest-suffix-first so a more-specific TLD wins when the map grows
        // to contain overlapping entries (e.g. `co.uk` vs `bbc.co.uk`).
        uksort($hosts, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($hosts as $candidate => $currency) {
            if (str_ends_with($host, '.' . $candidate)) {
                return $currency;
            }
        }

        return null;
    }

    private static function hostFor(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'])) {
            return null;
        }

        return UrlNormalizer::normalizeHost($parsed['host']);
    }
}
