<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\BillingGate;
use App\Billing\CheckoutSessions;
use App\Billing\Plan;
use App\Billing\ProPrice;
use App\Billing\StripeTax;
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
    /**
     * The one link the marketing pages point Pro at, for signed-in visitors
     * and strangers alike.
     *
     * A stranger is sent to registration with the billing page recorded as
     * the intended URL. Fortify's register, login and email-verification
     * responses all redirect through `intended()`, so the intent survives
     * the whole signup — without this the CTA landed them on the dashboard
     * with nothing to say why they were there.
     */
    public function upgrade(): RedirectResponse
    {
        if (! auth()->check()) {
            session()->put('url.intended', url('/app/billing'));

            return redirect()->route('register');
        }

        // Anyone Stripe already bills goes to the page that manages it, not
        // to a second checkout.
        if ($this->user()->subscription(Plan::SUBSCRIPTION_TYPE)?->valid() === true) {
            return redirect('/app/billing');
        }

        return redirect()->route('billing.checkout');
    }

    public function checkout(): Checkout|RedirectResponse
    {
        if (! BillingGate::isOpen()) {
            return $this->failed('Pro is not on sale yet.');
        }

        $user = $this->user();
        $priceId = ProPrice::priceId();

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
            $pending = $this->pendingSession($user);

            if ($pending instanceof RedirectResponse) {
                return $pending;
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
            ...StripeTax::checkoutOptions(),
        ]);

        $user->forceFill([
            'stripe_checkout_session_id' => $checkout->asStripeCheckoutSession()->id,
        ])->save();

        return $checkout;
    }

    /**
     * What to do about a Checkout session this account already started.
     * Null means there is nothing in flight and a new one may begin.
     *
     * The lock above only survives 30 seconds while a session lives for
     * hours, and Cashier writes no subscription row until the webhook lands.
     * Without this, one customer could complete two sessions and be billed
     * twice.
     */
    private function pendingSession(User $user): ?RedirectResponse
    {
        $sessionId = $user->stripe_checkout_session_id;

        // A session Stripe cannot tell us about is not a reason to refuse a
        // customer: `find()` answers null and a fresh session begins.
        $session = is_string($sessionId)
            ? app(CheckoutSessions::class)->find($sessionId)
            : null;

        if ($session === null) {
            return null;
        }

        // Paid, but the subscription webhook has not arrived yet. Starting
        // a second checkout here is exactly the double charge to avoid.
        if ($session->isPaid()) {
            return $this->notice('Your payment went through. Pro appears here as soon as Stripe confirms it — usually within a minute.');
        }

        return $session->isOpen() && $session->url !== null
            ? redirect($session->url)
            : null;
    }

    private function notice(string $message): RedirectResponse
    {
        Notification::make()
            ->info()
            ->title('Almost there')
            ->body($message)
            ->send();

        return redirect('/app/billing');
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
