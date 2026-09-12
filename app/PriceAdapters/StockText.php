<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Reads the Dutch and Flemish phrases a shop puts on the page when a product
 * cannot be bought, for pages whose structured data says nothing useful.
 *
 * Only consulted when the structured signal is absent or unmappable. It never
 * overrules a shop that plainly states `InStock`: a phrase can come from a
 * related-product tile or a delivery notice, and turning a sellable product
 * into a sold-out one would hide a price the user could have paid.
 */
final readonly class StockText
{
    /**
     * Phrases that mean "you cannot buy this now", longest first so the
     * reported signal is the most specific one on the page.
     *
     * Deliberately narrow, because the page is searched as a whole and a
     * shop puts plenty of text on it that is not about this product. A bare
     * "uitverkocht" belongs to a related-product tile as often as to this
     * one, "niet leverbare artikelen" is a filter label, and "momenteel niet
     * beschikbaar" is usually the chat widget. A false sold-out hides a
     * price the shopper could have paid, so only phrasing a shop uses about
     * the product it is selling stays in this list.
     *
     * @var list<string>
     */
    private const array UNAVAILABLE = [
        'tijdelijk niet leverbaar',
        'tijdelijk niet beschikbaar',
        'momenteel niet leverbaar',
        'tijdelijk uitverkocht',
        'tijdelijk niet in voorraad',
        'momenteel uitverkocht',
        'niet meer op voorraad',
        'niet meer leverbaar',
        'niet op voorraad',
    ];

    /**
     * A phrase a shop also uses while the product is still for sale.
     *
     * @var array<string, string>
     */
    private const array NOT_PRECEDED_BY = [
        'niet op voorraad' => 'bijna ',
    ];

    /**
     * The phrase the page uses, or null when it uses none of them.
     */
    public static function unavailablePhrase(string $html): ?string
    {
        $text = self::text($html);

        if ($text === '') {
            return null;
        }

        foreach (self::UNAVAILABLE as $phrase) {
            if (preg_match(self::pattern($phrase), $text) === 1) {
                return $phrase;
            }
        }

        return null;
    }

    /**
     * Whole words only: "uitverkocht" must not match inside "uitverkochte",
     * and a phrase with a disqualifying word before it does not count. The
     * boundary is letters rather than `\b`, because stripping the markup
     * off `<p>Tijdelijk niet leverbaar</p><span>19,95</span>` leaves the
     * price stuck to the phrase.
     */
    private static function pattern(string $phrase): string
    {
        $exclude = self::NOT_PRECEDED_BY[$phrase] ?? null;
        $lookbehind = $exclude === null ? '' : '(?<!' . preg_quote($exclude, '/') . ')';

        return '/' . $lookbehind . '(?<!\pL)' . preg_quote($phrase, '/') . '(?!\pL)/u';
    }

    /**
     * The page as a shopper reads it: no scripts, no styles, no tags, and
     * runs of whitespace collapsed so a phrase split across markup still
     * matches.
     */
    private static function text(string $html): string
    {
        $stripped = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strtolower(strip_tags($stripped));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
