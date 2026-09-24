<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Collection;

/**
 * The figure a surface leads with for one product, and the shop behind it.
 *
 * Per unit while some eligible shop can win the per-unit ranking — the same
 * rule {@see Product::dropComparisonUnit()} uses, so the price a person reads
 * first is the basis an alert fires on. Per pack otherwise.
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

        if (! $bestValue instanceof Shop) {
            // The pack-price headline. With no shop to buy from now, a paused
            // or sold-out product keeps the last lowest price it recorded.
            $shop = $lowest ?? ($product->cheapest_shop_id === null ? null : $product->cheapestShop);

            return new self($packs, unit: null, shop: $shop, lowestShop: null, fallbackPrice: $fallbackPrice, currency: $currency, eligible: $eligible);
        }

        return new self(
            $packs,
            unit: $packs->unit(),
            shop: $bestValue,
            lowestShop: $lowest instanceof Shop && $lowest->isNot($bestValue) ? $lowest : null,
            fallbackPrice: $fallbackPrice,
            currency: $currency,
            eligible: $eligible,
        );
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
