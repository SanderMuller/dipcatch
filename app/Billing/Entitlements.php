<?php declare(strict_types=1);

namespace App\Billing;

use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * The single reader of the plan entitlements in `config/plans.php`. Every
 * feature asks this class what a user may do — no feature branches on the
 * plan itself, and no feature reads the Stripe subscription state directly.
 *
 * The `stripe` section of that file is pricing, not entitlement, and has
 * its own single reader in {@see ProPrice}.
 */
final readonly class Entitlements
{
    private function __construct(public Plan $plan) {}

    public static function for(User $user): self
    {
        return new self($user->plan());
    }

    public static function of(Plan $plan): self
    {
        return new self($plan);
    }

    /**
     * Null means unlimited.
     */
    public function maxProducts(): ?int
    {
        return $this->limit('max_products');
    }

    /**
     * Null means unlimited.
     */
    public function maxShopsPerProduct(): ?int
    {
        return $this->limit('max_shops_per_product');
    }

    /**
     * Unset on a plan means "whatever the app is set to" — the free plan
     * follows `dipcatch.recheck.interval_hours` rather than duplicating it.
     */
    public function recheckIntervalHours(): int
    {
        return $this->number('recheck_interval_hours', 'dipcatch.recheck.interval_hours');
    }

    public function notificationsHourlyLimit(): int
    {
        return $this->number('notifications_hourly_limit', 'dipcatch.notifications.user_hourly_limit');
    }

    /**
     * Days of price history the account may read. Null means unlimited.
     */
    public function historyDays(): ?int
    {
        return $this->limit('history_days');
    }

    public function allowsUnitPriceAlerts(): bool
    {
        return $this->value('unit_price_alerts') === true;
    }

    /** The plan half of `User::wantsAutoCategories()`. */
    public function allowsAutoCategories(): bool
    {
        return $this->value('auto_categories') === true;
    }

    private function number(string $key, string $fallbackKey): int
    {
        $value = $this->value($key);

        if (is_numeric($value)) {
            return (int) $value;
        }

        return Config::integer($fallbackKey);
    }

    private function limit(string $key): ?int
    {
        $value = $this->value($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private function value(string $key): mixed
    {
        return config("plans.{$this->plan->value}.{$key}");
    }
}
