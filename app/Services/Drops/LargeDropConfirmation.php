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
     * True when the shop's eligible reading before `$reading` was a large drop
     * in its own right. A merely discounted one — past the notify threshold
     * but short of the confirmation ceiling — would let a single anomalous
     * reading through on its coat-tails.
     */
    public function isConfirmedByPrevious(Product $product, PriceCheck $reading, ReferenceValue $reference): bool
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
    public function isExempt(Shop $shop): bool
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
            ->where('shop_id', $reference->isUnitBasis() ? $product->best_value_shop_id : $product->cheapest_shop_id)
            ->eligible()
            ->latest('id')
            ->first();

        // The newest reading has to be the one showing this price, from a shop
        // that is confirmed at all. A shop joining the product never asks for
        // a second reading, so nothing is on its way.
        return $latest !== null
            && bccomp(Numeric::str((string) $latest->price), Numeric::str($winningPack), self::BC_SCALE) === 0
            && ! $this->isExempt($latest->shop)
            && ! $latest->joinsAProductAlreadyWatchedElsewhere()
            && ! $this->isConfirmedByPrevious($product, $latest, $reference);
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
