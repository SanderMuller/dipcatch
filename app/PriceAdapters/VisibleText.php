<?php declare(strict_types=1);

namespace App\PriceAdapters;

use DOMElement;
use DOMNode;
use DOMText;

/**
 * An element's text as a screen reader hears it: without the parts marked
 * `aria-hidden`. billa.at splits a price into a hidden whole part and a
 * hidden "99 €" beside a readable "0,99 €"; the plain text of the block is
 * "0,99 €099 €", and the cents alone read as 99 euros (2026-10-06).
 */
final readonly class VisibleText
{
    public static function of(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::collect($node)) ?? '');
    }

    private static function collect(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->textContent;
        }

        if ($node instanceof DOMElement && strtolower($node->getAttribute('aria-hidden')) === 'true') {
            return '';
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= self::collect($child);
        }

        return $text;
    }
}
