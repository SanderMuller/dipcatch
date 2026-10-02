<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

/** The `noul` question `TypeSafeClient::sameProduct()` asks about one candidate. */
final class SameProductQuestion
{
    /**
     * @param  array<string, string>  $candidate
     * @param  bool  $anyPack  The candidate may sell another pack size of the product.
     * @param  string|null  $exactPackSize  Asks about this one pack, not about any size the product's shops state.
     * @return array<string, mixed>
     */
    public static function for(array $candidate, bool $anyPack, ?string $exactPackSize): array
    {
        if ($anyPack) {
            return [
                'type' => 'noul',
                'instructions' => [
                    'candidate' => $candidate,
                    'question' => 'Does `candidate` sell the same product as the tracked product in the state, in any pack size?',
                ],
                'criteria' => [
                    'true' => 'The same product, the same variant or flavour; the pack size may differ',
                    'false' => 'A different product, variant or flavour',
                ],
            ];
        }

        return [
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
    }
}
