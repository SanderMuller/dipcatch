{{--
    Step 3's suggested alert, built from the facts AlertSuggestion carries.
    Needs `$suggestion`, `$product`, `$onOfferNow`, `$asksJev`, `$canSwitchOnAi`.
--}}
@php
    $currency = $product->currency;
    $perUnit = fn (string $amount): string => \App\Support\MoneyFormatter::unitPrice($amount, $currency) . ' ' . \App\Support\UnitWord::labelFor($suggestion->unit);
@endphp

<div class="space-y-3 rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200 dark:bg-white/5 dark:ring-white/10" data-test="alert-suggestion"
    @if ($asksJev) wire:init="askJev" wire:loading.attr="aria-busy" wire:target="askJev" @endif>
    <flux:heading level="3">{{ __('Suggested alert') }}</flux:heading>

    <flux:text class="max-w-[65ch]" data-test="alert-suggestion-reason">
        @if ($suggestion->unitTarget === null)
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
        @else
            @if ($suggestion->promotionNowHost !== null)
                {{ __(':shop has this :depth% off now.', ['shop' => $suggestion->promotionNowHost, 'depth' => $suggestion->promotionNowDepth]) }}
            @endif
            @if ($suggestion->cappedByLaw)
                {{ __('Alcohol can go at most :depth% off in the Netherlands.', ['depth' => $suggestion->depth]) }}
            @elseif ($suggestion->depthSource === \App\Enums\DepthSource::Jev)
                {{ __('Products like this often go :depth% off.', ['depth' => $suggestion->depth]) }}
            @elseif ($suggestion->depthSource === \App\Enums\DepthSource::Category && $suggestion->category !== null)
                {{ __(':category often goes about :depth% off.', ['category' => $suggestion->category->label(), 'depth' => $suggestion->depth]) }}
            @endif
            {{ __('We suggest :target, :depth% under the normal :normal.', [
                'target' => $perUnit($suggestion->unitTarget),
                'depth' => $suggestion->depth,
                'normal' => $perUnit((string) $suggestion->normalUnitPrice),
            ]) }}
            @if ($suggestion->normalPack !== null && $suggestion->packTarget !== null)
                {{ __('That is :price for the :pack pack.', ['price' => \App\Support\MoneyFormatter::format($suggestion->packTarget, $currency), 'pack' => $suggestion->normalPack]) }}
            @endif
            @if ($suggestion->alreadyMet)
                {{ __('It is at that price now, so we notify you from the next time it gets there.') }}
            @endif
        @endif
    </flux:text>

    @if ($asksJev)
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" wire:loading wire:target="askJev">{{ __('Checking how this product goes on sale…') }}</flux:text>
    @elseif ($canSwitchOnAi)
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

    @if ($suggestion->unitTarget !== null)
        <div class="flex flex-wrap gap-2">
            <flux:button type="button" size="sm" variant="primary" wire:click="useSuggestion" data-test="use-suggestion">{{ __('Use this alert') }}</flux:button>
            <flux:button type="button" size="sm" wire:click="setOwn">{{ __('Set my own') }}</flux:button>
        </div>
    @endif
</div>
