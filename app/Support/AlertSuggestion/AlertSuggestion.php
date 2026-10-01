<?php declare(strict_types=1);

namespace App\Support\AlertSuggestion;

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Enums\DepthSource;
use App\Enums\ProductCategory;
use App\Enums\PromotionDepthBand;
use App\Models\Product;
use App\Models\Shop;
use App\Support\Numeric;
use App\Support\UnitTargetGuide;
use App\Support\UnitWord;

/**
 * The alert step 3 of the add-product wizard suggests: a price per unit at the
 * depth this product's promotions are likely to reach, measured from its
 * normal price rather than from a promotion it is on now.
 *
 * Facts only. The view builds the sentence, where the translation lives.
 */
final readonly class AlertSuggestion
{
    private function __construct(
        /** The suggested `unit_price_target`, or null when only the default drop alert applies. */
        public ?string $unitTarget,
        /** The comparison unit: `g`, `ml` or `piece`. */
        public ?string $unit,
        public int $depth,
        public DepthSource $depthSource,
        public PromotionDepthBand $band,
        public ?ProductCategory $category,
        public ?string $normalUnitPrice,
        /** The pack the normal price comes from, as a shopper reads it: "2 kg". */
        public ?string $normalPack,
        /** The target for that pack. */
        public ?string $packTarget,
        public ?string $promotionNowHost,
        public int $promotionNowDepth,
        public bool $cappedByLaw,
        public bool $alreadyMet,
    ) {}

    public static function for(Product $product, PromotionDepthBand $band = PromotionDepthBand::Unknown): self
    {
        $category = $product->category ?? $product->suggested_category;
        $packs = $product->comparablePacks();
        $trustClaims = $category === null || ! TypicalPromotionDepth::distrustsClaims($category->department());

        $normal = null;
        $deepest = null;

        foreach ($packs->winnable($product->eligibleShops()) as $shop) {
            $price = NormalPrice::of($shop, $trustClaims);
            $size = $packs->for($shop)?->size;
            $unitValue = $price === null ? null : $size?->unitPriceValueFor($price->price);

            if ($price === null || $size === null || $unitValue === null) {
                continue;
            }

            if ($normal === null || $unitValue < $normal['value']) {
                $normal = ['value' => $unitValue, 'pack' => UnitWord::pack($size), 'perPack' => $size->unit === 'piece' ? $size->quantity : $size->quantity / 1000];
            }

            if ($price->depthNow > 0 && ($deepest === null || $price->depthNow > $deepest['depth'])) {
                $deepest = ['depth' => $price->depthNow, 'host' => (string) $shop->host];
            }
        }

        [$prior, $source] = match (true) {
            $band !== PromotionDepthBand::Unknown => [(int) $band->depth(), DepthSource::Jev],
            $category instanceof ProductCategory => [TypicalPromotionDepth::for($category), DepthSource::Category],
            default => [0, DepthSource::None],
        };

        $now = intdiv($deepest['depth'] ?? 0, 5) * 5;

        if ($now > $prior) {
            [$prior, $source] = [$now, DepthSource::PromotionNow];
        }

        $cap = min(TypicalPromotionDepth::legalCap($category), TypicalPromotionDepth::MAX);
        // A product that may not go on sale gets no target, whatever a shop shows now.
        $depth = $band === PromotionDepthBand::Fixed ? 0 : min($cap, $prior);

        $normalUnit = $normal === null ? null : Numeric::str(sprintf('%.10F', $normal['value']));
        $target = $normalUnit === null || $depth === 0
            ? null
            : UnitTargetGuide::percentUnder($normalUnit, $normal['perPack'], $depth);

        return new self(
            unitTarget: $target['unit'] ?? null,
            unit: $packs->unit(),
            depth: $target === null ? 0 : $depth,
            depthSource: $target === null ? DepthSource::None : $source,
            band: $band,
            category: $category,
            normalUnitPrice: $normalUnit === null ? null : bcadd($normalUnit, '0', 4),
            normalPack: $normal['pack'] ?? null,
            packTarget: $target['packPrice'] ?? null,
            promotionNowHost: $deepest['host'] ?? null,
            promotionNowDepth: $deepest['depth'] ?? 0,
            cappedByLaw: $target !== null && $prior > $cap && $cap < TypicalPromotionDepth::MAX,
            alreadyMet: $target !== null && self::meets($product->bestValueShop(), $target['unit']),
        );
    }

    /** The detector's own comparison, so a pre-latch and the alert agree. */
    private static function meets(?Shop $shop, string $target): bool
    {
        $value = $shop?->unitPriceValue();

        return $value !== null && DetectUnitPriceTarget::meets($value, $target);
    }
}
