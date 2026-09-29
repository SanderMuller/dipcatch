<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Models\Product;

/**
 * Reads whether a page quotes its price without VAT.
 *
 * fivestartrading-holland.eu prints `€21,15` and, in the line under it,
 * "excl. BTW en verzendkosten". Nothing about the page is trade-only — an
 * ordinary add-to-cart, no login wall — so the number reached the comparison
 * as an ordinary consumer price, undercut a real €22,00 at Amazon by 4%, and
 * took both the lowest-price and the best-value answer on a product the
 * shopper cannot buy at that price. The page's own JSON-LD offer publishes no
 * `valueAddedTaxIncluded`, which is why this is read from the words.
 *
 * Only the fact is read here. What to do about it belongs to the shop row —
 * see {@see Product::votingShops()}.
 */
final readonly class VatStatement
{
    /**
     * Ways a shop says its prices leave VAT out.
     *
     * Every one names the tax. "Excl. verzendkosten" is about shipping and
     * says nothing about the price being incomplete, and plenty of ordinary
     * consumer shops print it.
     */
    private const string EXCLUSIVE_PATTERN = '/(?<!\pL)(?:'
        . 'ex(?:cl|kl)?\.?\s*(?:\d+\s*%\s*)?(?:btw|vat|tax)'
        . '|exclusief\s+(?:\d+\s*%\s*)?(?:btw|vat)'
        . '|excluding\s+(?:vat|tax)'
        . '|zonder\s+btw'
        . '|prices?\s+exclude\s+(?:vat|tax)'
        . ')(?!\pL)/u';

    /**
     * Ways a shop says the opposite.
     *
     * A page carrying both is not one this reader can call. A shop selling to
     * consumers and businesses from the same page states each, and refusing
     * its price would hide an offer the shopper can act on — the same
     * merciful direction {@see StockText} chooses when a phrase is ambiguous.
     */
    private const string INCLUSIVE_PATTERN = '/(?<!\pL)(?:'
        . 'in(?:cl|kl)\.?\s*(?:\d+\s*%\s*)?(?:btw|vat|tax)'
        . '|inclusief\s+(?:\d+\s*%\s*)?btw'
        . '|including\s+(?:vat|tax)'
        . '|prijzen\s+zijn\s+inclusief'
        . ')(?!\pL)/u';

    /**
     * The words the page uses to say its price excludes VAT, or null when it
     * says nothing of the kind — or says both things.
     *
     * The phrase itself is returned rather than a bare true, because a shop
     * disqualified from the comparison has to be able to show why.
     */
    public static function exclusivePhrase(string $html): ?string
    {
        $text = self::text($html);

        if ($text === '' || preg_match(self::INCLUSIVE_PATTERN, $text) === 1) {
            return null;
        }

        return preg_match(self::EXCLUSIVE_PATTERN, $text, $match) === 1 ? $match[0] : null;
    }

    /**
     * The page as a shopper reads it: no scripts, no styles, no tags, and
     * runs of whitespace collapsed so a phrase split across markup still
     * matches. A script is stripped because minified JavaScript is full of
     * words like `excluded` that no shopper ever sees.
     */
    private static function text(string $html): string
    {
        $stripped = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strtolower(strip_tags($stripped));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
