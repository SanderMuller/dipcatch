<?php declare(strict_types=1);

namespace App\Actions\Drops;

use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\DropEvaluator;
use App\Services\Drops\DropOutcome;
use App\Services\Drops\NotificationBudget;
use App\Services\Drops\Reference;
use App\Services\Drops\ReferenceValue;
use App\Support\Numeric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class DetectDrop
{
    private const int BC_SCALE = 4;

    public function __construct(
        private Reference $reference,
        private DropEvaluator $evaluator,
    ) {}

    /**
     * Invoked from `Product::recomputeCheapestShop()` when the cheapest price
     * has decreased. `$triggeringPriceCheckId` is the id of the freshly-inserted
     * price_check row that caused the drop — it becomes the event's anchor
     * (no `latest('checked_at')` lookup; that was racy under concurrent jobs).
     *
     * `$reference` is the value the caller already computed before it took the
     * row lock. Passing it keeps the 30-day window read out of the critical
     * section. Two cases still fall through to computing it here: a caller
     * that holds no reference (a test, a manual trigger), and a product whose
     * pre-lock compute returned null because it has no history segment yet.
     * The second is a product's first-ever recompute, where the reference is
     * the new price and nothing fires.
     */
    public function __invoke(Product $product, ?int $triggeringPriceCheckId, ?ReferenceValue $reference = null): void
    {
        if ($product->cheapest_price === null) {
            return;
        }

        $newPrice = (string) $product->cheapest_price;

        $ref = $reference ?? $this->reference->compute($product);

        if ($ref === null) {
            return;
        }

        $outcome = $this->evaluator->evaluate($product, $newPrice, $ref);

        if (! $outcome->belowThreshold) {
            return;
        }

        $this->triggerNotificationAtomically($product, $newPrice, $outcome, $triggeringPriceCheckId);
    }

    /**
     * Clear `last_notified_price` / `last_notified_at` when the new cheapest
     * is at or above the reference (recovered). Called from
     * `recomputeCheapestShop()` on upward / null-cheapest moves so the latch
     * doesn't get stuck after the original cheapest offer goes out of stock.
     */
    public function clearLatchIfRecovered(Product $product, ?string $newPrice, ?ReferenceValue $reference): void
    {
        if ($product->last_notified_price === null && $product->last_notified_at === null) {
            return;
        }

        // Null cheapest = no eligible offer; treat as "recovered" (nothing to
        // compare against, latch should not stay armed indefinitely).
        if ($newPrice === null || $reference === null) {
            $this->clearLatchAtomically($product);

            return;
        }

        if ($this->isRecovered($newPrice, $reference)) {
            $this->clearLatchAtomically($product);
        }
    }

    private function isRecovered(string $newPrice, ReferenceValue $ref): bool
    {
        return bccomp(Numeric::str($newPrice), Numeric::str($ref->value), self::BC_SCALE) >= 0;
    }

    private function shouldNotify(Product $locked, string $newPrice): bool
    {
        if ($locked->last_notified_price === null) {
            return true;
        }

        return bccomp(Numeric::str($newPrice), Numeric::str((string) $locked->last_notified_price), self::BC_SCALE) < 0;
    }

    private function clearLatchAtomically(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = Product::query()->lockForUpdate()->find($product->id);

            if ($locked === null) {
                return;
            }

            if ($locked->last_notified_price === null && $locked->last_notified_at === null) {
                return;
            }

            $locked->forceFill([
                'last_notified_price' => null,
                'last_notified_at' => null,
            ])->save();
        });
    }

    private function triggerNotificationAtomically(
        Product $product,
        string $newPrice,
        DropOutcome $outcome,
        ?int $triggeringPriceCheckId,
    ): void {
        DB::transaction(function () use ($product, $newPrice, $outcome, $triggeringPriceCheckId): void {
            $locked = Product::query()->lockForUpdate()->find($product->id);

            if ($locked === null) {
                return;
            }

            if (! $this->shouldNotify($locked, $newPrice)) {
                return;
            }

            $locked->forceFill([
                'last_notified_price' => $newPrice,
                'last_notified_at' => now(),
            ])->save();

            $triggerCheck = $this->resolveTriggerCheck($locked, $triggeringPriceCheckId);

            if ($triggerCheck === null) {
                return;
            }

            $event = PriceDropEvent::create([
                'product_id' => $locked->id,
                'user_id' => $locked->user_id,
                'price_check_id' => $triggerCheck->id,
                'triggered_by_shop_id' => $triggerCheck->shop_id,
                'currency' => $locked->currency,
                'reference_price' => $outcome->referencePrice,
                'reference_kind' => $outcome->referenceKind,
                'new_price' => $newPrice,
                'drop_pct' => $outcome->dropPercent,
                'drop_abs' => $outcome->dropAbsolute,
                'fired_at' => now(),
            ]);

            $user = $locked->user;
            if ($user === null) {
                return;
            }

            // The event row belongs inside the transaction; the budget and the
            // send do not. Asking spends a slot of the hourly ceiling, and the
            // limiter is cache-backed — it does not roll back.
            DB::afterCommit(function () use ($locked, $outcome, $event, $user): void {
                try {
                    if ($this->withinHourlyLimit($user)) {
                        $user->notify(new PriceDropNotification($locked, $outcome, $event->id));
                    } else {
                        Log::warning('Notification suppressed by hourly rate limit', [
                            'alert' => 'price_drop',
                            'user_id' => $user->id,
                            'product_id' => $locked->id,
                            'price_drop_event_id' => $event->id,
                        ]);
                    }
                } catch (Throwable $e) {
                    // This alert is already lost. The row is committed, the
                    // latch is armed, and the job retry finds no price change
                    // to re-detect — so rethrowing recovers nothing. It would
                    // also skip every callback staged after this one, which
                    // is the unit-price and target-price alert for the same
                    // check. `report()` keeps the trace; the catch only stops
                    // the failure steering control flow.
                    report($e);

                    Log::warning('Alert failed to send', [
                        'alert' => 'price_drop',
                        'user_id' => $user->id,
                        'product_id' => $locked->id,
                        'exception' => $e->getMessage(),
                    ]);
                }
            });
        });
    }

    /**
     * Prefer the explicit triggering check id passed in by the recompute
     * caller. Fall back to the latest check on the cheapest offer for paths
     * that don't carry an explicit id (e.g. tests, manual triggers).
     */
    private function resolveTriggerCheck(Product $product, ?int $triggeringPriceCheckId): ?PriceCheck
    {
        if ($triggeringPriceCheckId !== null) {
            return PriceCheck::query()->find($triggeringPriceCheckId);
        }

        $cheapestOfferId = $product->cheapest_shop_id;

        if ($cheapestOfferId === null) {
            return null;
        }

        return PriceCheck::query()
            ->where('shop_id', $cheapestOfferId)
            ->latest('checked_at')
            ->first();
    }

    private function withinHourlyLimit(User $user): bool
    {
        return app(NotificationBudget::class)->allows($user);
    }
}
