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

/**
 * The alert to suggest for a product: a price per unit at the depth its
 * promotions are likely to reach, measured from its normal price rather than
 * from a promotion it is on now, for the alert step of adding a product.
 *
 * Facts only, so the sentence is built where the translation lives.
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
        $department = $category?->department();

        $normal = null;
        $deepest = null;

        foreach ($packs->winnable($product->eligibleShops()) as $shop) {
            $price = NormalPrice::of($shop, $department);
            $size = $packs->for($shop)?->size;
            $choice = $price === null || $size === null
                ? null
                : UnitTargetGuide::packChoice((string) $shop->id, (string) $shop->host, $size, $price->price);

            if ($price === null || $choice === null || $choice['unitPrice'] === null) {
                continue;
            }

            if ($normal === null || $choice['unitPrice'] < $normal['unitPrice']) {
                $normal = [...$choice, 'normalPrice' => $price->price];
            }

            if ($price->depthNow > 0 && ($deepest === null || [$price->depthStep, $price->depthNow] > [$deepest['step'], $deepest['depth']])) {
                $deepest = ['step' => $price->depthStep, 'depth' => $price->depthNow, 'host' => (string) $shop->host];
            }
        }

        [$prior, $source] = match (true) {
            $band !== PromotionDepthBand::Unknown => [(int) $band->depth(), DepthSource::Jev],
            $category instanceof ProductCategory => [TypicalPromotionDepth::for($category), DepthSource::Category],
            default => [0, DepthSource::None],
        };

        $now = $deepest['step'] ?? 0;

        if ($now > $prior) {
            [$prior, $source] = [$now, DepthSource::PromotionNow];
        }

        $legalCap = TypicalPromotionDepth::legalCap($category);
        $cap = $legalCap ?? TypicalPromotionDepth::MAX;
        // A product that may not go on sale gets no target, whatever a shop shows now.
        $depth = $band === PromotionDepthBand::Fixed ? 0 : min($cap, $prior);

        $target = $normal === null || $depth === 0
            ? null
            : UnitTargetGuide::percentUnder($normal['normalPrice'], $normal['perPack'], $depth);

        return new self(
            unitTarget: $target['unit'] ?? null,
            unit: $packs->unit(),
            depth: $target === null ? 0 : $depth,
            depthSource: $target === null ? DepthSource::None : $source,
            band: $band,
            category: $category,
            normalUnitPrice: $normal === null ? null : bcdiv($normal['normalPrice'], Numeric::str(sprintf('%.10F', $normal['perPack'])), 4),
            normalPack: $normal['pack'] ?? null,
            packTarget: $target['packPrice'] ?? null,
            promotionNowHost: $deepest['host'] ?? null,
            promotionNowDepth: $deepest['depth'] ?? 0,
            cappedByLaw: $target !== null && $legalCap !== null && $prior > $legalCap,
            alreadyMet: $target !== null && self::meets($product->bestValueShop(), $target['unit']),
        );
    }

    /** The detector's own comparison, so `alreadyMet` and the alert agree. */
    private static function meets(?Shop $shop, string $target): bool
    {
        $value = $shop?->unitPriceValue();

        return $value !== null && DetectUnitPriceTarget::meets($value, $target);
    }
}
