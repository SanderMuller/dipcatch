{{--
    The suggested alert as a row in the price list on the edit form: the
    `suggestion` slot of x-unit-target. Needs `$suggestion`, `$product` and
    `$asksJev`. Inside a button, so phrasing content only.
--}}
@php
    $perUnit = fn (string $amount): string => \App\Support\MoneyFormatter::unitPrice($amount, $product->currency) . ' ' . \App\Support\UnitWord::labelFor($suggestion->unit);
@endphp

@if ($asksJev)
    <span class="flex items-center gap-2 text-base/6 text-zinc-600 sm:text-sm/6 dark:text-zinc-400">
        <flux:icon.loading variant="micro" class="shrink-0" />
        {{ __('Checking how products like this go on sale…') }}
    </span>
@else
    <span class="flex flex-wrap items-center gap-2 text-base/6 font-medium text-zinc-900 sm:text-sm/6 dark:text-white">
        {{ __('Suggested alert') }}
        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs/5 font-medium text-amber-900 tabular-nums dark:bg-amber-400/15 dark:text-amber-200">
            {{ __(':depth% under normal', ['depth' => $suggestion->depth]) }}
        </span>
    </span>
    @include('livewire.products.partials.alert-suggestion-insight', ['compact' => true])
    {{-- The rest once it is the chosen alert, so the list stays short. --}}
    <span x-show="isSuggested()" class="mt-1 block max-w-[65ch] text-sm/5 text-pretty text-zinc-500 dark:text-zinc-400" data-test="alert-suggestion-detail">
        @if ($suggestion->normalUnitPrice !== null)
            {{ __('The normal price is :normal.', ['normal' => $perUnit((string) $suggestion->normalUnitPrice)]) }}
        @endif
        @if ($suggestion->cheapOutliers !== [])
            {{ trans_choice(':shops is normally far cheaper than the other shops, so its price does not count as the normal one.|:shops are normally far cheaper than the other shops, so their prices do not count as the normal one.', count($suggestion->cheapOutliers), ['shops' => implode(', ', $suggestion->cheapOutliers)]) }}
        @endif
        @if ($suggestion->alreadyMet)
            {{ __('It is at that price now, so we notify you from the next time it gets there.') }}
        @endif
    </span>
@endif
