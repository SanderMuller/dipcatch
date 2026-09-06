<x-filament-panels::page>
    @php
        $user = $this->user();
        $isPro = $user->isPro();
        $limitProducts = $this->entitlements()->maxProducts();
        $limitShops = $this->entitlements()->maxShopsPerProduct();
        $used = $this->productCount();
        $endsAt = $this->periodEndsAt();
    @endphp

    @if ($this->isBlocked())
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-5 w-5 text-danger-500" />
                <div class="text-sm">
                    <p class="font-medium text-gray-950 dark:text-white">Pro is paused after a chargeback</p>
                    <p class="mt-1 text-gray-500 dark:text-gray-400">
                        A payment was charged back and lost, so Pro features are off. Everything you track is untouched and still being checked. Contact support to sort it out.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @elseif ($user->isPastDue())
        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-5 w-5 text-warning-500" />
                <div class="text-sm">
                    <p class="font-medium text-gray-950 dark:text-white">Your last payment did not go through</p>
                    <p class="mt-1 text-gray-500 dark:text-gray-400">
                        Update your card to keep Pro. Your tracked products keep working either way.
                    </p>
                    <x-filament::button tag="a" :href="route('billing.portal')" size="sm" class="mt-3">
                        Update payment details
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Your plan</x-slot>

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::badge :color="$isPro ? 'success' : 'gray'" size="lg">
                {{ $isPro ? 'Pro' : 'Free' }}
            </x-filament::badge>

            @if ($this->isOnTrial())
                <span class="text-sm text-gray-500 dark:text-gray-400">Trial ends {{ $endsAt }}</span>
            @elseif ($this->isCancelling())
                <span class="text-sm text-gray-500 dark:text-gray-400">Cancelled — Pro until {{ $endsAt }}</span>
            @elseif ($isPro)
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $this->priceLabel() }} per month</span>
            @endif
        </div>

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Products</p>
                <p class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                    {{ $used }} @if ($limitProducts !== null) / {{ $limitProducts }} @else <span class="font-normal text-gray-500">tracked, no limit</span> @endif
                </p>
                @if ($limitProducts !== null)
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                        <div class="h-full rounded-full {{ $used >= $limitProducts ? 'bg-danger-500' : 'bg-primary-500' }}"
                             style="width: {{ min(100, (int) round($used / max(1, $limitProducts) * 100)) }}%"></div>
                    </div>
                @endif
            </div>

            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Shops per product</p>
                <p class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                    {{ $limitShops ?? 'No limit' }}
                </p>
            </div>

            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Price check</p>
                <p class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                    Every {{ $this->entitlements()->recheckIntervalHours() }} hours
                </p>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            @if ($this->canManageBilling())
                <x-filament::button tag="a" :href="route('billing.portal')" color="gray">
                    Manage subscription
                </x-filament::button>
            @endif

            @if (! $isPro && $this->canUpgrade())
                <x-filament::button tag="a" :href="route('billing.checkout')">
                    @if ($this->offersTrial())
                        Start {{ $this->trialDays() }}-day trial
                    @else
                        Upgrade to Pro
                    @endif
                </x-filament::button>
            @elseif (! $this->isBlocked() && ! $isPro)
                <p class="text-sm text-gray-500 dark:text-gray-400">Pro is not on sale yet.</p>
            @endif

            <x-filament::button tag="a" href="{{ route('pricing') }}" color="gray" outlined>
                Compare plans
            </x-filament::button>
        </div>
    </x-filament::section>

    @if (! $isPro && $this->canUpgrade())
        <x-filament::section>
            <x-slot name="heading">What Pro adds</x-slot>

            <ul class="space-y-2 text-sm text-gray-500 dark:text-gray-400">
                <li>Unlimited products, and unlimited shops to compare per product.</li>
                <li>Prices checked every {{ \App\Billing\Entitlements::of(\App\Billing\Plan::Pro)->recheckIntervalHours() }} hours instead of {{ \App\Billing\Entitlements::of(\App\Billing\Plan::Free)->recheckIntervalHours() }}.</li>
                <li>Unit price alerts — be told when a product reaches your target per kilo, litre or piece.</li>
                <li>A higher alert ceiling, so a busy week is not silently capped.</li>
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
