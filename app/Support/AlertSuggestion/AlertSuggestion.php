<?php declare(strict_types=1);

namespace App\Support\AlertSuggestion;

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Enums\DepthSource;
use App\Enums\ProductCategory;
use App\Enums\PromotionDepthBand;
use App\Models\Product;
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
    /** How far under the middle of the shops' normal prices, in percent, a shop counts as far cheaper. */
    private const int OUTLIER_UNDER_MIDDLE = 25;

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
        /** Whether the depth lies halfway between the usual one and a deeper offer running now. */
        public bool $halfwayToOffer,
        /** @var list<string> Shops left out of the normal price for being far cheaper than the rest. */
        public array $cheapOutliers,
    ) {}

    public static function for(Product $product, PromotionDepthBand $band = PromotionDepthBand::Unknown): self
    {
        $category = $product->category ?? $product->suggested_category;
        $packs = $product->comparablePacks();
        $department = $category?->department();

        $normals = [];
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

            $normals[] = [...$choice, 'normalPrice' => $price->price];

            if ($price->depthNow > 0 && ($deepest === null || [$price->depthStep, $price->depthNow] > [$deepest['step'], $deepest['depth']])) {
                $deepest = ['step' => $price->depthStep, 'depth' => $price->depthNow, 'host' => (string) $shop->host];
            }
        }

        [$normal, $outliers] = self::normalShop($normals);

        [$prior, $source] = match (true) {
            $band !== PromotionDepthBand::Unknown => [(int) $band->depth(), DepthSource::Jev],
            $category instanceof ProductCategory => [TypicalPromotionDepth::for($category), DepthSource::Category],
            default => [0, DepthSource::None],
        };

        [$prior, $source, $halfway] = self::withOfferNow($prior, $source, $deepest['step'] ?? 0);

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
            alreadyMet: $target !== null && self::meets($product, $target['unit']),
            halfwayToOffer: $target !== null && $halfway && ($legalCap === null || $prior <= $legalCap),
            cheapOutliers: $target === null ? [] : $outliers,
        );
    }

    /**
     * The shop whose normal price a promotion goes off: the lowest normal
     * price per unit, leaving out a shop whose normal price is far under the
     * middle of the shops', an online-only seller say. The usual depth off
     * its price is a deal none of the shops runs. Two shops have no middle
     * to tell an outlier by.
     *
     * @param  list<array{shopId: string, host: string, pack: string, perPack: float, price: float|null, unitPrice: float, normalPrice: numeric-string}>  $normals
     * @return array{0: array{shopId: string, host: string, pack: string, perPack: float, price: float|null, unitPrice: float, normalPrice: numeric-string}|null, 1: list<string>} the shop, and the hosts left out
     */
    private static function normalShop(array $normals): array
    {
        usort($normals, static fn (array $a, array $b): int => $a['unitPrice'] <=> $b['unitPrice']);
        $count = count($normals);

        if ($count < 3) {
            return [$normals[0] ?? null, []];
        }

        $middle = $count % 2 === 1
            ? $normals[intdiv($count, 2)]['unitPrice']
            : ($normals[$count / 2 - 1]['unitPrice'] + $normals[$count / 2]['unitPrice']) / 2;
        $floor = $middle * (1 - self::OUTLIER_UNDER_MIDDLE / 100);
        $kept = array_values(array_filter($normals, static fn (array $row): bool => $row['unitPrice'] >= $floor));
        $outliers = array_values(array_map(
            static fn (array $row): string => $row['host'],
            array_filter($normals, static fn (array $row): bool => $row['unitPrice'] < $floor),
        ));

        // The middle shop itself is always kept.
        return [$kept[0], $outliers];
    }

    /**
     * An offer deeper than usual moves the suggestion halfway towards it, on
     * the same 5% steps: the usual depth would ask far more than the shop
     * just charged, and the offer itself may seldom come back. With no usual
     * depth to go on, the offer is the only sign there is.
     *
     * @return array{0: int, 1: DepthSource, 2: bool} the depth, its source, and whether it is halfway
     */
    private static function withOfferNow(int $prior, DepthSource $source, int $now): array
    {
        $halfway = intdiv(intdiv($prior + $now, 2), 5) * 5;

        return match (true) {
            $prior > 0 && $halfway > $prior => [$halfway, DepthSource::PromotionNow, true],
            $prior === 0 && $now > 0 => [$now, DepthSource::PromotionNow, false],
            default => [$prior, $source, false],
        };
    }

    /** The detector's own comparison, so `alreadyMet` and the alert agree. */
    private static function meets(Product $product, string $target): bool
    {
        $shop = $product->bestValueShop();
        // On the size the resolver compares the shop by, which can be the
        // second size it states rather than its pack columns.
        $value = $shop === null ? null : $product->comparablePacks()->unitPriceValueOf($shop);

        return $value !== null && DetectUnitPriceTarget::meets($value, $target);
    }
}
