<?php declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\Shop;
use App\Services\Drops\TierDefaults;

/**
 * A product's alert settings from before one target per unit: a price for
 * any pack, and a drop in percent or money. Read only by
 * `dipcatch:convert-alerts-to-unit-targets`, and gone with their columns.
 */
final readonly class OldAlertSettings
{
    public function __construct(private Product $product) {}

    /** @return list<string> */
    public function describe(): array
    {
        $product = $this->product;

        return array_values(array_filter([
            $product->target_price === null ? null : 'price for any pack ' . $product->target_price,
            $product->drop_threshold_pct === null ? null : 'drop ' . Numeric::trimmed((string) $product->drop_threshold_pct) . '%',
            $product->drop_threshold_abs === null ? null : 'drop ' . $product->drop_threshold_abs,
        ]));
    }

    /** Whether the stored drops are a pair the defaults would give, or the 10% the first form filled in. */
    public function isTierDefault(): bool
    {
        $pct = $this->product->drop_threshold_pct === null ? null : (float) $this->product->drop_threshold_pct;
        $abs = $this->product->drop_threshold_abs === null ? null : (float) $this->product->drop_threshold_abs;

        return ($pct === 10.0 && $abs === null) || array_any(
            [1.0, 50.0, 200.0, 1000.0, 5000.0],
            fn (float $band): bool => [$pct, $abs] === array_values(TierDefaults::for($band)),
        );
    }

    /**
     * The highest target the settings give, the easiest of them read against
     * today's best: per unit on the best-value shop's pack, or
     * per pack on the shop the product was added with while nobody can win
     * per unit. Null when no setting gives one.
     *
     * @return array{target: string, unit: string, shop: ?Shop, today: ?string}|null
     */
    public function target(): ?array
    {
        $packs = $this->product->comparablePacks();
        $winner = $this->product->bestValueShop();
        $size = $winner === null ? null : $packs->for($winner)?->size;
        $unit = $packs->unit();

        if ($winner?->current_price !== null && $size !== null && $unit !== null) {
            return $this->highest((string) $winner->current_price, UnitTargetMath::perPack($size), $unit, null, $packs->unitPriceOf($winner) . ' ' . UnitWord::labelFor($unit));
        }

        $shop = $this->product->firstAddedShop();
        $price = $shop?->current_price === null ? null : (string) $shop->current_price;

        return $shop === null ? null : $this->highest($price, '1', UnitTargetConversion::PACK, $shop, $price);
    }

    /**
     * @param  numeric-string  $perPack
     * @return array{target: string, unit: string, shop: ?Shop, today: ?string}|null
     */
    private function highest(?string $packPrice, string $perPack, string $unit, ?Shop $shop, ?string $today): ?array
    {
        $product = $this->product;
        $candidates = array_values(array_filter([
            $product->target_price === null ? null : UnitTargetMath::fromPackPrice((string) $product->target_price, $perPack),
            $product->drop_threshold_pct === null || $packPrice === null ? null : UnitTargetMath::percentUnderPack($packPrice, (string) $product->drop_threshold_pct, $perPack),
            $product->drop_threshold_abs === null || $packPrice === null ? null : UnitTargetMath::amountUnder($packPrice, (string) $product->drop_threshold_abs, $perPack),
        ], is_string(...)));
        usort($candidates, fn (string $a, string $b): int => bccomp(Numeric::str($b), Numeric::str($a), 4));

        return $candidates === [] ? null : ['target' => $candidates[0], 'unit' => $unit, 'shop' => $shop, 'today' => $today];
    }
}
