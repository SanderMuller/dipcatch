<div>
    <flux:heading size="xl" level="1" class="text-2xl! font-semibold! tracking-tight sm:text-3xl!">{{ __('Plan & billing') }}</flux:heading>
    <flux:text class="mt-1 max-w-[60ch] text-pretty text-zinc-500 dark:text-zinc-400">
        {{ __('Your plan, how much of it you use, and where to change how you pay.') }}
    </flux:text>

    {{-- One live region for the whole wait, so the swap from waiting to welcome or late is announced. --}}
    @if ($checkoutDone)
        <div role="status">
        @if ($isPro && ! $isBlocked)
            <flux:callout class="mt-6" icon="check-circle" color="green" data-test="checkout-welcome">
                <flux:callout.heading>{{ __('Welcome to Pro') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Every Pro feature is yours now.') }}</flux:callout.text>
            </flux:callout>
        @elseif (! $isPro && $stillWaiting)
            <flux:callout class="mt-6" icon="arrow-path" wire:poll.2s="waitForPro" data-test="checkout-waiting">
                <flux:callout.heading>{{ __('Setting up Pro') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Thanks for your payment. Pro switches on as soon as Stripe confirms it, which takes a few seconds.') }}</flux:callout.text>
            </flux:callout>
        @elseif (! $isPro)
            <flux:callout class="mt-6" icon="clock" data-test="checkout-slow">
                <flux:callout.heading>{{ __('Pro is on its way') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Stripe has not confirmed your payment yet. Pro switches on by itself once it does, usually within a few minutes. Reload this page in a moment, or contact support if it takes longer.') }}</flux:callout.text>
            </flux:callout>
        @endif
        </div>
    @endif

    @if ($isBlocked)
        {{-- A lost chargeback drops the account to Free while Stripe may still
             be billing it, so the portal stays reachable below. --}}
        <flux:callout variant="danger" class="mt-6" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Pro is paused after a chargeback') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('A payment was charged back and lost, so Pro features are off. Everything you track is untouched and still being checked. Contact support to sort it out.') }}
            </flux:callout.text>
        </flux:callout>
    @elseif ($isPastDue)
        {{-- keepPastDueSubscriptionsActive() means Pro survives dunning, so the
             warning must not threaten what is not at risk. --}}
        <flux:callout variant="warning" class="mt-6" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Your last payment did not go through') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Update your card to keep Pro. Your tracked products keep working either way.') }}</flux:callout.text>
            <flux:button class="mt-3 w-fit" size="sm" :href="route('billing.portal')">{{ __('Update payment details') }}</flux:button>
        </flux:callout>
    @endif

    @php
        $maxProducts = $entitlements->maxProducts();
        $usedPercent = $maxProducts ? min(100, (int) round($productCount / $maxProducts * 100)) : null;
        $productsFull = $remainingProducts === 0;
        $statClass = 'border-ink/10 px-6 py-5 even:border-l dark:border-white/10 [&:nth-child(n+3)]:border-t @2xl:[&:not(:first-child)]:border-l @2xl:[&:nth-child(n+3)]:border-t-0';
        $historyLabel = fn (\App\Billing\Entitlements $plan): string => __(':days days', ['days' => $plan->historyKeptDays()]);
    @endphp

    <div class="mt-8 overflow-hidden rounded-2xl bg-paper ring-1 ring-line dark:bg-zinc-900 dark:ring-white/10">
        <div class="flex flex-col gap-5 p-6 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Your plan') }}</flux:text>
                <p class="mt-1 text-2xl font-semibold tracking-tight text-ink dark:text-white">{{ $isPro ? 'Pro' : 'Free' }}</p>
                <flux:text class="mt-1 text-pretty text-zinc-600 dark:text-zinc-400">{{ $status }}</flux:text>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($canManageBilling)
                    <flux:button :href="route('billing.portal')" variant="filled" icon-trailing="arrow-top-right-on-square">{{ __('Manage subscription') }}</flux:button>
                @endif
                @if ($isPro)
                    <flux:button :href="route('pricing')" variant="ghost">{{ __('Compare plans') }}</flux:button>
                @endif
            </div>
        </div>

        @if ($canManageBilling)
            <p class="-mt-2 px-6 pb-5 text-sm text-pretty text-zinc-500 dark:text-zinc-400">
                {{ __('Your card, invoices and cancelling live in Stripe, where payments are handled.') }}
            </p>
        @endif

        <div class="@container border-t border-ink/10 dark:border-white/10">
            <dl class="grid grid-cols-2 @2xl:grid-cols-4">
                <div class="{{ $statClass }}">
                    <dt id="products-used" class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ __('Products') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-ink tabular-nums dark:text-white">
                        {{ $productCount }}
                        <span class="font-normal text-zinc-500 dark:text-zinc-400">{{ $maxProducts === null ? __('tracked, no limit') : __('of :max', ['max' => $maxProducts]) }}</span>
                    </dd>
                    @if ($usedPercent !== null)
                        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-ink/10 dark:bg-white/10" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $maxProducts }}" aria-valuenow="{{ min($productCount, $maxProducts) }}" aria-valuetext="{{ __(':count of :max', ['count' => $productCount, 'max' => $maxProducts]) }}" aria-labelledby="products-used">
                            <div @class(['h-full rounded-full', 'bg-alert' => $productsFull, 'bg-chart' => ! $productsFull && $usedPercent >= 80, 'bg-savings' => $usedPercent < 80]) style="width: {{ $usedPercent }}%"></div>
                        </div>
                        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $productsFull ? ($isPro ? __('Full') : __('Full. Pro holds up to :max.', ['max' => $pro->maxProducts()])) : trans_choice(':count place left|:count places left', $remainingProducts ?? 0) }}
                        </p>
                    @endif
                </div>

                <div class="{{ $statClass }}">
                    <dt class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ __('Shops per product') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-ink tabular-nums dark:text-white">{{ $entitlements->maxShopsPerProduct() ?? __('No limit') }}</dd>
                </div>

                <div class="{{ $statClass }}">
                    <dt class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ __('Price checks') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-ink tabular-nums dark:text-white">{{ __('Every :hours h', ['hours' => $entitlements->recheckIntervalHours()]) }}</dd>
                </div>

                <div class="{{ $statClass }}">
                    <dt class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ __('Price history') }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-ink dark:text-white">{{ $historyLabel($entitlements) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    @if (! $isPro && $canUpgrade)
        <section class="mt-12" aria-labelledby="pro-compare">
            <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                <div>
                    <h2 id="pro-compare" class="text-xl font-semibold tracking-tight text-ink dark:text-white">{{ __('Free and Pro, side by side') }}</h2>
                    <p class="mt-1 max-w-[60ch] text-base text-pretty text-zinc-500 sm:text-sm dark:text-zinc-400">
                        {{ $offersTrial
                            ? __('Start a :days-day trial, then :price a month. Cancel any time.', ['days' => $trialDays, 'price' => $priceLabel])
                            : __(':price a month. Cancel any time.', ['price' => $priceLabel]) }}
                    </p>
                </div>
                <flux:link :href="route('app.pro')" wire:navigate class="text-sm">{{ __('See everything Pro adds') }}</flux:link>
            </div>

            <x-plans.comparison
                class="mt-6 max-w-none!"
                sticky-top="top-[4.75rem]"
                :offers-trial="$offersTrial"
                :pro-cta="['href' => route('billing.checkout'), 'label' => $offersTrial ? __('Start :days-day trial', ['days' => $trialDays]) : __('Upgrade to Pro'), 'short' => $offersTrial ? __('Try free') : __('Get Pro')]"
                :yearly-cta="$yearlyLabel === null ? null : ['href' => route('billing.checkout', ['interval' => 'yearly']), 'label' => $offersTrial ? __('Or :price a year after the trial', ['price' => $yearlyLabel]) : __('Or :price a year', ['price' => $yearlyLabel])]"
            />
        </section>
    @elseif (! $isPro && ! $isBlocked)
        <flux:text class="mt-6 text-zinc-500 dark:text-zinc-400">{{ __('Pro is not on sale yet.') }}</flux:text>
    @elseif ($isPro && $aiAvailable)
        <section class="mt-8" aria-labelledby="ai-help">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <flux:heading size="lg" level="2" id="ai-help" class="font-semibold! tracking-tight">{{ __('AI help in your plan') }}</flux:heading>
                <flux:link :href="route('product-features.edit')" wire:navigate class="text-sm">{{ __('Change in Product features') }}</flux:link>
            </div>
            <flux:text class="mt-0.5 max-w-[60ch] text-pretty text-zinc-500 dark:text-zinc-400">{{ __('Included in Pro. Each one stays off until you switch it on.') }}</flux:text>

            <ul role="list" class="mt-4 divide-y divide-ink/10 border-y border-ink/10 dark:divide-white/10 dark:border-white/10">
                @foreach ([
                    [__('Automatic categories'), __('New products filed for you.'), $autoCategoriesOn, $entitlements->allowsAutoCategories()],
                    [__('Same-product check'), __('A warning when a new shop sells another flavour or pack.'), $shopChecksOn, $entitlements->allowsShopChecks()],
                ] as [$label, $hint, $on, $allowed])
                    @continue(! $allowed)
                    <li class="flex items-center justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <p class="font-medium text-ink dark:text-white">{{ $label }}</p>
                            <p class="text-sm text-pretty text-zinc-500 dark:text-zinc-400">{{ $hint }}</p>
                        </div>
                        <flux:badge :color="$on ? 'green' : 'zinc'" size="sm" class="shrink-0">{{ $on ? __('On') : __('Off') }}</flux:badge>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
