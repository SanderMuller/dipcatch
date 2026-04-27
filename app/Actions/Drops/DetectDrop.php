<?php declare(strict_types=1);

namespace App\Actions\Drops;

use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Services\Drops\DropEvaluator;
use App\Services\Drops\DropOutcome;
use App\Services\Drops\Reference;
use App\Services\Drops\ReferenceValue;
use App\Support\Config as DipConfig;
use App\Support\Numeric;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final readonly class DetectDrop
{
    private const int BC_SCALE = 4;

    public function __construct(
        private Reference $reference,
        private DropEvaluator $evaluator,
    ) {}

    public function __invoke(Product $product): void
    {
        if ($product->last_price === null) {
            return;
        }

        $newPrice = (string) $product->last_price;

        $ref = $this->reference->compute($product);

        if ($ref === null) {
            return;
        }

        $outcome = $this->evaluator->evaluate($product, $newPrice, $ref);

        if ($this->isRecovered($newPrice, $ref)) {
            $this->clearLatchAtomically($product);

            return;
        }

        if (! $outcome->belowThreshold) {
            return;
        }

        // Re-check latch + write event + dispatch notification under a row
        // lock so concurrent scrapes for the same product can't both fire.
        $this->triggerNotificationAtomically($product, $newPrice, $outcome);
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
        if ($product->last_notified_price === null && $product->last_notified_at === null) {
            return;
        }

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

    private function triggerNotificationAtomically(Product $product, string $newPrice, DropOutcome $outcome): void
    {
        DB::transaction(function () use ($product, $newPrice, $outcome): void {
            $locked = Product::query()->lockForUpdate()->find($product->id);

            if ($locked === null) {
                return;
            }

            // Re-evaluate against the freshly locked row — another worker may
            // already have written a lower latch value while we were waiting.
            if (! $this->shouldNotify($locked, $newPrice)) {
                return;
            }

            $locked->forceFill([
                'last_notified_price' => $newPrice,
                'last_notified_at' => now(),
            ])->save();

            $triggerCheck = $locked->priceChecks()->latest('checked_at')->first();

            if ($triggerCheck === null) {
                return;
            }

            $event = PriceDropEvent::create([
                'product_id' => $locked->id,
                'user_id' => $locked->user_id,
                'price_check_id' => $triggerCheck->id,
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

            if ($this->withinHourlyLimit($user)) {
                $user->notify(new PriceDropNotification($locked, $outcome, $event->id));
            } else {
                Log::warning('Notification suppressed by hourly rate limit', [
                    'user_id' => $user->id,
                    'product_id' => $locked->id,
                    'price_drop_event_id' => $event->id,
                ]);
            }
        });
    }

    /**
     * Per-user hourly rate limit. Returns true if a slot is consumed (notification
     * may proceed); false when the user is over the configured threshold.
     */
    private function withinHourlyLimit(User $user): bool
    {
        $limit = DipConfig::int('dipcatch.notifications.user_hourly_limit', 30);

        if ($limit <= 0) {
            return true;
        }

        $key = "notify:user:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return false;
        }

        RateLimiter::hit($key, decaySeconds: 3600);

        return true;
    }
}
