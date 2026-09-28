<div>
    @if ($checklist !== null && $checklist->open !== [])
        <flux:card class="mt-6 space-y-5" data-test="tracking-ideas">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('What else do you buy again and again?') }}</flux:heading>
                    <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                        {{ __('Tick off what you already track. A tracked product ticks its item by itself.') }}
                    </flux:text>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400" data-test="tracking-ideas-progress">
                        {{ __(':done of :total', ['done' => $checklist->handled(), 'total' => $checklist->total()]) }}
                    </span>
                    <flux:button size="sm" variant="ghost" wire:click="hide">{{ __('Hide this list') }}</flux:button>
                </div>
            </div>

            <div class="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (collect($checklist->open)->groupBy(fn ($idea) => $idea->group()->value) as $group => $ideas)
                    <div wire:key="idea-group-{{ $group }}">
                        <flux:heading size="sm" level="3" class="text-zinc-500! dark:text-zinc-400!">{{ $ideas->first()->group()->label() }}</flux:heading>
                        <ul class="mt-2 space-y-1">
                            @foreach ($ideas as $idea)
                                <li wire:key="idea-{{ $idea->value }}" class="flex items-center justify-between gap-2 text-sm">
                                    <a href="{{ route('app.products.create', ['idea' => $idea->value]) }}" wire:navigate class="hover:underline">
                                        {{ $idea->label() }}
                                    </a>
                                    <span class="flex shrink-0 gap-0.5">
                                        <flux:button size="xs" variant="ghost" icon="check" wire:click="markDone('{{ $idea->value }}')" :aria-label="__('I track :idea already', ['idea' => $idea->label()])" :tooltip="__('I track this already')" />
                                        <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="skip('{{ $idea->value }}')" :aria-label="__(':idea is not for me', ['idea' => $idea->label()])" :tooltip="__('Not for me')" />
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            @if ($checklist->tracked !== [] || $checklist->done !== [] || $checklist->skipped !== [])
                <div class="space-y-1 border-t border-line pt-3 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($checklist->tracked !== [] || $checklist->done !== [])
                        <p data-test="tracking-ideas-covered">
                            <flux:icon.check-circle variant="micro" class="inline size-4 text-savings-strong" />
                            {{ __('Covered:') }}
                            @foreach ($checklist->tracked as $idea)
                                {{ $idea->label() }}@if (! $loop->last || $checklist->done !== []),@endif
                            @endforeach
                            @foreach ($checklist->done as $idea)
                                <button type="button" wire:key="done-{{ $idea->value }}" wire:click="undo('{{ $idea->value }}')" class="underline decoration-dotted hover:decoration-solid" aria-label="{{ __('Undo :idea', ['idea' => $idea->label()]) }}">{{ $idea->label() }}</button>@unless ($loop->last),@endunless
                            @endforeach
                        </p>
                    @endif
                    @if ($checklist->skipped !== [])
                        <p data-test="tracking-ideas-skipped">
                            {{ __('Not for me:') }}
                            @foreach ($checklist->skipped as $idea)
                                <button type="button" wire:key="skipped-{{ $idea->value }}" wire:click="undo('{{ $idea->value }}')" class="underline decoration-dotted hover:decoration-solid" aria-label="{{ __('Put :idea back on the list', ['idea' => $idea->label()]) }}">{{ $idea->label() }}</button>@unless ($loop->last),@endunless
                            @endforeach
                        </p>
                    @endif
                </div>
            @endif
        </flux:card>
    @endif
</div>
