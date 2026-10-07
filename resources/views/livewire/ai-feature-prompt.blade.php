{{-- In the Pro hint's frosted card, so offers of what Pro includes read as one family with the offers to get Pro. --}}
<div @class(['mb-6' => $spaced && ($switchedOn || $offered), 'mt-3' => $below && ($switchedOn || $offered)])>
    @if ($switchedOn)
        {{-- Focused, so a keyboard or screen reader user lands on the result rather than on the page top. --}}
        <flux:callout icon="check-circle" color="green" data-test="ai-feature-on" tabindex="-1" x-init="$el.focus()">
            <flux:callout.text>
                {{ $aiFeature->switchedOnText() }}
                <flux:link :href="route('product-features.edit')" wire:navigate>{{ __('Change it in settings') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @elseif ($offered)
        <div class="@container rounded-2xl bg-linear-to-r from-brand/5 via-white/40 to-violet-500/5 p-4 shadow-sm ring-1 ring-brand/15 backdrop-blur-md dark:from-brand/10 dark:via-zinc-900/60 dark:to-violet-500/10 dark:shadow-none dark:ring-white/10" data-test="ai-feature-prompt" data-place="{{ $aiPlace->value }}" data-ai-feature="{{ $aiFeature->value }}"
            {{-- A prompt from a later request (lazy load, a form opened later) hides when an earlier one for the feature is in the DOM: the server claim covers one request. --}}
            x-data x-init="if (document.querySelector('[data-ai-feature={{ $aiFeature->value }}]') !== $el) { $el.hidden = true }">
            <div class="flex flex-col gap-3 @xl:flex-row @xl:items-center @xl:justify-between @xl:gap-6">
                <div class="flex min-w-0 flex-col gap-2 @md:flex-row @md:items-start @md:gap-3">
                    <x-pro-badge class="self-start @md:mt-px" />
                    <p class="text-base text-pretty text-ink sm:text-sm dark:text-zinc-100">
                        <span class="font-semibold">{{ __(':feature come with your Pro plan.', ['feature' => $aiFeature->label()]) }}</span>
                        {{ $aiPlace->text() }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2 self-start @xl:self-auto">
                    <button type="button" wire:click="switchOn" class="inline-flex items-center gap-1.5 rounded-full bg-linear-to-r from-brand to-violet-500 py-2 pr-3.5 pl-3 text-sm font-medium text-white shadow-sm hover:from-brand/90 hover:to-violet-500/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:shadow-none" data-test="ai-feature-switch-on">
                        <flux:icon.sparkles variant="micro" class="shrink-0" />
                        {{ __('Switch on') }}
                    </button>
                    <flux:button size="sm" variant="ghost" wire:click="dismiss" data-test="ai-feature-not-now">{{ __('Not now') }}</flux:button>
                </div>
            </div>
        </div>
    @endif
</div>
