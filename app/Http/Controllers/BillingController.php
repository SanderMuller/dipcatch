<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Plan;
use App\Billing\ProPrice;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
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
        $priceId = ProPrice::priceId();

        if ($priceId === '') {
            return $this->failed('Checkout is not configured yet. No price has been set.');
        }

        if ($user->isPro()) {
            return redirect('/app/billing');
        }

        // A double-click must not open two Checkout sessions: Stripe writes
        // no local row until the webhook lands, so the isPro() check above
        // cannot see a session already in flight. Completing both would
        // charge the customer twice.
        $lock = Cache::lock('billing:checkout:' . $user->id, 30);

        if (! $lock->get()) {
            return redirect('/app/billing');
        }

        $trialDays = ProPrice::trialDays();

        try {
            $subscription = $user->newSubscription(Plan::SUBSCRIPTION_TYPE, $priceId);

            if ($trialDays > 0 && $user->qualifiesForTrial()) {
                $subscription->trialDays($trialDays);
            }

            return $subscription->checkout([
                'success_url' => url('/app/billing?checkout=done'),
                'cancel_url' => url('/app/billing?checkout=cancelled'),
            ]);
        } catch (Throwable) {
            // Stripe down, wrong key, deleted price: the customer gets a
            // way forward instead of a 500.
            $lock->release();

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
