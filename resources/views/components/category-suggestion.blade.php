{{--
    The category the AI suggested for a product it could not place on its
    own, with a one-click accept and decline. `accept` and `decline` name the
    Livewire methods. A recessed well rather than a card: it belongs to the
    field or the title it sits under.
--}}
@props(['label', 'accept', 'decline'])

<div {{ $attributes->class('flex w-fit max-w-full flex-wrap items-center gap-x-4 gap-y-2 rounded-lg bg-zinc-950/2.5 px-3 py-2 ring-1 ring-zinc-950/5 dark:bg-white/5 dark:ring-white/10') }}>
    <p class="flex min-w-0 flex-1 items-start gap-2 text-base/6 text-zinc-600 sm:text-sm/6 dark:text-zinc-300">
        <flux:icon.light-bulb variant="micro" class="h-lh shrink-0 text-chart-line dark:text-chart" />
        <span>{{ __('Suggested category:') }} <span class="font-medium text-zinc-900 dark:text-white">{{ $label }}</span></span>
    </p>
    <div class="flex shrink-0 items-center gap-1">
        <flux:button size="sm" variant="filled" icon="check" wire:click="{{ $accept }}">{{ __('Use it') }}</flux:button>
        <flux:button size="sm" variant="ghost" wire:click="{{ $decline }}">{{ __('Not this one') }}</flux:button>
    </div>
</div>
