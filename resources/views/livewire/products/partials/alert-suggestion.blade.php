{{--
    The suggested alert, built from the facts AlertSuggestion carries, on
    step 3 of adding a product. The edit form shows it as a row in the price
    list instead: alert-suggestion-row.
    Needs `$suggestion`, `$product`, `$onOfferNow`, `$asksJev`, `$usesJev`,
    and `$canSwitchOnAi`.
    While `$asksJev`, the card shows a checking state: the numbers can still
    change with Jev's answer.
--}}
@php
    $currency = $product->currency;
    $perUnit = fn (string $amount): string => \App\Support\MoneyFormatter::unitPrice($amount, $currency) . ' ' . \App\Support\UnitWord::labelFor($suggestion->unit);
    $leadsWithPack = $suggestion->normalPack !== null && $suggestion->packTarget !== null;
@endphp

<section aria-labelledby="alert-suggestion-heading" class="rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-950/5 sm:p-5 dark:bg-white/5 dark:ring-white/10" data-test="alert-suggestion"
    @if ($asksJev) wire:init="askJev" wire:loading.attr="aria-busy" wire:target="askJev" @endif>
    <h3 id="alert-suggestion-heading" tabindex="-1" class="flex items-center gap-1.5 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-brand text-sm/6 font-medium text-zinc-600 dark:text-zinc-400">
        <flux:icon.bell-alert variant="micro" class="shrink-0" />
        {{ __('Suggested alert') }}
    </h3>

    @if ($asksJev)
        <div class="mt-3 space-y-3" data-test="alert-suggestion-checking">
            <p class="flex items-center gap-2 text-base/7 text-zinc-600 sm:text-sm/6 dark:text-zinc-400">
                <flux:icon.loading variant="mini" class="shrink-0" />
                {{ __('Checking how products like this go on sale…') }}
            </p>
            <div class="h-9 w-40 animate-pulse rounded-md bg-zinc-950/5 dark:bg-white/10" aria-hidden="true"></div>
            <div class="h-4 max-w-sm animate-pulse rounded-md bg-zinc-950/5 dark:bg-white/10" aria-hidden="true"></div>
        </div>
    @elseif ($suggestion->unitTarget === null)
        <p class="mt-2 max-w-[65ch] text-base/7 text-pretty text-zinc-900 sm:text-sm/6 dark:text-white" data-test="alert-suggestion-reason">
            @if ($suggestion->band === \App\Enums\PromotionDepthBand::Fixed)
                {{ __('This product has a fixed price, so we only alert you on a real drop. Keep the default alert below.') }}
            @elseif ($suggestion->unit === null)
                {{ __('We could not read a pack size, so we suggest the default alert below.') }}
            @elseif ($suggestion->normalUnitPrice === null)
                {{ __('We could not tell its normal price, so we suggest the default alert below.') }}
            @else
                {{ __('We have no sign that this product goes on sale, so we suggest the default alert below.') }}
            @endif
            @if ($onOfferNow)
                {{ __('It is on offer now, so for its first weeks the default counts from the offer price.') }}
            @endif
        </p>
    @else
        <p class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1" data-test="alert-suggestion-price">
            @if ($leadsWithPack)
                <span class="text-3xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-white">{{ \App\Support\MoneyFormatter::format($suggestion->packTarget, $currency) }}</span>
                <span class="text-base/7 text-zinc-600 sm:text-sm/6 dark:text-zinc-400">{{ __('for the :pack pack', ['pack' => $suggestion->normalPack]) }}</span>
            @else
                <span class="text-3xl font-semibold tracking-tight text-zinc-950 tabular-nums dark:text-white">{{ $perUnit($suggestion->unitTarget) }}</span>
            @endif
        </p>

        @include('livewire.products.partials.alert-suggestion-insight')

        <p class="mt-1 max-w-[65ch] text-base/7 text-pretty text-zinc-500 sm:text-sm/6 dark:text-zinc-400" data-test="alert-suggestion-detail">
            @if ($leadsWithPack)
                {{ __('Our suggestion is :target, :depth% under the normal :normal.', [
                    'target' => $perUnit($suggestion->unitTarget),
                    'depth' => $suggestion->depth,
                    'normal' => $perUnit((string) $suggestion->normalUnitPrice),
                ]) }}
            @else
                {{ __(':depth% under the normal :normal.', [
                    'depth' => $suggestion->depth,
                    'normal' => $perUnit((string) $suggestion->normalUnitPrice),
                ]) }}
            @endif
            @if ($suggestion->cheapOutliers !== [])
                {{ trans_choice(':shops is normally far cheaper than the other shops, so its price does not count as the normal one.|:shops are normally far cheaper than the other shops, so their prices do not count as the normal one.', count($suggestion->cheapOutliers), ['shops' => implode(', ', $suggestion->cheapOutliers)]) }}
            @endif
            @if ($suggestion->alreadyMet)
                {{ __('It is at that price now, so we notify you from the next time it gets there.') }}
            @endif
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
            <flux:button type="button" size="sm" variant="filled" wire:click="useSuggestion" data-test="use-suggestion">{{ __('Use this alert') }}</flux:button>
            <flux:button type="button" size="sm" variant="ghost" wire:click="setOwn">{{ __('Set my own') }}</flux:button>
        </div>
    @endif

    @if (! $usesJev)
        <div class="mt-4 border-t border-zinc-950/5 pt-3 dark:border-white/10">
            @if ($canSwitchOnAi)
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="switch-on-ai">
                    {{ __('With AI help on, Pro also checks how products like this go on sale.') }}
                    <flux:link :href="route('product-features.edit')" wire:navigate>{{ __('Switch on AI help') }}</flux:link>
                </flux:text>
            @else
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="pro-teaser">
                    {{ __('Pro also checks how products like this go on sale.') }}
                    <flux:link :href="route('app.billing')" wire:navigate>{{ __('Compare plans') }}</flux:link>
                </flux:text>
            @endif
        </div>
    @endif
</section>
