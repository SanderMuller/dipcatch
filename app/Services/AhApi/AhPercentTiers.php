<?php declare(strict_types=1);

namespace App\Services\AhApi;

use App\PriceAdapters\BundleOffer;
use App\PriceAdapters\PriceNormalizer;

/**
 * The steps of an AH `DISCOUNT_TIERED_PERCENT` offer. AH sends one tier per
 * step and words the mechanism by its top step only: Edet toilet
 * paper, captured on 2026-11-20, was "2 stapelen tot 60%" over a count 1 at
 * 50% and a count 2 at 60%.
 */
final readonly class AhPercentTiers
{
    /** @param  list<array{int, numeric-string}>  $tiers  quantity and percentage off */
    private function __construct(private array $tiers) {}

    /** @param  array<mixed>  $card */
    public static function fromCard(array $card): self
    {
        $labels = data_get($card, 'discountLabels');

        if (! is_array($labels)) {
            return new self([]);
        }

        return new self(array_values(array_filter(array_map(self::tier(...), $labels))));
    }

    public static function isTierLabel(mixed $label): bool
    {
        return self::tier($label) !== null;
    }

    public function isEmpty(): bool
    {
        return $this->tiers === [];
    }

    public function bundleOffer(string $singleItemPrice): ?BundleOffer
    {
        return BundleOffer::fromTiers(
            array_values(array_filter($this->tiers, static fn (array $tier): bool => $tier[0] >= 2)),
            $singleItemPrice,
        );
    }

    /**
     * The shelf price after the one-item step ("1 stuk 50% korting"), a plain
     * discount that AH leaves out of `currentPrice`. Without that step the
     * price stays as it is.
     *
     * @param  numeric-string  $price
     * @return numeric-string
     */
    public function singleItemPrice(string $price): string
    {
        $step = array_find($this->tiers, static fn (array $tier): bool => $tier[0] === 1);

        if ($step === null) {
            return $price;
        }

        $discounted = bcmul($price, bcsub('1', bcdiv($step[1], '100', 6), 6), 6);

        return bcdiv(bcadd($discounted, '0.005', 6), '1', 2);
    }

    /** @return array{int, numeric-string}|null */
    private static function tier(mixed $label): ?array
    {
        if (! is_array($label) || data_get($label, 'code') !== 'DISCOUNT_TIERED_PERCENT' || data_get($label, 'unit') !== 'PIECE') {
            return null;
        }

        $count = data_get($label, 'count');
        $percentage = PriceNormalizer::fromMixed(data_get($label, 'percentage'));

        if (! is_int($count) || $count < 1 || $percentage === null
            || bccomp($percentage, '0', 4) <= 0 || bccomp($percentage, '100', 4) >= 0) {
            return null;
        }

        return [$count, $percentage];
    }
}
