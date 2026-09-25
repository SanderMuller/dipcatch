<?php declare(strict_types=1);

namespace App\Services\Drops;

use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Support\Numeric;

/**
 * The second opinion a large drop needs before it alerts.
 *
 * A drop at or past `drops.confirm_above_pct` below the reference is what one
 * mis-extraction looks like, so it alerts only when the shop's previous
 * eligible reading agreed. `DetectDrop::confirmLargeDrop()` applies the rule as
 * a reading lands; the product page asks whether a drop is still waiting.
 * Nothing is stored: the shop's own price-check history is the record.
 */
final readonly class LargeDropConfirmation
{
    private const int BC_SCALE = 4;

    public function __construct(
        private Reference $reference,
        private DropEvaluator $evaluator,
        private DropLatch $latch,
    ) {}

    /**
     * What `$reading` means for a drop already known to be large. Every guard
     * lives here once, so the detector and the product page apply the same
     * list in the same way.
     */
    public function verdictFor(Product $product, PriceCheck $reading, ReferenceValue $reference): LargeDropVerdict
    {
        // `CheckShopPrice::persist()` recomputes on every outcome, a failure
        // included, and a failed check leaves the shop's price cached. A
        // reading that read nothing cannot anchor a drop.
        if (! $reading->isEligible()) {
            return LargeDropVerdict::NotThisReading;
        }

        if ($reading->shop_id !== self::basisShopId($product, $reference)) {
            return LargeDropVerdict::NotThisReading;
        }

        // Both sides pack money: a price check records what the page charged,
        // never a price per kilo.
        $winningPack = $product->winningPackPrice($reference->unit);

        if ($winningPack === null
            || bccomp(Numeric::str((string) $reading->price), Numeric::str($winningPack), self::BC_SCALE) !== 0) {
            return LargeDropVerdict::NotThisReading;
        }

        // A shop joining a product watched elsewhere is not a fall. Last of
        // the reading guards because it is the one that queries.
        if ($reading->joinsAProductAlreadyWatchedElsewhere()) {
            return LargeDropVerdict::NotThisReading;
        }

        if ($this->isExempt($reading->shop)) {
            return LargeDropVerdict::Exempt;
        }

        return $this->isConfirmedByPrevious($product, $reading, $reference)
            ? LargeDropVerdict::Confirmed
            : LargeDropVerdict::Awaiting;
    }

    /**
     * True when the shop's eligible reading before `$reading` was a large drop
     * in its own right. A merely discounted one — past the notify threshold
     * but short of the confirmation ceiling — would let a single anomalous
     * reading through on its coat-tails.
     */
    private function isConfirmedByPrevious(Product $product, PriceCheck $reading, ReferenceValue $reference): bool
    {
        $previous = PriceCheck::query()
            ->where('shop_id', $reading->shop_id)
            ->where('id', '<', $reading->id)
            ->eligible()
            ->latest('id')
            ->first();

        return $previous !== null && $this->qualifiesAsLargeDrop($product, (string) $previous->price, $reference);
    }

    /**
     * A dataset or API shop reads a structured field and never fetches a page,
     * so a second reading is the same row again and confirms nothing. Those
     * shops alert on one reading.
     */
    private function isExempt(Shop $shop): bool
    {
        return app(AhApiSource::class)->supports($shop->host)
            || app(CheckjebonSource::class)->supports($shop->host);
    }

    /**
     * True when the product's price is a large drop that one reading has seen
     * and nothing has confirmed or cleared yet. The product page says so: the
     * chart already shows the new price, and no alert has arrived.
     *
     * Read-only. The rule is taken from the newest eligible reading of the
     * shop the basis is measured on. A drop the latch already alerted on is
     * not waiting for anything.
     */
    public function isAwaited(Product $product): bool
    {
        $reference = $this->reference->compute($product);
        $newPrice = $reference === null ? null : $product->dropBasisPrice($reference->unit);
        $winningPack = $reference === null ? null : $product->winningPackPrice($reference->unit);

        if ($reference === null || $newPrice === null || $winningPack === null) {
            return false;
        }

        $outcome = $this->evaluator->evaluate($product, $newPrice, $reference, $winningPack);

        if (! $outcome->belowThreshold || ! $outcome->needsConfirmation
            || ! $this->latch->shouldNotify($product, $newPrice, $reference->unit)) {
            return false;
        }

        $latest = PriceCheck::query()
            ->where('shop_id', self::basisShopId($product, $reference))
            ->eligible()
            ->latest('id')
            ->first();

        return $latest !== null && $this->verdictFor($product, $latest, $reference) === LargeDropVerdict::Awaiting;
    }

    /**
     * The shop the basis is measured on: the best-value winner once the
     * product has a comparison unit, the cheapest pack otherwise.
     */
    private static function basisShopId(Product $product, ReferenceValue $reference): ?string
    {
        return $reference->isUnitBasis() ? $product->best_value_shop_id : $product->cheapest_shop_id;
    }

    /**
     * `$packPrice` is what the shop charged at that earlier reading. It is
     * converted with the winner's current size before it is compared: the
     * reference is a unit figure, and handing it a pack price would compare two
     * scales.
     */
    private function qualifiesAsLargeDrop(Product $product, string $packPrice, ReferenceValue $reference): bool
    {
        $price = $reference->isUnitBasis()
            ? $product->bestValuePackSize()?->unitPriceFor($packPrice)
            : $packPrice;

        if ($price === null) {
            return false;
        }

        $outcome = $this->evaluator->evaluate($product, $price, $reference, $packPrice);

        return $outcome->belowThreshold && $outcome->needsConfirmation;
    }
}
