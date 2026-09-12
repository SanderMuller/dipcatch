<div>
    <flux:heading size="xl">{{ __('Plan & billing') }}</flux:heading>

    @if ($isBlocked)
        {{-- A lost chargeback drops the account to Free while Stripe may still
             be billing it, so the portal stays reachable below. --}}
        <flux:callout variant="danger" class="mt-4" icon="exclamation-triangle">
            <flux:callout.heading>Pro is paused after a chargeback</flux:callout.heading>
            <flux:callout.text>
                A payment was charged back and lost, so Pro features are off. Everything you track is untouched and still being checked. Contact support to sort it out.
            </flux:callout.text>
        </flux:callout>
    @elseif ($isPastDue)
        {{-- keepPastDueSubscriptionsActive() means Pro survives dunning, so the
             warning must not threaten what is not at risk. --}}
        <flux:callout variant="warning" class="mt-4" icon="exclamation-triangle">
            <flux:callout.heading>Your last payment did not go through</flux:callout.heading>
            <flux:callout.text>Update your card to keep Pro. Your tracked products keep working either way.</flux:callout.text>
            <flux:button class="mt-3" size="sm" :href="route('billing.portal')">Update payment details</flux:button>
        </flux:callout>
    @endif

    <flux:card class="mt-6">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="lg">Your plan</flux:heading>
            <flux:badge :color="$isPro ? 'green' : 'zinc'">{{ $isPro ? 'Pro' : 'Free' }}</flux:badge>

            @if ($isComped)
                <flux:text class="text-zinc-500">Pro is on us, so there is nothing to pay</flux:text>
            @elseif ($isOnTrial)
                <flux:text class="text-zinc-500">Trial ends {{ $periodEndsAt }}</flux:text>
            @elseif ($isCancelling)
                <flux:text class="text-zinc-500">Cancelled. Pro runs until {{ $periodEndsAt }}</flux:text>
            @elseif ($isPro)
                <flux:text class="text-zinc-500">{{ $priceLabel }} per month</flux:text>
            @endif
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div>
                <flux:text size="sm" class="text-zinc-500">Products</flux:text>
                <flux:heading size="lg">
                    {{ $productCount }}
                    @if ($entitlements->maxProducts() !== null)
                        <span class="text-zinc-500">/ {{ $entitlements->maxProducts() }}</span>
                    @else
                        <span class="text-zinc-500">tracked, no limit</span>
                    @endif
                </flux:heading>
            </div>

            <div>
                <flux:text size="sm" class="text-zinc-500">Shops per product</flux:text>
                <flux:heading size="lg">{{ $entitlements->maxShopsPerProduct() ?? 'No limit' }}</flux:heading>
            </div>

            <div>
                <flux:text size="sm" class="text-zinc-500">Price check</flux:text>
                <flux:heading size="lg">Every {{ $entitlements->recheckIntervalHours() }} hours</flux:heading>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            @if ($canManageBilling)
                <flux:button :href="route('billing.portal')" variant="filled">Manage subscription</flux:button>
            @endif

            @if (! $isPro && $canUpgrade)
                <flux:button :href="route('billing.checkout')" variant="primary">
                    @if ($offersTrial)
                        Start {{ $trialDays }}-day trial
                    @else
                        Upgrade to Pro
                    @endif
                </flux:button>
            @elseif (! $isBlocked && ! $isPro)
                <flux:text class="text-zinc-500">Pro is not on sale yet.</flux:text>
            @endif

            <flux:button :href="route('pricing')" variant="ghost">Compare plans</flux:button>
        </div>
    </flux:card>
</div>
