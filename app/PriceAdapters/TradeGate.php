<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * Reads whether a page keeps its price for trade accounts only.
 *
 * prometeus.nl publishes `og:price:amount = 12,99` on a page that shows no
 * price at all and says "Sign in to see prices" where one would be. The
 * OpenGraph reader took the number, and a consumer cannot buy at it — or at
 * all. A target of 13.00 was met on the spot by a price that does not exist
 * for the person who set it.
 *
 * Two facts, and both are required. A phrase on its own belongs to a
 * related-product tile as often as to this product, and a shop that gates
 * some of its catalogue still sells the rest at a real price. A price the
 * page never shows, with no phrase to explain it, is as likely a reader that
 * missed it as a gate. Together they are the shape of a page that is not
 * selling to the public.
 */
final readonly class TradeGate
{
    /**
     * What a shop says in place of a price it will not show.
     *
     * Never a bare "b2b" or "zakelijk". Prometeus names its own vendor
     * "Prometeus B2B", and a shop with a trade portal links to it from the
     * footer of every consumer page it serves.
     *
     * @var list<string>
     */
    private const array GATED = [
        'sign in to see prices',
        'sign in to see price',
        'log in to see prices',
        'login to see prices',
        'login for prices',
        'inloggen voor prijzen',
        'prijzen na inloggen',
        'prijzen zichtbaar na inloggen',
        'request official b2b account',
        'request a b2b account',
        'request b2b account',
        'alleen voor bedrijven',
        'alleen voor zakelijke klanten',
        'uitsluitend voor bedrijven',
    ];

    /**
     * The words the page uses to withhold its price, or null when it withholds
     * nothing — or when it does show the price that was read.
     *
     * `$price` is what the adapter extracted, in its normalized form. It is
     * searched for as the shopper would see it, in both decimal conventions.
     */
    public static function gatedPhrase(string $html, string $price): ?string
    {
        $text = self::text($html);

        if ($text === '' || self::statesPrice($text, $price)) {
            return null;
        }

        foreach (self::GATED as $phrase) {
            if (str_contains($text, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }

    /**
     * Whether the page shows this price to a reader.
     *
     * Matched against the text with its spaces removed, because a shop that
     * splits a price across elements — `<span>12</span><span>,99</span>` —
     * leaves a gap behind once the tags are gone. Reading that as a page with
     * no price would be the hoogvliet markup all over again.
     */
    private static function statesPrice(string $text, string $price): bool
    {
        $compact = str_replace(' ', '', $text);

        return str_contains($compact, $price)
            || str_contains($compact, str_replace('.', ',', $price));
    }

    /**
     * The page as a shopper reads it. Scripts are stripped: a Shopify theme
     * ships its whole catalogue as JSON, prices included, so a gated page
     * would otherwise look like one that states its price.
     */
    private static function text(string $html): string
    {
        $stripped = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strtolower(strip_tags($stripped));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
