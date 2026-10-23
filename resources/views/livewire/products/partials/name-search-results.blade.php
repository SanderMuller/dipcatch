{{-- Step 1's results for a typed name: the catalogue and bol.com first, the
     web below on request. A row is a button that sends its index, so the
     wizard probes the link it listed, never one the browser sends. --}}
@php
    $localCount = count($nameResults['items']);
    $webBlock = $this->webSearchBlock();
    $webNote = $webStatus?->note();
    $webFound = $webStatus === \App\Services\ProductSearch\WebNameSearchStatus::Found;
@endphp
{{-- Keyed on what the block shows, so a new search or web answer replaces
     it whole and Alpine reads the new searched text, rather than a morph
     keeping the bindings of the last one. --}}
<section class="space-y-3" aria-labelledby="name-results-heading" data-test="name-results" wire:key="name-results-{{ md5(json_encode([$searchedQuery, $webStatus, $webVerdict, $webCapSpent])) }}">
    <flux:heading level="3" id="name-results-heading" tabindex="-1" class="focus:outline-none">
        {{ __('Results for “:text”', ['text' => $searchedQuery]) }}
    </flux:heading>

    @if ($localCount === 0)
        <flux:text class="text-zinc-500 dark:text-zinc-400" data-test="name-results-empty">
            {{ __('Nothing found in the supermarkets or at bol.com. Search the web, or add it by hand.') }}
        </flux:text>
    @else
        @include('livewire.products.partials.name-search-rows', ['rows' => $nameResults['items'], 'offset' => 0])
    @endif

    @if ($nameResults['bolFailed'])
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" data-test="name-results-bol-failed">{{ __('bol.com did not answer. Try again in a moment.') }}</flux:text>
    @endif

    @if ($webFound)
        <flux:heading level="3" id="web-results-heading" tabindex="-1" class="pt-2 focus:outline-none">{{ __('From the web') }}</flux:heading>
        @if ($webResults === [])
            <flux:text class="text-zinc-500 dark:text-zinc-400" data-test="web-results-empty">{{ __('The web search found no shops for this name.') }}</flux:text>
        @else
            @include('livewire.products.partials.name-search-rows', ['rows' => $webResults, 'offset' => $localCount])
        @endif
    @else
        {{-- Off while the input holds other text than was searched, so the
             web search never runs for words the results do not describe. --}}
        <div class="flex flex-wrap items-center gap-3" x-data>
            <flux:button
                type="button"
                icon="globe-alt"
                wire:click="searchTheWeb"
                :disabled="$webBlock !== null || ! $this->searchedQueryIsShown()"
                x-bind:disabled="{{ $webBlock !== null ? 'true' : 'false' }} || ($wire.query ?? '').trim() !== {{ \Illuminate\Support\Js::from($searchedQuery) }}"
                data-test="search-the-web"
            >
                <span wire:loading.remove wire:target="searchTheWeb">{{ __('Search the web') }}</span>
                <span wire:loading wire:target="searchTheWeb">{{ __('Searching the web…') }}</span>
            </flux:button>
        </div>
        @if (($webBlock ?? $webNote) !== null)
            <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400" data-test="web-search-note">{{ $webBlock ?? $webNote }}</flux:text>
        @endif
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400" @style(['display: none' => $this->searchedQueryIsShown()]) x-show="($wire.query ?? '').trim() !== {{ \Illuminate\Support\Js::from($searchedQuery) }}">
            {{ __('Look up the new text first, then search the web for it.') }}
        </flux:text>
    @endif

    <flux:button type="button" variant="ghost" size="sm" wire:click="switchMode('manual')" data-test="name-results-manual">
        {{ __('Not listed? Add it by hand') }}
    </flux:button>
</section>
