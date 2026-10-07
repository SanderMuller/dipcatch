<?php declare(strict_types=1);

namespace App\Actions\Drops;

use App\Models\Product;
use App\Models\Shop;
use App\Models\TargetPriceEvent;
use App\Notifications\UnitPriceTargetNotification;
use App\Services\Drops\NotificationBudget;
use App\Support\ComparablePacks;
use App\Support\Numeric;
use App\Support\UnitTargetConversion;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    private const int BC_SCALE = 6;

    public function __invoke(Product $product): void
    {
        $target = $product->effectiveUnitPriceTarget();

        if ($target === null) {
            return;
        }

        $packs = $product->comparablePacks();
        $shop = $product->bestValueShop();
        // The resolver's size, not the shop's own pack columns: a shop can win
        // on the second size it states, 560 g beside its 20 pieces.
        $unitPrice = $shop === null ? null : $packs->unitPriceOf($shop);
        // Two figures on purpose: the unrounded one decides, the rounded one is
        // what the latch stores and the notification prints. Deciding on the
        // rounded one fired the alert on a shop whose true price per tablet was
        // above the target and merely rounded down onto it.
        $unitValue = $shop === null ? null : $packs->unitPriceValueOf($shop);

        if ($shop === null || $unitPrice === null || $unitValue === null) {
            $this->clearLatch($product);

            return;
        }

        if (! self::meets($unitValue, $target)) {
            // Above the target: nothing to say, and the next time it drops
            // below is worth saying again.
            $this->clearLatch($product);

            return;
        }

        $unit = $packs->unit();

        if ($unit === null || self::alreadyNotified($product, $unitPrice, $unit, $packs)) {
            return;
        }

        $this->notify($product, $shop, $unitPrice, $unit, $target);
    }

    /**
     * The latch holds while the value stays at or under the target, so a
     * shop rechecked every six hours does not send the same news four times
     * a day. A value that drops further than the one already sent is news
     * again.
     */
    private static function alreadyNotified(Product $product, string $unitPrice, string $unit, ComparablePacks $packs): bool
    {
        $notified = self::latchIn($product, $unit, $packs);

        // At the four decimals a sent price is stored and printed with, as
        // the latch itself is: a converted latch rounds the same way.
        return $notified !== null
            && bccomp(Numeric::str($unitPrice), Numeric::str(number_format($notified, 4, '.', '')), self::BC_SCALE) >= 0;
    }

    /**
     * The latch in today's unit. It is money in the unit the alert was sent
     * in, which a product changing its comparison unit leaves behind: kept as
     * it is, €0.25 a piece would hold back every alert above €0.25 a kilo.
     * Converted from the stored figure every time, so a unit going back and
     * forth never drifts it. Null — no latch — when it does not convert.
     */
    public static function latchIn(Product $product, string $unit, ComparablePacks $packs): ?float
    {
        if ($product->unit_price_notified === null) {
            return null;
        }

        $value = (float) $product->unit_price_notified;
        $latchUnit = $product->unit_price_notified_unit;

        // A latch from before units were recorded was armed in the unit the
        // product compared in then, which is the one it still had.
        if ($latchUnit === null || $latchUnit === $unit) {
            return $value;
        }

        $converted = UnitTargetConversion::convertValue($value, $latchUnit, $unit, $packs);

        return $converted !== null && UnitTargetConversion::inRange($converted) ? $converted : null;
    }

    /** Whether an unrounded unit price reaches the target. */
    public static function meets(float $unitValue, string $target): bool
    {
        return bccomp(self::precise($unitValue), Numeric::str($target), self::BC_SCALE) <= 0;
    }

    /**
     * A float rendered at a scale bccomp can read without losing the decision.
     *
     * @return numeric-string
     */
    private static function precise(float $value): string
    {
        return Numeric::str(number_format($value, self::BC_SCALE, '.', ''));
    }

    private function clearLatch(Product $product): void
    {
        if ($product->unit_price_notified === null && $product->unit_price_notified_at === null) {
            return;
        }

        $product->forceFill([
            'unit_price_notified' => null,
            'unit_price_notified_unit' => null,
            'unit_price_notified_at' => null,
        ])->save();
    }

    private function notify(Product $product, Shop $shop, string $unitPrice, string $unit, string $target): void
    {
        $user = $product->user;

        if ($user === null) {
            return;
        }

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
                'unit_price_notified_unit' => $unit,
                'unit_price_notified_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $product->refresh();

        // Before the budget: an alert the hourly ceiling holds back still
        // reaches the daily email.
        TargetPriceEvent::record(
            $product,
            $shop,
            $target,
            price: $shop->current_price === null ? null : (string) $shop->current_price,
            unitPrice: $unitPrice,
        );

        // Asked here and nowhere earlier: asking spends a slot of the hourly
        // ceiling, the limiter is cache-backed and does not roll back, and
        // `CheckShopPrice` runs this action inside a transaction.
        DB::afterCommit(function () use ($product, $shop, $unitPrice, $user, $target): void {
            try {
                if (! app(NotificationBudget::class)->allows($user)) {
                    Log::warning('Notification suppressed by hourly rate limit', [
                        'alert' => 'unit_price_target',
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                    ]);

                    return;
                }

                $user->notify(new UnitPriceTargetNotification($product, $shop, $unitPrice, $target));
            } catch (Throwable $e) {
                // This alert is already lost. The claim is committed and the
                // latch is armed, and `CheckShopPrice` sets
                // `maxExceptions = 1`, so rethrowing fails the job on the
                // first throw rather than retrying it — and a replayed check
                // would find the latch already armed anyway. Rethrowing also
                // skips every callback staged after this one, costing the
                // other alerts on the same check. `report()` keeps the trace;
                // the catch only stops the failure steering control flow.
                report($e);

                Log::error('Alert failed to send', [
                    'alert' => 'unit_price_target',
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
}
