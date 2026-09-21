<?php declare(strict_types=1);

namespace App\PriceAdapters;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Last-resort heuristic adapter. Looks for `.price`, `[data-price]`,
 * `[class*="price"]` text nodes containing a currency-prefixed number.
 *
 * Low confidence: this is the fallback when JSON-LD / microdata / OG all
 * skip. Skips when no plausible match found (so the chain can return
 * `no_adapter_matched` instead of polluting success metrics).
 */
final readonly class GenericAdapter implements ShopAdapter
{
    /** @var list<string> */
    private const array PRICE_SELECTORS = [
        '[itemprop="price"]',
        '[data-price]',
        '.product-price',
        '.price',
        '[class*="price"]',
    ];

    /**
     * Text that marks a number as a rate per unit rather than a price to pay.
     *
     * Dutch first, because these are Dutch shops: hoogvliet.com prints
     * "Reguliere prijs per liter € 4,79" *above* the 3,59 a bottle actually
     * costs, and this adapter took the first `.price`-ish node it found. The
     * stored number was already a unit price, so it looked expensive on a
     * bottle and would look like a bargain on anything over a litre — and it is
     * the column unit ranking divides.
     *
     * @var list<string>
     */
    private const array UNIT_RATE_MARKERS = [
        'per liter', 'per litre', 'per kilo', 'per kilogram', 'per gram',
        'per stuk', 'per stuks', 'per st', 'per eenheid', 'per piece', 'per each',
        'per 100 g', 'per 100g', 'per 100 gram', 'per 100 ml', 'per 100ml',
        'prijs per', 'price per', 'unit price', 'eenheidsprijs',
        '/kg', '/ kg', '/l', '/ l', '/ltr', '/stuk',
    ];

    /** @var array<string, string> */
    private const array CURRENCY_SYMBOLS = [
        '€' => 'EUR',
        '$' => 'USD',
        '£' => 'GBP',
        '¥' => 'JPY',
    ];

    /**
     * How long a "per litre" label can be before it is a paragraph that merely
     * mentions one. A rate and its words are a handful of characters.
     */
    private const int RATE_LABEL_MAX_LENGTH = 120;

    public function key(): string
    {
        return 'generic';
    }

    public function extract(string $url, string $html, ?AdapterContext $context = null): ExtractionResult
    {
        $crawler = self::crawler($html);

        // Every rate the page states per unit, so a candidate that merely sits
        // in a differently-labelled node can still be recognised as one.
        $rates = self::statedUnitRates($crawler);

        foreach (self::PRICE_SELECTORS as $selector) {
            // Every matching node, not only the first: a page that prints its
            // per-litre rate above the shelf price used to end the search on
            // that rate. Skipping it has to leave the real price reachable.
            foreach ($crawler->filter($selector) as $element) {
                $node = new Crawler($element);
                $rawText = trim($node->text(''));
                $dataPrice = $node->attr('data-price') ?? $node->attr('content');

                $candidate = is_string($dataPrice) && $dataPrice !== '' ? $dataPrice : $rawText;
                $price = $candidate === '' ? null : PriceNormalizer::fromMixed($candidate);

                if ($price === null || self::readsAsUnitRate($rawText) || in_array($price, $rates, strict: true)) {
                    continue;
                }

                $currency = self::detectCurrency($rawText) ?? self::detectCurrencyMeta($crawler);

                if ($currency === null) {
                    continue;
                }

                return ExtractionResult::success(new ShopSnapshot(
                    title: self::detectTitle($crawler),
                    imageUrl: self::detectImage($crawler),
                    price: $price,
                    currency: $currency,
                    // Nothing on the page states availability at this level.
                    inStock: null,
                    raw: ['source' => 'generic', 'matched_selector' => $selector],
                ));
            }
        }

        return ExtractionResult::skip();
    }

    /**
     * Whether this text prices a unit rather than the thing on the shelf.
     *
     * A false positive costs a reading; a false negative stores a number that
     * is already a unit price in the column the unit comparison divides, where
     * it reads as a bargain on anything sold by more than a litre.
     */
    private static function readsAsUnitRate(string $text): bool
    {
        $haystack = mb_strtolower($text);

        return array_any(self::UNIT_RATE_MARKERS, fn (string $marker): bool => str_contains($haystack, $marker));
    }

    /**
     * The prices this page states as a rate per unit, normalized.
     *
     * Read from the whole document rather than from the price nodes alone: a
     * shop often puts the rate in one element and the words "per liter" in its
     * label beside it, and neither half reads as a rate on its own.
     *
     * @return list<string>
     */
    private static function statedUnitRates(Crawler $crawler): array
    {
        $rates = [];

        foreach ($crawler->filter('*') as $element) {
            // `textContent` rather than a Crawler per node: this runs over every
            // element of a page that can be half a megabyte, on every price
            // check, and building a wrapper object to read one string is the
            // whole cost.
            $text = trim($element->textContent);

            // A short node only. An ancestor carrying both the rate and the
            // shelf price would otherwise brand every number on the page a rate.
            if ($text === '' || mb_strlen($text) > self::RATE_LABEL_MAX_LENGTH || ! self::readsAsUnitRate($text)) {
                continue;
            }

            $price = PriceNormalizer::fromMixed($text);

            if ($price !== null) {
                $rates[] = $price;
            }
        }

        return array_values(array_unique($rates));
    }

    private static function crawler(string $html): Crawler
    {
        $crawler = new Crawler();
        $crawler->addHtmlContent('<html><body>' . $html . '</body></html>');

        return $crawler;
    }

    private static function detectCurrency(string $text): ?string
    {
        foreach (self::CURRENCY_SYMBOLS as $symbol => $iso) {
            if (str_contains($text, $symbol)) {
                return $iso;
            }
        }

        // Three-letter ISO code embedded?
        if (preg_match('/\b(EUR|USD|GBP|JPY|CHF|SEK|NOK|DKK|PLN|CZK)\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private static function detectCurrencyMeta(Crawler $crawler): ?string
    {
        $node = $crawler->filter('[itemprop="priceCurrency"]')->first();
        if ($node->count() > 0) {
            $content = $node->attr('content') ?? trim($node->text(''));
            if ($content !== '') {
                return strtoupper($content);
            }
        }

        return null;
    }

    private static function detectTitle(Crawler $crawler): string
    {
        return PageMarkup::element($crawler, 'h1')
            ?? PageMarkup::element($crawler, 'title')
            ?? 'Unknown';
    }

    private static function detectImage(Crawler $crawler): ?string
    {
        return PageMarkup::ogImage($crawler);
    }
}
