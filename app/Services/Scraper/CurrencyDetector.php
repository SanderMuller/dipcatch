<?php declare(strict_types=1);

namespace App\Services\Scraper;

use JsonException;
use Symfony\Component\DomCrawler\Crawler;

final class CurrencyDetector
{
    /**
     * Three-letter ISO 4217 codes we recognize directly (uppercase).
     *
     * @var list<string>
     */
    private const array ISO_CODES = [
        'EUR', 'USD', 'GBP', 'JPY', 'CHF', 'AUD', 'CAD', 'NZD',
        'SEK', 'DKK', 'NOK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN',
        'TRY', 'CNY', 'HKD', 'SGD', 'KRW', 'TWD', 'INR', 'BRL',
        'MXN', 'ZAR', 'AED', 'SAR', 'ILS',
    ];

    /**
     * Symbol → ISO. Order matters where prefixes overlap (e.g. '$' must be
     * checked after `US$` / `CA$` / `A$` / `NZ$`).
     *
     * @var array<string, string>
     */
    private const array SYMBOLS = [
        'US$' => 'USD',
        'CA$' => 'CAD',
        'A$' => 'AUD',
        'NZ$' => 'NZD',
        'HK$' => 'HKD',
        'S$' => 'SGD',
        'NT$' => 'TWD',
        'R$' => 'BRL',
        'zł' => 'PLN',
        'Kč' => 'CZK',
        'Ft' => 'HUF',
        'lei' => 'RON',
        'лв' => 'BGN',
        '€' => 'EUR',
        '£' => 'GBP',
        '¥' => 'JPY',
        '₩' => 'KRW',
        '₪' => 'ILS',
        '₹' => 'INR',
        '₺' => 'TRY',
        '$' => 'USD',
    ];

    /**
     * Detect the currency from a raw price string with hint-driven preference.
     *
     * If multiple currencies appear and one matches `$preferred`, return that;
     * otherwise return the first occurrence; otherwise null.
     */
    public function detectFromString(string $raw, ?string $preferred = null): ?string
    {
        $found = $this->collectFromString($raw);

        return $this->pickPreferred($found, $preferred);
    }

    /**
     * Detect the currency from a fully-parsed page: ISO/symbol scan over the
     * given raw text first, then meta tags, then JSON-LD `Product.offers.priceCurrency`.
     */
    public function detect(string $raw, Crawler $crawler, ?string $preferred = null): ?string
    {
        $candidates = $this->collectFromString($raw);

        if (($pick = $this->pickPreferred($candidates, $preferred)) !== null) {
            return $pick;
        }

        $meta = $this->fromMeta($crawler);
        if ($meta !== null) {
            return $meta;
        }

        return $this->fromJsonLd($crawler);
    }

    /**
     * @return list<string>
     */
    private function collectFromString(string $raw): array
    {
        $found = [];

        if (preg_match_all('/\b([A-Z]{3})\b/', $raw, $isoMatches) > 0) {
            foreach ($isoMatches[1] as $code) {
                if (in_array($code, self::ISO_CODES, true)) {
                    $found[] = $code;
                }
            }
        }

        foreach (self::SYMBOLS as $symbol => $iso) {
            $offset = 0;
            while (($pos = strpos($raw, $symbol, $offset)) !== false) {
                $found[] = $iso;
                $offset = $pos + strlen($symbol);
            }
        }

        return $found;
    }

    /**
     * @param list<string> $found
     */
    private function pickPreferred(array $found, ?string $preferred): ?string
    {
        if ($found === []) {
            return null;
        }

        if ($preferred !== null && in_array(strtoupper($preferred), $found, true)) {
            return strtoupper($preferred);
        }

        return $found[0];
    }

    private function fromMeta(Crawler $crawler): ?string
    {
        $selectors = [
            'meta[itemprop="priceCurrency"]',
            'meta[property="product:price:currency"]',
            'meta[property="og:price:currency"]',
        ];

        foreach ($selectors as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                $value = strtoupper(trim($node->attr('content') ?? ''));
                if ($value !== '' && in_array($value, self::ISO_CODES, true)) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function fromJsonLd(Crawler $crawler): ?string
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $text = trim($node->textContent);
            if ($text === '') {
                continue;
            }

            try {
                $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $currency = $this->walkJsonLd($data);
            if ($currency !== null) {
                return $currency;
            }
        }

        return null;
    }

    private function walkJsonLd(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['priceCurrency']) && is_string($data['priceCurrency'])) {
            $code = strtoupper($data['priceCurrency']);
            if (in_array($code, self::ISO_CODES, true)) {
                return $code;
            }
        }

        foreach ($data as $value) {
            $nested = $this->walkJsonLd($value);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }
}
