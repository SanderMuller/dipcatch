<?php declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Shop;
use App\Support\ComparablePacks;
use App\Support\PackSize;

/**
 * The best value on one seeded day: the shop whose stated size gives the
 * lowest unit price, as `recomputeCheapestShop()` crowns it. Only a stated
 * size may win, so a shop whose size is borrowed from its siblings never does.
 */
final readonly class DemoBestValue
{
    private function __construct(
        public Shop $shop,
        public float $price,
        public float $unitPrice,
        public PackSize $size,
    ) {}

    /**
     * @param  list<array{shop: Shop, prices: array<int, float>}>  $offers
     */
    public static function on(array $offers, ComparablePacks $packs, int $day): ?self
    {
        $best = null;

        foreach ($offers as $offer) {
            $pack = $packs->for($offer['shop']);
            $price = $offer['prices'][$day];
            $unitPrice = $pack?->canWin() === true ? $pack->unitPriceValueFor($price) : null;

            if ($unitPrice === null || ! $pack?->size instanceof PackSize) {
                continue;
            }

            if ($best === null || $unitPrice < $best->unitPrice) {
                $best = new self($offer['shop'], $price, $unitPrice, $pack->size);
            }
        }

        return $best;
    }

    /** Whether a segment that opened on `$other` can carry on through this day. */
    public static function same(?self $one, ?self $other): bool
    {
        if ($one === null || $other === null) {
            return $one === $other;
        }

        return $one->shop->id === $other->shop->id && abs($one->price - $other->price) < 0.005;
    }
}
