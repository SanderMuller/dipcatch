<div>
    <flux:heading size="xl">{{ __('Plan & billing') }}</flux:heading>

    @if ($isBlocked)
        {{-- A lost chargeback drops the account to Free while Stripe may still
             be billing it, so the portal stays reachable below. --}}
        <flux:callout variant="danger" class="mt-4" icon="shield-exclamation">
            <flux:callout.heading>{{ __('Pro is on hold') }}</flux:callout.heading>
            <flux:callout.text>{{ __('A payment dispute was decided against this account. Manage or cancel your subscription below, or contact support.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="mt-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <flux:heading size="lg">{{ __('Your plan') }}</flux:heading>
                <flux:badge :color="$plan->isPro() ? 'green' : 'zinc'">{{ $plan->isPro() ? 'Pro' : 'Free' }}</flux:badge>
            </div>

            @if ($periodEndsAt)
                <flux:text class="text-zinc-500">
                    @if ($isOnTrial)
                        {{ __('Trial ends :date', ['date' => $periodEndsAt]) }}
                    @elseif ($isCancelling)
                        {{ __('Access ends :date', ['date' => $periodEndsAt]) }}
                    @else
                        {{ __('Renews :date', ['date' => $periodEndsAt]) }}
                    @endif
                </flux:text>
            @elseif ($isComped)
                <flux:text class="text-zinc-500">{{ __('Pro, on the house') }}</flux:text>
            @endif
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div>
                <flux:text size="sm" class="text-zinc-500">{{ __('Products') }}</flux:text>
                <flux:heading size="lg">
                    {{ $productCount }}
                    @if ($entitlements->maxProducts() !== null)
                        <span class="text-zinc-500">/ {{ $entitlements->maxProducts() }}</span>
                    @else
                        <span class="text-zinc-500">{{ __('tracked, no limit') }}</span>
                    @endif
                </flux:heading>
            </div>

            <div>
                <flux:text size="sm" class="text-zinc-500">{{ __('Shops per product') }}</flux:text>
                <flux:heading size="lg">{{ $entitlements->maxShopsPerProduct() ?? __('No limit') }}</flux:heading>
            </div>

            <div>
                <flux:text size="sm" class="text-zinc-500">{{ __('Price check') }}</flux:text>
                <flux:heading size="lg">{{ __('Every :hours hours', ['hours' => $entitlements->recheckIntervalHours()]) }}</flux:heading>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            @if ($canManageBilling)
                <flux:button :href="route('billing.portal')" variant="filled">{{ __('Manage subscription') }}</flux:button>
            @endif

            @if ($canUpgrade && ! $plan->isPro())
                <flux:button :href="route('billing.checkout')" variant="primary">
                    {{ $offersTrial ? __('Start :days-day trial', ['days' => $trialDays]) : __('Upgrade for :price a month', ['price' => $priceLabel]) }}
                </flux:button>
            @endif

            <flux:button :href="route('pricing')" variant="ghost">{{ __('Compare plans') }}</flux:button>
        </div>
    </flux:card>
</div>
