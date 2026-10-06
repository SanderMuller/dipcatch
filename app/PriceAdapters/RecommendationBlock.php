<?php declare(strict_types=1);

namespace App\PriceAdapters;

use DOMElement;
use DOMNode;

/**
 * A block of other products on a product page, whose prices are not this
 * product's: "frequently bought together", recommendations, related
 * products. {@see GenericAdapter} skips a price inside one.
 */
final readonly class RecommendationBlock
{
    /**
     * Class-name and tag tokens that mark a block as other products, or as a
     * card for the product that ignores the variant asked for. Myprotein's
     * "frequently bought together" card prints the default variant's price
     * before the page's own price block (2026-10-06).
     */
    private const string PATTERN = '/(^|[\s_-])(fbt|frequently-bought(-together)?|also-bought|recommend(ed|ations?)?|related|rec|upsell|cross-?sell|carousel)([\s_-]|$)/i';

    /** How far up the tree a recommendation block can wrap a price. */
    private const int ANCESTOR_DEPTH = 12;

    /**
     * Whether a price sits inside a block of other products: its own element
     * or an ancestor named like one, by tag or by class.
     */
    public static function contains(DOMNode $element): bool
    {
        $node = $element;

        for ($depth = 0; $depth <= self::ANCESTOR_DEPTH && $node instanceof DOMElement; $depth++) {
            if (preg_match(self::PATTERN, $node->tagName . ' ' . $node->getAttribute('class')) === 1) {
                return true;
            }

            $node = $node->parentNode;
        }

        return false;
    }
}
