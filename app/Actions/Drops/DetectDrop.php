<?php declare(strict_types=1);

namespace App\Actions\Drops;

use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\Drops\DropEvaluator;
use App\Services\Drops\DropOutcome;
use App\Services\Drops\NotificationBudget;
use App\Services\Drops\Reference;
use App\Services\Drops\ReferenceValue;
use App\Support\Numeric;
use Illuminate\Support\Facades\Config;
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

        // A large drop belongs to confirmLargeDrop(), which the recompute
        // calls before its own `$changed` gate. Notifying here as well would
        // send the alert on one reading — the thing this guard exists to stop.
        if ($outcome->needsConfirmation) {
            return;
        }

        $this->triggerNotificationAtomically($product, $newPrice, $outcome, $triggeringPriceCheckId);
    }

    /**
     * Decide a large drop — one at or past `drops.confirm_above_pct` below the
     * reference. Such a drop is what a mis-extraction looks like: a unit price,
     * a "from" price, another variant. It notifies only when the shop's
     * previous eligible reading agreed, so one bad read cannot mail anyone.
     *
     * Called from `recomputeCheapestShop()` inside the product row lock and
     * **before** its `$changed` early return: the confirming reading is the
     * same price again, so it trips neither that gate nor the `down`
     * direction, and `__invoke()` would never see it.
     *
     * Nothing is written. The second opinion is the shop's own price-check
     * history, so a swallowed or failed confirmation job costs nothing — the
     * next scheduled check is an equally valid second reading.
     */
    public function confirmLargeDrop(
        Product $product,
        string $newPrice,
        ?int $triggeringPriceCheckId,
        ReferenceValue $reference,
    ): void {
        if ($triggeringPriceCheckId === null) {
            return;
        }

        // Evaluated before anything is loaded: this runs on every recompute,
        // inside the product row lock, and an ordinary check must leave the
        // critical section without a further query.
        $outcome = $this->evaluator->evaluate($product, $newPrice, $reference);

        if (! $outcome->belowThreshold || ! $outcome->needsConfirmation) {
            return;
        }

        $trigger = PriceCheck::query()->find($triggeringPriceCheckId);

        // `CheckShopPrice::persist()` recomputes on every outcome, a failure
        // included, and a failed check leaves the shop's price cached. Without
        // this the failure would re-evaluate that cached drop and notify,
        // anchored to a check that read nothing.
        if ($trigger === null || ! $trigger->isEligible()) {
            return;
        }

        // The drop must belong to the shop this check read.
        if ($trigger->shop_id !== $product->cheapest_shop_id) {
            return;
        }

        if (bccomp(Numeric::str((string) $trigger->price), Numeric::str($newPrice), self::BC_SCALE) !== 0) {
            return;
        }

        // A dataset or API shop reads a structured field and never fetches a
        // page, so a second reading is the same row again and confirms
        // nothing. Those shops alert on one reading, as they always have.
        if ($this->readsWithoutFetching($trigger->shop)) {
            $this->triggerNotificationAtomically($product, $newPrice, $outcome, $triggeringPriceCheckId);

            return;
        }

        $previous = PriceCheck::query()
            ->where('shop_id', $trigger->shop_id)
            ->where('id', '<', $trigger->id)
            ->eligible()
            ->latest('id')
            ->first();

        // The predecessor has to be a large drop in its own right. A merely
        // discounted one — below the notify threshold but above the
        // confirmation ceiling — would let a single anomalous reading through
        // on its coat-tails, which is the whole failure this guard exists for.
        if ($previous !== null && $this->qualifiesAsLargeDrop($product, (string) $previous->price, $reference)) {
            $this->triggerNotificationAtomically($product, $newPrice, $outcome, $triggeringPriceCheckId);

            return;
        }

        // This reading is the transition. Ask for a second one now rather than
        // waiting for the shop's next scheduled check.
        $shop = $trigger->shop;

        DB::afterCommit(function () use ($shop): void {
            CheckShopPrice::dispatch($shop, confirmation: true)
                ->delay(now()->addMinutes(Config::integer('dipcatch.drops.confirm_delay_minutes')));
        });
    }

    private function qualifiesAsLargeDrop(Product $product, string $price, ReferenceValue $reference): bool
    {
        $outcome = $this->evaluator->evaluate($product, $price, $reference);

        return $outcome->belowThreshold && $outcome->needsConfirmation;
    }

    private function readsWithoutFetching(Shop $shop): bool
    {
        return app(AhApiSource::class)->supports($shop->host)
            || app(CheckjebonSource::class)->supports($shop->host);
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

            // Everything that can abandon the send is resolved before the
            // latch is armed, so an armed latch always has an event row behind
            // it. Armed without one, the product would suppress every later
            // drop at or above this price for an alert nobody received — and a
            // plain `return` inside this closure commits.
            $triggerCheck = $this->resolveTriggerCheck($locked, $triggeringPriceCheckId);

            if ($triggerCheck === null) {
                // The one branch here that abandons a real drop. Nothing is
                // written, and `recomputeCheapestShop()` has already moved
                // `cheapest_price`, so the next check at this price fails the
                // `$changed` gate and never reaches detection again. Without a
                // line here the alert is lost with no trace.
                Log::warning('Drop abandoned: no price check to anchor it to', [
                    'alert' => 'price_drop',
                    'product_id' => $locked->id,
                    'cheapest_shop_id' => $locked->cheapest_shop_id,
                    'triggering_price_check_id' => $triggeringPriceCheckId,
                    'new_price' => $newPrice,
                ]);

                return;
            }

            $user = $locked->user;

            if ($user === null) {
                return;
            }

            $locked->forceFill([
                'last_notified_price' => $newPrice,
                'last_notified_at' => now(),
            ])->save();

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

            // The event row belongs inside the transaction; the budget and the
            // send do not. Asking spends a slot of the hourly ceiling, and the
            // limiter is cache-backed — it does not roll back.
            DB::afterCommit(function () use ($locked, $outcome, $event, $user, $triggerCheck): void {
                try {
                    if ($this->withinHourlyLimit($user)) {
                        $user->notify(new PriceDropNotification($locked, $outcome, $event->id, $triggerCheck));
                    } else {
                        Log::warning('Notification suppressed by hourly rate limit', [
                            'alert' => 'price_drop',
                            'user_id' => $user->id,
                            'product_id' => $locked->id,
                            'price_drop_event_id' => $event->id,
                        ]);
                    }
                } catch (Throwable $e) {
                    // This alert is already lost. The row is committed and the
                    // latch is armed, and `CheckShopPrice` sets
                    // `maxExceptions = 1`, so rethrowing fails the job on the
                    // first throw rather than retrying it — and a replayed
                    // check would find no price change to re-detect anyway.
                    // Rethrowing also skips every callback staged after this
                    // one, which is the unit-price and target-price alert for
                    // the same check. `report()` keeps the trace; the catch
                    // only stops the failure steering control flow.
                    report($e);

                    Log::error('Alert failed to send', [
                        'alert' => 'price_drop',
                        'user_id' => $user->id,
                        'product_id' => $locked->id,
                        'price_drop_event_id' => $event->id,
                        'exception' => $e->getMessage(),
                    ]);
                }
            });
        });
    }

    /**
     * Prefer the explicit triggering check id passed in by the recompute
     * caller. Fall back to the latest check on the cheapest offer for the
     * callers that carry none: saving or removing a shop from the product
     * page, the MCP remove-shop tool, and the admin revive action, which
     * passes the shop's latest successful check and has none to pass when
     * every `ok` row has been pruned.
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
