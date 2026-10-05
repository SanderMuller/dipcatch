<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

/** The `noul` question `TypeSafeClient::sameProduct()` asks about one candidate. */
final class SameProductQuestion
{
    /**
     * @param  array<string, string>  $candidate
     * @param  bool  $anyPack  The candidate may sell another pack size of the product.
     * @param  string|null  $exactPackSize  Asks about this one pack, not about any size the product's shops state.
     * @param  string|null  $shoppersCountry  The candidate must also be a shop for shoppers in this country.
     * @return array<string, mixed>
     */
    public static function for(array $candidate, bool $anyPack, ?string $exactPackSize, ?string $shoppersCountry = null): array
    {
        $question = $anyPack ? [
            'type' => 'noul',
            'instructions' => [
                'candidate' => $candidate,
                'question' => 'Does `candidate` sell the same product as the tracked product in the state, in any pack size?',
            ],
            'criteria' => [
                'true' => 'The same product, the same variant or flavour; the pack size may differ',
                'false' => 'A different product, variant or flavour',
            ],
        ] : [
            'type' => 'noul',
            'instructions' => [
                'candidate' => $candidate,
                'question' => $exactPackSize === null
                    ? 'Does `candidate` sell the same product as the tracked product in the state, in the same pack size or in one of its `tracked_pack_sizes`, so that the prices compare like for like?'
                    : "Does `candidate` sell the same product as the tracked product in the state, in a pack of exactly {$exactPackSize}? Judge from the candidate's own title and price.",
            ],
            'criteria' => [
                'true' => 'The same product, the same variant or flavour, and the same amount per pack',
                'false' => 'A different product, variant, flavour, or pack size',
            ],
        ];

        return $shoppersCountry === null ? $question : self::forShoppersIn($question, $shoppersCountry);
    }

    /**
     * Adds the country to a question. The shop's domain, title and listing
     * show its country and language; a barcode match says nothing about it.
     *
     * @param  array{type: string, instructions: array{candidate: array<string, string>, question: string}, criteria: array{true: string, false: string}}  $question
     * @return array<string, mixed>
     */
    private static function forShoppersIn(array $question, string $country): array
    {
        $question['instructions']['question'] .= " And is `candidate` a shop for shoppers in {$country}, with its site in that country's language or its domain in that country? Answer no for a shop for another country, even when it sells the same product.";
        $question['criteria']['true'] .= ", at a shop for shoppers in {$country}";
        $question['criteria']['false'] .= ', or a shop for shoppers in another country';

        return $question;
    }
}
