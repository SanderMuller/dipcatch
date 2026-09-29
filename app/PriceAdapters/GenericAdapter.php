<?php declare(strict_types=1);

namespace App\PriceAdapters;

use DOMElement;
use DOMNode;
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
     * "Reguliere prijs per liter € 4,79" beside the 3,59 a bottle actually
     * costs, and this adapter took the first `.price`-ish node it found. The
     * stored number was already a unit price, so it looked expensive on a
     * bottle and would look like a bargain on anything over a litre — and it is
     * the column unit ranking divides.
     *
     * A pattern rather than a substring list, because the units are short
     * enough to appear inside ordinary words: "verzending/levering" contains
     * "/l", and matching it refused a real price. The slash forms need a digit
     * in front for the same reason.
     */
    private const string UNIT_RATE_PATTERN = '/\bper\s+(?:liter|litre|kilo|kilogram|kg|gram|stuk|stuks|st|eenheid|piece|each|\d+\s*(?:g|gram|ml))\b'
        . '|\b(?:prijs|price)\s+per\b'
        . '|\beenheidsprijs\b'
        . '|\bunit\s+price\b'
        . '|\d\s*\/\s*(?:kg|ltr|liter|litre|stuk|l)\b/iu';

    /**
     * Class-name tokens that mark a price as the one no longer being charged.
     *
     * Matched on token edges rather than as substrings, so `price--old` counts
     * and `gold-edition` does not.
     */
    private const string STRUCK_CLASS_PATTERN = '/(^|[\s_-])(old|was|strike|struck|through|original|rrp|before|previous|oud|advies)([\s_-]|$)/i';

    /**
     * `regular`, `normaal` and `list` are deliberately absent. Magento names the
     * price it is charging `regular-price`, so reading those as a strike refuses
     * an ordinary price on every shop built that way — and refusing a price is
     * as wrong as storing the wrong one, just quieter.
     */

    /** Tags that strike their contents out by definition. */
    private const array STRUCK_TAGS = ['del', 's', 'strike'];

    /**
     * How far up the tree a strike-through can be declared before it stops
     * describing this price. A struck price is usually wrapped once or twice —
     * `<del><span class="price">` — and anything further up is a section, not a
     * price.
     */
    private const int STRUCK_ANCESTOR_DEPTH = 3;

    /**
     * How far above a price its "per litre" words can sit and still be
     * describing it. One or two wrappers is a label; further up is a section.
     */
    private const int RATE_LABEL_ANCESTOR_DEPTH = 2;

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

        // Twice: once refusing any price that equals a rate the page states,
        // then once allowing it. A one-litre pack prices its litre at the same
        // number as its bottle, and refusing outright made every 1 kg and 1 L
        // product unreadable — milk, juice, oil, the commonest packs there are.
        // Preferring a different number keeps the rate from winning while a
        // shelf price exists, and falling back keeps the single-number page.
        return self::firstPrice($crawler, $rates)
            ?? self::firstPrice($crawler, [])
            ?? ExtractionResult::skip();
    }

    /**
     * The first node that yields a price, skipping anything labelled as a rate
     * and anything valued like one of `$refused`.
     *
     * @param  list<string>  $refused
     */
    private static function firstPrice(Crawler $crawler, array $refused): ?ExtractionResult
    {
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

                if ($price === null
                    || self::isLabelledRate($element, $rawText)
                    || self::isStruckThrough($element)
                    || in_array($price, $refused, strict: true)) {
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

        return null;
    }

    /**
     * Whether this node's own text, or the label around it, prices a unit.
     *
     * A veto in both passes, unlike the value test. A label is evidence about
     * what a number *means*; equality is evidence about arithmetic, and on a
     * one-litre pack the arithmetic is an identity. Only the first kind is safe
     * to refuse outright.
     *
     * The ancestor walk is what closes the shape where the words and the number
     * are separate elements — `<div>Prijs per liter <span>€ 4,79</span></div>`
     * — which the value test alone let through on the second pass, putting the
     * rate back in the pack-price column on any page carrying no other number.
     *
     * An ancestor only labels this price when it holds exactly one. A wrapper
     * around both a rate and a shelf price describes the section, not either
     * number, and reading it as a label would refuse the whole page.
     */
    private static function isLabelledRate(DOMNode $node, string $ownText): bool
    {
        if (self::readsAsUnitRate($ownText)) {
            return true;
        }

        for ($depth = 0; $depth < self::RATE_LABEL_ANCESTOR_DEPTH; $depth++) {
            $parent = $node->parentNode;

            if (! $parent instanceof DOMElement) {
                return false;
            }

            $node = $parent;
            $text = trim($node->textContent);

            // Long text is a section, whatever it says. A description that
            // happens to mention "per stuk" is not labelling the price beside
            // it, and letting length off when the words match put that back.
            if (mb_strlen($text) > self::RATE_LABEL_MAX_LENGTH) {
                return false;
            }

            if (self::readsAsUnitRate($text) && self::priceTokenCount($text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many currency-shaped numbers this text holds. A malformed pattern
     * would answer false; there is none here, and counting nothing is the
     * answer that refuses to treat the text as a label.
     */
    private static function priceTokenCount(string $text): int
    {
        $count = preg_match_all('/\d+[.,]\d{2}\b/u', $text);

        return $count === false ? 0 : $count;
    }

    /**
     * Whether this node holds a price the shop has stopped charging.
     *
     * A sale page prints the regular price first and strikes it out, and this
     * reader took the first price-shaped node it found — so it stored the
     * higher, struck number and the promotion went unseen. The error is in the
     * merciful direction, which is why it survived: the shop merely looks
     * dearer than it is, and a drop never arrives rather than a false one
     * firing.
     *
     * Read from the markup, never from the numbers. "The lower of two prices
     * wins" would be a guess about meaning dressed up as arithmetic, which is
     * the mistake the rate guard already had to be talked out of.
     */
    private static function isStruckThrough(DOMNode $node): bool
    {
        for ($depth = 0; $depth <= self::STRUCK_ANCESTOR_DEPTH && $node instanceof DOMElement; $depth++) {
            if (in_array(mb_strtolower($node->tagName), self::STRUCK_TAGS, strict: true)) {
                return true;
            }

            if (preg_match(self::STRUCK_CLASS_PATTERN, $node->getAttribute('class')) === 1) {
                return true;
            }

            if (str_contains(str_replace(' ', '', mb_strtolower($node->getAttribute('style'))), 'line-through')) {
                return true;
            }

            $parent = $node->parentNode;

            if (! $parent instanceof DOMNode) {
                return false;
            }

            $node = $parent;
        }

        return false;
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
        return preg_match(self::UNIT_RATE_PATTERN, $text) === 1;
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
