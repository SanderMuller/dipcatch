{{-- Flux puts attributes on its wrapper, not on the <dialog>, so the name is linked from here. --}}
<div x-init="$el.querySelector('dialog')?.setAttribute('aria-labelledby', 'pro-ai-offer-title')">
    @if ($features !== [])
        <flux:modal name="pro-ai-offer" wire:model.self="open" focusable class="w-full max-w-lg" data-test="pro-ai-offer">
            <form wire:submit="switchOn" class="space-y-6">
                <div class="space-y-2">
                    <x-pro-badge />
                    <flux:heading size="lg" id="pro-ai-offer-title">{{ __('Let AI do the busywork') }}</flux:heading>
                    <flux:text>{{ __('Your Pro plan includes these. They stay off until you switch them on.') }}</flux:text>
                </div>

                <div class="space-y-4">
                    @foreach ($features as $feature)
                        <div class="flex items-start gap-3 rounded-xl p-3 ring-1 ring-line dark:ring-white/10" wire:key="pro-ai-offer-{{ $feature->value }}">
                            <flux:checkbox wire:model="chosen" value="{{ $feature->value }}" :aria-label="$feature->label()" class="mt-0.5" data-test="pro-ai-offer-{{ $feature->value }}" />
                            <div class="min-w-0">
                                <p class="font-semibold text-ink dark:text-white">{{ $feature->label() }}</p>
                                <ul role="list" class="mt-2 grid gap-1.5">
                                    @foreach ($feature->does() as $line)
                                        <li class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                            <flux:icon.check variant="micro" class="h-lh shrink-0 text-savings-strong dark:text-savings" />
                                            {{ $line }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost" data-test="pro-ai-offer-not-now">{{ __('Not now') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="pro-ai-offer-switch-on">{{ __('Switch on') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
