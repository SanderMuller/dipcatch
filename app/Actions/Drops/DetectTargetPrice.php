<?php declare(strict_types=1);

namespace App\Actions\Drops;

use App\Models\Product;
use App\Models\Shop;
use App\Notifications\TargetPriceNotification;
use App\Services\Drops\NotificationBudget;
use App\Support\Numeric;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fires when the cheapest shop reaches the price the shopper asked about —
 * "tell me when this is under 18 euro".
 *
 * Not the drop engine, and not the unit-price target. The drop engine
 * measures a fall against the product's own 30-day median and answers "is
 * this unusually cheap"; this answers "is this cheap enough for me", which
 * needs no history. It sits beside {@see DetectUnitPriceTarget} rather than
 * inside it because a pack price and a price per kilo are different
 * questions, and a shop publishing no pack size can only answer this one.
 */
final readonly class DetectTargetPrice
{
    private const int BC_SCALE = 4;

    public function __invoke(Product $product): void
    {
        $target = $product->target_price;

        if ($target === null) {
            return;
        }

        $shop = $product->cheapestShop;
        $price = $product->cheapest_price;

        if ($shop === null || $price === null) {
            $this->clearLatch($product);

            return;
        }

        if (bccomp(Numeric::str((string) $price), Numeric::str((string) $target), self::BC_SCALE) > 0) {
            // Above the target: nothing to say, and the next time it falls
            // below is worth saying again.
            $this->clearLatch($product);

            return;
        }

        if ($this->alreadyNotified($product, (string) $price)) {
            return;
        }

        $this->notify($product, $shop, (string) $price);
    }

    /**
     * The latch holds while the price stays at or under the target, so a
     * product rechecked every few hours does not send the same news again.
     * A price lower than the one already sent is news again.
     */
    private function alreadyNotified(Product $product, string $price): bool
    {
        $notified = $product->target_price_notified;

        if ($notified === null) {
            return false;
        }

        return bccomp(Numeric::str($price), Numeric::str((string) $notified), self::BC_SCALE) >= 0;
    }

    private function clearLatch(Product $product): void
    {
        if ($product->target_price_notified === null && $product->target_price_notified_at === null) {
            return;
        }

        $product->forceFill([
            'target_price_notified' => null,
            'target_price_notified_at' => null,
        ])->save();
    }

    private function notify(Product $product, Shop $shop, string $price): void
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
                $query->whereNull('target_price_notified')
                    ->orWhere('target_price_notified', $product->target_price_notified);
            })
            ->update([
                'target_price_notified' => $price,
                'target_price_notified_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $product->refresh();

        // Asked here and nowhere earlier: asking spends a slot of the hourly
        // ceiling, the limiter is cache-backed and does not roll back, and
        // `CheckShopPrice` runs this action inside a transaction.
        DB::afterCommit(function () use ($product, $shop, $price, $user): void {
            try {
                if (! app(NotificationBudget::class)->allows($user)) {
                    Log::warning('Notification suppressed by hourly rate limit', [
                        'alert' => 'target_price',
                        'user_id' => $user->id,
                        'product_id' => $product->id,
                    ]);

                    return;
                }

                $user->notify(new TargetPriceNotification($product, $shop, $price));
            } catch (Throwable $e) {
                // This alert is already lost. The claim is committed, the
                // latch is armed, and the job retry finds nothing to
                // re-detect — so rethrowing recovers nothing. It would also
                // skip every callback staged after this one, costing the
                // other alerts on the same check. `report()` keeps the trace;
                // the catch only stops the failure steering control flow.
                report($e);

                Log::warning('Alert failed to send', [
                    'alert' => 'target_price',
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
}
