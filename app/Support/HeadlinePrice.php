<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\PriceDisplay;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Collection;

/**
 * The figure a surface leads with for one product, and the shop behind it.
 *
 * The pack price leads while every shop that can win sells the same pack:
 * the price per unit ranks those shops in the same order, and people read
 * a pizza as €3.99, not €9.98 /kg. The price per unit leads once the packs
 * differ, because only it compares a 150 g pack with a 250 g one fairly.
 * The product's `price_display` overrides both ways.
 *
 * The comparison itself stays per unit whatever leads: the best-value shop,
 * the shop table and the drop alerts all rank on it.
 *
 * Reads the product's loaded shops. A caller that already resolved the packs
 * passes them in rather than resolving twice.
 */
final readonly class HeadlinePrice
{
    private function __construct(
        public ComparablePacks $packs,
        /** The comparison unit code, or null for a pack-price headline. */
        public ?string $unit,
        /** Best value when {@see $unit} is set, else the lowest pack price. */
        public ?Shop $shop,
        /** The lowest pack price, only when another shop than {@see $shop} holds it. */
        public ?Shop $lowestShop,
        /** The per-unit winner, whichever price leads; null when nothing compares per unit. */
        public ?Shop $bestValue,
        private ?string $fallbackPrice,
        private string $currency,
        /** @var Collection<int, Shop> The shops that can be bought from now. */
        private Collection $eligible,
    ) {}

    public static function of(Product $product, ?ComparablePacks $packs = null): self
    {
        $packs ??= $product->comparablePacks();
        $eligible = $product->eligibleShops();
        $bestValue = $packs->hasComparisonUnit() ? $packs->cheapestPerUnit($eligible) : null;
        $lowest = $product->lowestOutlayShop();
        $currency = (string) $product->currency;
        $fallbackPrice = $product->cheapest_price === null ? null : (string) $product->cheapest_price;

        if (! $bestValue instanceof Shop || $product->price_display === PriceDisplay::Pack) {
            // The pack-price headline: nothing compares per unit, or the
            // person chose the pack price. With no shop to buy from now, a
            // paused or sold-out product keeps the last lowest price it
            // recorded.
            $shop = $lowest ?? ($product->cheapest_shop_id === null ? null : $product->cheapestShop);

            return new self($packs, unit: null, shop: $shop, lowestShop: null, bestValue: $bestValue, fallbackPrice: $fallbackPrice, currency: $currency, eligible: $eligible);
        }

        return new self(
            $packs,
            // The winner's pack price leads while the winners sell one size:
            // among them the lowest pack price is the best value, and a shop
            // outside the comparison, a box of bars beside a bag, is only the
            // lowest-price note.
            unit: self::leadsPerUnit($product, $packs, $eligible) ? $packs->unit() : null,
            shop: $bestValue,
            lowestShop: $lowest instanceof Shop && $lowest->isNot($bestValue) ? $lowest : null,
            bestValue: $bestValue,
            fallbackPrice: $fallbackPrice,
            currency: $currency,
            eligible: $eligible,
        );
    }

    /**
     * Whether the price per unit leads: the person's choice, else whether the
     * shops that can win sell more than one pack size. The same shops
     * `cheapestPerUnit()` ranks, so the lead and the winner agree.
     *
     * @param  Collection<int, Shop>  $eligible
     */
    private static function leadsPerUnit(Product $product, ComparablePacks $packs, Collection $eligible): bool
    {
        if ($product->price_display !== null) {
            return $product->price_display === PriceDisplay::Unit;
        }

        $first = null;

        foreach ($packs->winnable($eligible) as $shop) {
            $size = $packs->for($shop)?->size;

            if ($first === null) {
                $first = $size;
            } elseif (! $first->isSameSizeAs($size)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the product compares per unit at all, whichever price leads. */
    public function comparesPerUnit(): bool
    {
        return $this->bestValue !== null;
    }

    /** The comparison unit code while the product compares per unit, whichever price leads. */
    public function comparisonUnit(): ?string
    {
        return $this->bestValue === null ? null : $this->packs->unit();
    }

    /**
     * The headline shop when it sells the product now. With none, the
     * headline falls back to the last cheapest shop, which may be sold out or
     * switched off, and nobody can buy there at that price.
     */
    public function buyableShop(): ?Shop
    {
        return $this->shop instanceof Shop && $this->eligible->contains($this->shop) ? $this->shop : null;
    }

    public function isPerUnit(): bool
    {
        return $this->unit !== null;
    }

    /** The unit price at four decimals, or null for a pack-price headline. */
    public function unitPrice(): ?string
    {
        return $this->unit === null || $this->shop === null ? null : $this->packs->unitPriceOf($this->shop);
    }

    /** The unit price without the running bundle, for the struck-through figure beside it. */
    public function regularUnitPrice(): ?string
    {
        if ($this->unit === null || $this->shop?->liveBundleOffer() === null) {
            return null;
        }

        return $this->packs->for($this->shop)?->unitPriceFor($this->shop->singleItemPrice());
    }

    public function packPrice(): ?string
    {
        $price = $this->shop->current_price ?? $this->fallbackPrice;

        return $price === null ? null : (string) $price;
    }

    public function currency(): string
    {
        return $this->shop->currency ?? $this->currency;
    }

    /**
     * Under a pack-price headline, the pack and its price per unit:
     * `400 g · €9.98 /kg`. Null for a per-unit headline, which states the
     * pack on its own line, or when the pack has no comparable size.
     */
    public function unitLine(): ?string
    {
        // Only on a size the shop's own page states: an estimate is no fact.
        if ($this->unit !== null || $this->shop === null || $this->packs->for($this->shop)?->canWin() !== true) {
            return null;
        }

        $unitPrice = $this->packs->unitPriceOf($this->shop);
        $size = $this->packs->for($this->shop)->size;

        if ($unitPrice === null || $size === null) {
            return null;
        }

        return UnitWord::pack($size) . ' · ' . MoneyFormatter::unitPrice($unitPrice, $this->currency()) . ' ' . UnitWord::labelFor($this->packs->unit());
    }

    /** `€0.0275 /piece`, or `€21.99` for a pack-price headline, or a dash. */
    public function text(): string
    {
        $unitPrice = $this->unitPrice();

        if ($unitPrice !== null) {
            return MoneyFormatter::unitPrice($unitPrice, $this->currency()) . ' ' . UnitWord::labelFor($this->unit);
        }

        return MoneyFormatter::format($this->packPrice(), $this->currency());
    }

    /**
     * How much more per unit the lowest pack price costs than the best value,
     * in whole percent. Null when the two are one shop, or the lowest-price
     * shop has no unit price to compare.
     */
    public function lowestCostsMorePercent(): ?int
    {
        if ($this->lowestShop === null || $this->shop === null) {
            return null;
        }

        $lowestUnit = $this->packs->unitPriceValueOf($this->lowestShop);
        $bestUnit = $this->packs->unitPriceValueOf($this->shop);

        if ($lowestUnit === null || $bestUnit === null || $bestUnit <= 0 || $lowestUnit <= $bestUnit) {
            return null;
        }

        return max(1, (int) round(($lowestUnit - $bestUnit) / $bestUnit * 100));
    }

    /**
     * How far the running bundle puts the best value below its price per unit
     * at the regular single-item price, in whole percent. Null without a live
     * bundle on a per-unit headline.
     */
    public function belowRegularPercent(): ?int
    {
        if ($this->unit === null || $this->shop?->liveBundleOffer() === null) {
            return null;
        }

        $dealUnit = $this->packs->unitPriceValueOf($this->shop);
        $regularUnit = $this->packs->for($this->shop)?->unitPriceValueFor($this->shop->singleItemPrice());

        if ($dealUnit === null || $regularUnit === null || $regularUnit <= $dealUnit) {
            return null;
        }

        return max(1, (int) round(($regularUnit - $dealUnit) / $regularUnit * 100));
    }

    /**
     * Whether a shop's price per unit may be set beside the headline's: one
     * that can be bought now, on a size its own page states. A sold-out shop or
     * an estimated size can be lower per unit and still not be a better buy.
     */
    public function isComparable(Shop $shop): bool
    {
        return $this->isPerUnit()
            && $this->eligible->contains($shop)
            && $this->packs->for($shop)?->canWin() === true
            && $this->packs->unitPriceValueOf($shop) !== null;
    }

    public function packLine(?Shop $shop = null): ?PackLine
    {
        $shop ??= $this->shop;

        return $shop === null ? null : PackLine::of($shop, $this->packs);
    }
}
