<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Plan;
use App\Models\User;
use App\Support\Config as ConfigHelper;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Checkout;
use Throwable;

/**
 * The two round trips to Stripe. Card details never reach this app —
 * Checkout collects them, the Billing Portal changes them.
 */
class BillingController extends Controller
{
    public function checkout(): Checkout|RedirectResponse
    {
        $user = $this->user();
        // The key exists but is null until a Stripe price is configured.
        $priceId = config('plans.stripe.pro_price_id');
        $priceId = is_string($priceId) ? $priceId : '';

        if ($priceId === '') {
            return $this->failed('Checkout is not configured yet. No price has been set.');
        }

        if ($user->isPro()) {
            return redirect('/app/billing');
        }

        $trialDays = ConfigHelper::int('plans.stripe.trial_days', 0);

        try {
            $subscription = $user->newSubscription(Plan::SUBSCRIPTION_TYPE, $priceId);

            if ($trialDays > 0 && ! $user->hasEverSubscribedTo(Plan::SUBSCRIPTION_TYPE)) {
                $subscription->trialDays($trialDays);
            }

            return $subscription->checkout([
                'success_url' => url('/app/billing?checkout=done'),
                'cancel_url' => url('/app/billing?checkout=cancelled'),
            ]);
        } catch (Throwable) {
            // Stripe is down, the key is wrong, the price was deleted. The
            // customer gets a way forward instead of a 500.
            return $this->failed('Stripe could not start the checkout. Please try again in a moment.');
        }
    }

    public function portal(): RedirectResponse
    {
        $user = $this->user();

        if ($user->stripe_id === null) {
            return $this->failed('There is nothing to manage yet — no subscription has been started.');
        }

        try {
            return $user->redirectToBillingPortal(url('/app/billing'));
        } catch (Throwable) {
            return $this->failed('Stripe could not open the billing portal. Please try again in a moment.');
        }
    }

    private function failed(string $message): RedirectResponse
    {
        Notification::make()
            ->danger()
            ->title('Billing unavailable')
            ->body($message)
            ->persistent()
            ->send();

        return redirect('/app/billing');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
