<div @class(['mb-6' => $spaced && ($switchedOn || $offered)])>
    @if ($switchedOn)
        <flux:callout icon="check-circle" color="green" data-test="ai-feature-on">
            <flux:callout.text>
                {{ $aiFeature->switchedOnText() }}
                <flux:link :href="route('app.notifications') . '#ai-features'" wire:navigate>{{ __('Change it in settings') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @elseif ($offered)
        <flux:callout icon="sparkles" color="zinc" data-test="ai-feature-prompt">
            <flux:callout.heading>{{ $aiFeature->promptHeading() }}</flux:callout.heading>
            <flux:callout.text>{{ $aiFeature->promptText() }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="switchOn" data-test="ai-feature-switch-on">{{ __('Switch on') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="dismiss">{{ __('Not now') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif
</div>
