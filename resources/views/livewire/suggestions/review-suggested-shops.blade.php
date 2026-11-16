<div>
    <flux:modal :name="\App\Livewire\Suggestions\ReviewSuggestedShops::MODAL" class="w-full md:max-w-4xl" @close="finish" data-test="review-suggested-shops">
        @if ($current !== null && $product !== null)
            <div class="pe-8">
                <p class="flex items-center gap-2 text-sm font-medium text-zinc-500 tabular-nums dark:text-zinc-400">
                    <span data-test="review-position">{{ __('Suggestion :position of :total', ['position' => $position, 'total' => $total]) }}</span>
                    {{-- Moving on to the next suggestion takes a request of its own. --}}
                    <flux:icon.loading variant="micro" class="size-4" wire:loading aria-hidden="true" />
                </p>
                <flux:heading size="lg" level="2" class="mt-1 text-balance">
                    {{ __('Is :shop selling the same product?', ['shop' => $current['shop']]) }}
                </flux:heading>
                <div class="mt-3 h-1 overflow-hidden rounded-full bg-zinc-950/5 dark:bg-white/10" aria-hidden="true">
                    <div class="h-full w-(--done) rounded-full bg-brand" style="--done: {{ round(($position - 1) / max(1, $total) * 100) }}%"></div>
                </div>
            </div>

            <div class="mt-6 transition-opacity" wire:loading.class="pointer-events-none opacity-50">
                <livewire:shops.add-shop
                    :product="$product"
                    :review="\Illuminate\Support\Arr::only($current, ['url', 'shop', 'findingId', 'chain', 'externalId'])"
                    :key="'review-' . $position . '-' . $current['product'] . '-' . $current['host']"
                />
            </div>
        @elseif ($total > 0)
            <div class="py-6 text-center" data-test="review-done">
                <flux:heading size="lg" level="2">{{ __('All suggestions reviewed') }}</flux:heading>
                <flux:text class="mx-auto mt-2 max-w-[48ch] text-pretty">
                    {{ trans_choice('{0} You added no shops.|{1} You added :count shop.|[2,*] You added :count shops.', $added, ['count' => $added]) }}
                    @if ($declined > 0)
                        {{ trans_choice('{1} :count suggestion is hidden for its product.|[2,*] :count suggestions are hidden for their products.', $declined, ['count' => $declined]) }}
                    @endif
                    @if ($skipped > 0)
                        {{ trans_choice('{1} :count skipped suggestion stays on the dashboard.|[2,*] :count skipped suggestions stay on the dashboard.', $skipped, ['count' => $skipped]) }}
                    @endif
                </flux:text>
                <flux:modal.close>
                    <flux:button variant="primary" class="mt-6">{{ __('Done') }}</flux:button>
                </flux:modal.close>
            </div>
        @elseif ($gone)
            <div class="py-6 text-center" data-test="review-gone">
                <flux:heading size="lg" level="2">{{ __('This suggestion is no longer there') }}</flux:heading>
                <flux:text class="mx-auto mt-2 max-w-[48ch] text-pretty">{{ __('The shop may have been added or hidden in the meantime. Reload the dashboard to see the current suggestions.') }}</flux:text>
            </div>
        @else
            <div class="py-6 text-center" data-test="review-loading">
                <flux:icon.loading class="mx-auto" />
                <flux:text class="mt-3">{{ __('Opening the suggestion…') }}</flux:text>
            </div>
        @endif
    </flux:modal>
</div>
