<?php declare(strict_types=1);

namespace App\Actions\Drops;

use App\Models\Product;
use App\Models\Shop;
use App\Notifications\UnitPriceTargetNotification;
use App\Support\Numeric;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Fires when a product's best value reaches the price per unit the shopper
 * asked to be told about — "let me know when this is 6.00 per kilo or less".
 *
 * Deliberately not the drop engine. That one measures a fall against the
 * product's own 30-day median and answers "is this unusually cheap"; a
 * target answers "is this cheap enough for me", which needs no history and
 * no reference. They can fire on the same check without contradicting each
 * other, because they say different things.
 *
 * It also cannot ride on the cheapest-price trigger: the best value can
 * improve while the cheapest price does not move at all — a rival shop
 * dropping its own price changes nothing about which shop is cheapest.
 * So this runs after every successful check.
 */
final readonly class DetectUnitPriceTarget
{
    private const int BC_SCALE = 4;

    public function __invoke(Product $product): void
    {
        $target = $product->unit_price_target;

        if ($target === null) {
            return;
        }

        $shop = $product->bestValueShop();
        $unitPrice = $shop?->unitPrice();

        if ($shop === null || $unitPrice === null) {
            $this->clearLatch($product);

            return;
        }

        if (bccomp(Numeric::str($unitPrice), Numeric::str((string) $target), self::BC_SCALE) > 0) {
            // Above the target: nothing to say, and the next time it drops
            // below is worth saying again.
            $this->clearLatch($product);

            return;
        }

        if ($this->alreadyNotified($product, $unitPrice)) {
            return;
        }

        $this->notify($product, $shop, $unitPrice);
    }

    /**
     * The latch holds while the value stays at or under the target, so a
     * shop rechecked every six hours does not send the same news four times
     * a day. A value that drops further than the one already sent is news
     * again.
     */
    private function alreadyNotified(Product $product, string $unitPrice): bool
    {
        $notified = $product->unit_price_notified;

        if ($notified === null) {
            return false;
        }

        return bccomp(Numeric::str($unitPrice), Numeric::str((string) $notified), self::BC_SCALE) >= 0;
    }

    private function clearLatch(Product $product): void
    {
        if ($product->unit_price_notified === null && $product->unit_price_notified_at === null) {
            return;
        }

        $product->forceFill([
            'unit_price_notified' => null,
            'unit_price_notified_at' => null,
        ])->save();
    }

    private function notify(Product $product, Shop $shop, string $unitPrice): void
    {
        // Claim the send first: two workers finishing checks together would
        // otherwise both see an unlatched product and both notify.
        $claimed = Product::query()
            ->whereKey($product->getKey())
            ->where(function (EloquentBuilder $query) use ($product): void {
                $query->whereNull('unit_price_notified')
                    ->orWhere('unit_price_notified', $product->unit_price_notified);
            })
            ->update([
                'unit_price_notified' => $unitPrice,
                'unit_price_notified_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $product->refresh();

        DB::afterCommit(function () use ($product, $shop, $unitPrice): void {
            $product->user?->notify(new UnitPriceTargetNotification($product, $shop, $unitPrice));
        });
    }
}
