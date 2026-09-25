<?php declare(strict_types=1);

namespace App\Actions\Drops;

use App\Jobs\CheckShopPrice;
use App\Models\PriceCheck;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\DropEvaluator;
use App\Services\Drops\DropLatch;
use App\Services\Drops\DropOutcome;
use App\Services\Drops\LargeDropConfirmation;
use App\Services\Drops\LargeDropVerdict;
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
        private DropLatch $latch,
        private LargeDropConfirmation $confirmation,
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
        $ref = $reference ?? $this->reference->compute($product);

        if ($ref === null) {
            return;
        }

        $newPrice = $product->dropBasisPrice($ref->unit);
        $newPackPrice = $product->winningPackPrice($ref->unit);

        if ($newPrice === null) {
            // A product that compares per unit and has no winner to compare
            // cannot have a drop detected, and that silence is the failure this
            // work exists to remove. The resolver should make this unreachable
            // — a comparison unit is the majority unit among the *eligible*
            // shops that state one, so one of them is always winnable — so a
            // line here means that invariant broke.
            if ($ref->isUnitBasis()) {
                Log::warning('Drop detection skipped: no shop to measure per unit', [
                    'alert' => 'price_drop',
                    'product_id' => $product->id,
                    'comparison_unit' => $ref->unit,
                    'best_value_shop_id' => $product->best_value_shop_id,
                    'cheapest_shop_id' => $product->cheapest_shop_id,
                ]);
            }

            return;
        }

        $outcome = $this->evaluator->evaluate($product, $newPrice, $ref, $newPackPrice);

        if (! $outcome->belowThreshold) {
            return;
        }

        // A large drop belongs to confirmLargeDrop(), which the recompute
        // calls before its own `$changed` gate. Notifying here as well would
        // send the alert on one reading — the thing this guard exists to stop.
        if ($outcome->needsConfirmation) {
            return;
        }

        if ($this->triggerStartsAShop($triggeringPriceCheckId)) {
            return;
        }

        $this->triggerNotificationAtomically($product, $newPrice, $outcome, $triggeringPriceCheckId, $ref->unit);
    }

    /**
     * True when the reading that moved the price is a shop's first.
     *
     * A drop is a price falling at shops we were already watching. Adding a
     * shop that happens to be cheaper moves `cheapest_price` too, and the
     * reference still describes the shops from before it existed, so the
     * difference reads as a fall of whatever the new shop undercuts by. Three
     * of four alerts in one digest were this — a 227 g bag of Twix reported as
     * 34% off against a 333 g one, on the second the smaller shop was added.
     *
     * A caller with no trigger — a test, a manual recompute — cannot be judged
     * this way and is left alone.
     */
    private function triggerStartsAShop(?int $triggeringPriceCheckId): bool
    {
        $trigger = $triggeringPriceCheckId === null
            ? null
            : PriceCheck::query()->find($triggeringPriceCheckId);

        return $trigger?->joinsAProductAlreadyWatchedElsewhere() === true;
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
        $outcome = $this->evaluator->evaluate($product, $newPrice, $reference, $product->winningPackPrice($reference->unit));

        if (! $outcome->belowThreshold || ! $outcome->needsConfirmation) {
            return;
        }

        $trigger = PriceCheck::query()->find($triggeringPriceCheckId);

        if ($trigger === null) {
            return;
        }

        // Exhaustive on purpose: a verdict nobody handled must not fall
        // through to an alert on one reading.
        match ($this->confirmation->verdictFor($product, $trigger, $reference)) {
            LargeDropVerdict::NotThisReading => null,
            LargeDropVerdict::Exempt, LargeDropVerdict::Confirmed => $this->triggerNotificationAtomically($product, $newPrice, $outcome, $triggeringPriceCheckId, $reference->unit),
            LargeDropVerdict::Awaiting => $this->askForSecondReading($trigger->shop),
        };
    }

    /**
     * This reading is the transition. Ask for a second one now rather than
     * waiting for the shop's next scheduled check.
     */
    private function askForSecondReading(Shop $shop): void
    {
        DB::afterCommit(function () use ($shop): void {
            try {
                dispatch(new CheckShopPrice($shop, confirmation: true))
                    ->delay(now()->addMinutes(Config::integer('dipcatch.drops.confirm_delay_minutes')));
            } catch (Throwable $e) {
                // `CheckShopPrice` stages this callback before the unit-price
                // and target-price ones for the same check, so a rethrow
                // costs both of those alerts on top of this dispatch — and
                // `maxExceptions = 1` means it fails the job outright rather
                // than retrying, so it buys no recovery either.
                //
                // Unlike the sends, nothing is lost for good here: no alert
                // was due on this reading, and the shop's next scheduled
                // check re-enters this path with this reading as the
                // predecessor, which qualifies as a large drop in its own
                // right. The confirmation is delayed to that check rather
                // than skipped. `report()` keeps the trace.
                report($e);

                Log::error('Confirmation check failed to dispatch', [
                    'alert' => 'price_drop',
                    'shop_id' => $shop->id,
                    'product_id' => $shop->product_id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    /** @see DropLatch::clearIfRecovered() — kept here as the caller's entry point. */
    public function clearLatchIfRecovered(Product $product, ?string $newPrice, ?ReferenceValue $reference): void
    {
        $this->latch->clearIfRecovered($product, $newPrice, $reference);
    }

    private function triggerNotificationAtomically(
        Product $product,
        string $newPrice,
        DropOutcome $outcome,
        ?int $triggeringPriceCheckId,
        ?string $unit,
    ): void {
        DB::transaction(function () use ($product, $newPrice, $outcome, $triggeringPriceCheckId, $unit): void {
            $locked = Product::query()->lockForUpdate()->find($product->id);

            if ($locked === null) {
                return;
            }

            if (! $this->latch->shouldNotify($locked, $newPrice, $unit)) {
                return;
            }

            // Everything that can abandon the send is resolved before the
            // latch is armed, so an armed latch always has an event row behind
            // it. Armed without one, the product would suppress every later
            // drop at or above this price for an alert nobody received — and a
            // plain `return` inside this closure commits.
            $winningPack = $locked->winningPackPrice($unit);
            $triggerCheck = $this->resolveTriggerCheck($locked, $triggeringPriceCheckId, $winningPack ?? $newPrice);

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
                    'best_value_shop_id' => $locked->best_value_shop_id,
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
                'last_notified_unit' => $unit,
            ])->save();

            $event = PriceDropEvent::create([
                'product_id' => $locked->id,
                'user_id' => $locked->user_id,
                'price_check_id' => $triggerCheck->id,
                'triggered_by_shop_id' => $triggerCheck->shop_id,
                'currency' => $locked->currency,
                'reference_price' => $outcome->referencePrice,
                'reference_kind' => $outcome->referenceKind,
                // Always the pack price of the winning shop — the till figure.
                // The basis price is a price per unit whenever `$unit` is set,
                // and it has its own column rather than being squeezed in here.
                'new_price' => $winningPack ?? $newPrice,
                'drop_pct' => $outcome->dropPercent,
                'drop_abs' => $outcome->dropAbsolute,
                'reference_unit_price' => $outcome->referenceUnitPrice,
                'new_unit_price' => $outcome->newUnitPrice,
                'comparison_unit' => $outcome->comparisonUnit,
                // The size the unit price was measured on, for "€2.75 for 227 g".
                'pack_quantity' => $unit === null ? null : $locked->bestValuePackSize()?->quantity,
                'pack_unit' => $unit === null ? null : $locked->bestValuePackSize()?->unit,
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
     *
     * The fallback answers "which check read this price on this shop", so it
     * applies three of the guards {@see LargeDropConfirmation::verdictFor()}
     * applies to an explicit id: the right shop, an eligible reading, and the
     * drop's own price. The explicit branch needs none of them here — the
     * direct path reaches this only through the recompute's `$changed` gate,
     * so the id is the check that moved the price. `confirmLargeDrop()` runs
     * before that gate, which is why it runs the verdict's guards.
     *
     * Without the eligibility filter the latest row on a revived shop is
     * typically the failure that killed it, and the event would anchor to a
     * check that read nothing — the digest reads bundle terms and the struck
     * regular price straight off this row.
     */
    private function resolveTriggerCheck(Product $product, ?int $triggeringPriceCheckId, string $newPrice): ?PriceCheck
    {
        if ($triggeringPriceCheckId !== null) {
            return PriceCheck::query()->find($triggeringPriceCheckId);
        }

        $cheapestOfferId = $product->best_value_shop_id ?? $product->cheapest_shop_id;

        if ($cheapestOfferId === null) {
            return null;
        }

        $candidate = PriceCheck::query()
            ->where('shop_id', $cheapestOfferId)
            ->eligible()
            ->latest('checked_at')
            ->first();

        if ($candidate === null) {
            return null;
        }

        // Compared with bccomp rather than in SQL: these are decimals, and
        // '10.0' and '10.00' are the same price.
        return bccomp(Numeric::str((string) $candidate->price), Numeric::str($newPrice), self::BC_SCALE) === 0
            ? $candidate
            : null;
    }

    private function withinHourlyLimit(User $user): bool
    {
        return app(NotificationBudget::class)->allows($user);
    }
}
