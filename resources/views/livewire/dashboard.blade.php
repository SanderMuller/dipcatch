<div>
    <flux:heading size="xl" level="1" class="text-2xl! font-semibold! tracking-tight sm:text-3xl!">{{ __('Dashboard') }}</flux:heading>
    <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
        {{ __('What you track, and what it has saved you so far.') }}
    </flux:text>

    {{-- One surface with dividers, not three cards: these three numbers are
         siblings in one context, so they need separation, not elevation. --}}
    <flux:card class="mt-6 p-0!">
        <dl class="grid divide-y divide-zinc-950/5 sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-white/10">
            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Tracked products') }}</dt>
                <dd class="mt-2 text-3xl font-semibold tracking-tight tabular-nums sm:text-2xl lg:text-4xl">{{ $trackedProducts }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('You are following these right now.') }}</dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Active drops') }}</dt>
                <dd @class([
                    'mt-2 text-3xl font-semibold tracking-tight tabular-nums sm:text-2xl lg:text-4xl',
                    'text-savings-strong' => $activeDropCount > 0,
                ])>{{ $activeDropCount }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Cheaper right now than the price you set.') }}</dd>
            </div>

            <div class="p-5">
                <dt class="truncate text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Potential savings so far') }}</dt>
                <dd class="mt-2 text-3xl font-semibold tracking-tight text-brand tabular-nums sm:text-2xl lg:text-4xl">{{ $lifetimeSavings }}</dd>
                <dd class="mt-1 text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('Compared with the price we alerted you from.') }}</dd>
                <dd class="mt-1">
                    <flux:link :href="route('app.stats')" variant="subtle" class="text-base sm:text-sm" wire:navigate data-test="savings-by-month-link">
                        {{ __('See it by month') }}
                    </flux:link>
                </dd>
            </div>
        </dl>
    </flux:card>

    @unless ($hasAnyProduct)
        <flux:callout class="mt-6" icon="sparkles">
            <flux:callout.heading>{{ __('Track your first product') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Paste a product link and DipCatch watches it at every shop that sells it.') }}</flux:callout.text>
            @if ($canAddProduct)
                <flux:button class="mt-3 rounded-full!" :href="route('app.products.create')" variant="primary" wire:navigate>
                    {{ __('Track a product') }}
                </flux:button>
            @endif
        </flux:callout>
    @endunless

    @if ($needsSecondShop)
        <flux:callout class="mt-6" icon="scale">
            <flux:callout.heading>{{ __('Add a second shop to compare') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('One shop gives you a price history. A second one tells you which shop is cheaper, per kilo, litre or piece.') }}
            </flux:callout.text>
            {{-- Lands on the product with the add-shop form already open, so the
                 button does what its label says in one step. --}}
            <flux:button class="mt-3" :href="route('app.products.show', [$watching->first(), 'add-shop' => 1])" variant="primary" wire:navigate data-test="add-second-shop">
                {{ __('Add a shop') }}
            </flux:button>
        </flux:callout>
    @endif

    @if ($watching->isNotEmpty())
        <div class="mt-8">
            <div class="flex items-center justify-between gap-3">
                <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Recently tracked') }}</flux:heading>
                <a href="{{ route('app.products.index') }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-paper py-1 pr-2 pl-3 text-sm font-medium ring-1 ring-line hover:bg-canvas focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                    {{ __('All products') }}
                    <flux:icon.arrow-right variant="micro" class="size-4 shrink-0" />
                </a>
            </div>

            <x-product-card.grid class="mt-4">
                @foreach ($watching as $product)
                    <li class="min-w-0" wire:key="watching-{{ $product->id }}">
                        <x-product-card :product="$product" :compare="false" />
                    </li>
                @endforeach
            </x-product-card.grid>
        </div>
    @endif

    <div class="mt-8">
        <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Active drops') }}</flux:heading>

        @if ($activeDrops->isEmpty())
            <div class="mt-4 rounded-2xl border border-dashed border-line px-6 py-10 text-center">
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No active drops right now.') }}</flux:text>
                <flux:text size="sm" class="text-zinc-400">{{ __("DipCatch is watching. You hear from us as soon as a price drops far enough.") }}</flux:text>
            </div>
        @else
            <x-product-card.grid class="mt-4">
                @foreach ($activeDrops as $product)
                    <li class="min-w-0" wire:key="drop-{{ $product->id }}">
                        <x-product-card :product="$product" :compare="false">
                            @if ($product->last_notified_at)
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                    {{ __('Dropped :ago', ['ago' => $product->last_notified_at->diffForHumans()]) }}
                                </flux:text>
                            @endif
                        </x-product-card>
                    </li>
                @endforeach
            </x-product-card.grid>
        @endif
    </div>

</div>
