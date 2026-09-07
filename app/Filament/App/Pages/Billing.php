<?php declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Billing\BillingGate;
use App\Billing\Entitlements;
use App\Billing\Plan;
use App\Billing\PlanLimits;
use App\Billing\ProPrice;
use App\Models\Product;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The customer's own billing screen: which plan they are on, how much of it
 * they are using, and the two buttons that matter — upgrade, and manage the
 * card in Stripe's portal.
 *
 * Usage is shown before a limit is hit, so the wall is never a surprise.
 */
class Billing extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::CreditCard;

    protected static ?string $navigationLabel = 'Plan & billing';

    protected static ?string $title = 'Plan & billing';

    protected static ?string $slug = 'billing';

    protected string $view = 'filament.app.pages.billing';

    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function entitlements(): Entitlements
    {
        return $this->user()->entitlements();
    }

    public function productCount(): int
    {
        return Product::query()->where('user_id', $this->user()->id)->count();
    }

    public function remainingProducts(): ?int
    {
        return app(PlanLimits::class)->remainingProducts($this->user());
    }

    /**
     * The renewal or expiry date to show. Null when there is nothing dated
     * to say — a free account with no history.
     */
    public function periodEndsAt(): ?string
    {
        $subscription = $this->user()->subscription(Plan::SUBSCRIPTION_TYPE);

        if ($subscription === null) {
            return null;
        }

        $date = $subscription->ends_at ?? $subscription->trial_ends_at;

        return $date?->timezone($this->user()->timezone ?: 'Europe/Amsterdam')->isoFormat('D MMMM YYYY');
    }

    /**
     * Pro without paying for it. `isOnTrial()` reads the subscription's trial
     * and so is false here, which is why a comped account otherwise falls
     * through to the "per month" line and gets told it is being billed.
     */
    public function isComped(): bool
    {
        return $this->user()->isComped();
    }

    public function isCancelling(): bool
    {
        return $this->user()->subscription(Plan::SUBSCRIPTION_TYPE)?->onGracePeriod() === true;
    }

    public function isOnTrial(): bool
    {
        return $this->user()->subscription(Plan::SUBSCRIPTION_TYPE)?->onTrial() === true;
    }

    public function isBlocked(): bool
    {
        return $this->user()->billing_blocked_at !== null;
    }

    public function priceLabel(): string
    {
        return ProPrice::label();
    }

    public function trialDays(): int
    {
        return ProPrice::trialDays();
    }

    /**
     * A former subscriber gets no second free fortnight, so the button must
     * not promise one — checkout would create a paid subscription instead.
     */
    public function offersTrial(): bool
    {
        return ProPrice::trialDays() > 0 && $this->user()->qualifiesForTrial();
    }

    public function canUpgrade(): bool
    {
        return BillingGate::isOpen() && ! $this->isBlocked();
    }

    /**
     * Anyone Stripe knows about can reach the portal, whatever their plan
     * says. A lost chargeback drops the account to Free while Stripe keeps
     * billing the subscription — hiding the portal there would leave
     * someone paying with no way to stop.
     */
    public function canManageBilling(): bool
    {
        return $this->user()->stripe_id !== null;
    }
}
