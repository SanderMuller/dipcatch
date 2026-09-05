<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Plan;
use App\Billing\ProPrice;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
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

        // Not `isPro()`: a lost chargeback deliberately makes that false
        // while Stripe still bills an active subscription. Selling a second
        // one would charge the customer twice.
        if ($user->subscription(Plan::SUBSCRIPTION_TYPE)?->valid() === true) {
            return redirect('/app/billing');
        }

        if ($user->billing_blocked_at !== null) {
            return $this->failed('This account is on hold after a chargeback. Contact support to reopen it.');
        }

        // A double-click must not open two Checkout sessions: Stripe writes
        // no local row until the webhook lands, so nothing above can see a
        // session already in flight.
        $lock = Cache::lock('billing:checkout:' . $user->id, 30);

        if (! $lock->get()) {
            return redirect('/app/billing');
        }

        try {
            $open = $this->resumeOpenSession($user);

            if ($open !== null) {
                return redirect($open);
            }

            return $this->startCheckout($user, $priceId);
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

    private function startCheckout(User $user, string $priceId): Checkout
    {
        $subscription = $user->newSubscription(Plan::SUBSCRIPTION_TYPE, $priceId);

        if (ProPrice::trialDays() > 0 && $user->qualifiesForTrial()) {
            $subscription->trialDays(ProPrice::trialDays());
        }

        $checkout = $subscription->checkout([
            'success_url' => url('/app/billing?checkout=done'),
            'cancel_url' => url('/app/billing?checkout=cancelled'),
        ]);

        $user->forceFill([
            'stripe_checkout_session_id' => $checkout->asStripeCheckoutSession()->id,
        ])->save();

        return $checkout;
    }

    /**
     * The URL of a Checkout session this account already has open, if there
     * is one. The lock above only survives 30 seconds, while a session lives
     * for hours — without this, a customer who comes back later could
     * complete two sessions and be billed twice.
     */
    private function resumeOpenSession(User $user): ?string
    {
        $sessionId = $user->stripe_checkout_session_id;

        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        // A session Stripe cannot tell us about is not a reason to refuse a
        // customer: fall through and start a fresh one.
        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, []);
        } catch (Throwable) {
            return null;
        }

        return $session->status === 'open' && is_string($session->url)
            ? $session->url
            : null;
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
