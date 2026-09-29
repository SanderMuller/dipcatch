<div>
    @if ($checklist->open !== [] && ! $hidden)
        <flux:card class="mt-6 space-y-6" data-test="tracking-ideas">
            <div class="space-y-4">
                <div>
                    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight text-balance">{{ __('What else do you buy again and again?') }}</flux:heading>
                    <flux:text class="mt-1 max-w-[65ch] text-pretty text-zinc-500 dark:text-zinc-400">
                        {{ __('Pick an item to track a product for it. Tick what you already track, and a tracked product ticks its item by itself.') }}
                    </flux:text>
                </div>

                <div class="flex items-center gap-3 text-base/7 sm:text-sm/6">
                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-ink/5 dark:bg-white/10" aria-hidden="true">
                        <div class="h-full w-(--progress) rounded-full bg-savings" style="--progress: {{ round($checklist->handled() / $checklist->total() * 100) }}%"></div>
                    </div>
                    <p class="shrink-0 tabular-nums text-zinc-500 dark:text-zinc-400" data-test="tracking-ideas-progress">
                        {{ __(':done of :total done', ['done' => $checklist->handled(), 'total' => $checklist->total()]) }}
                    </p>
                </div>
            </div>

            {{-- Ticked items stay in place, so the list does not jump under the
                 pointer. Only "not for me" takes an item out. --}}
            <div class="@container">
                <div class="grid gap-x-10 gap-y-6 @xl:grid-cols-2 @4xl:grid-cols-3">
                    @foreach (collect(\App\Enums\TrackingIdea::cases())->reject(fn ($idea) => in_array($idea, $checklist->skipped, true))->groupBy(fn ($idea) => $idea->group()->value) as $group => $ideas)
                        <div wire:key="idea-group-{{ $group }}">
                            <div class="flex items-baseline justify-between gap-3 text-base/7 sm:text-sm/6">
                                <flux:heading level="3" class="truncate text-zinc-500! dark:text-zinc-400!">{{ $ideas->first()->group()->label() }}</flux:heading>
                                <p class="shrink-0 tabular-nums text-zinc-400 dark:text-zinc-500">
                                    {{ $ideas->reject(fn ($idea) => in_array($idea, $checklist->open, true))->count() }}/{{ $ideas->count() }}
                                </p>
                            </div>
                            <ul role="list" class="mt-2">
                                @foreach ($ideas as $idea)
                                    @php
                                        $isTracked = in_array($idea, $checklist->tracked, true);
                                        $isDone = in_array($idea, $checklist->done, true);
                                    @endphp
                                    <li wire:key="idea-{{ $idea->value }}" class="flex items-start gap-3 py-1 text-base/7 sm:text-sm/6">
                                        <span class="flex h-lh items-center">
                                            <span class="group inline-grid size-5 grid-cols-1 sm:size-4">
                                                <input
                                                    type="checkbox"
                                                    name="tracking_idea_{{ $idea->value }}"
                                                    @checked($isTracked || $isDone)
                                                    @disabled($isTracked)
                                                    wire:click="{{ $isDone ? 'undo' : 'markDone' }}('{{ $idea->value }}')"
                                                    aria-label="{{ __('I track :idea already', ['idea' => $idea->label()]) }}"
                                                    class="col-start-1 row-start-1 appearance-none rounded-sm border border-zinc-300 bg-white checked:border-savings-strong checked:bg-savings-strong indeterminate:border-savings-strong indeterminate:bg-savings-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-savings-strong disabled:border-zinc-300 disabled:bg-zinc-100 disabled:checked:bg-zinc-100 dark:border-white/10 dark:bg-white/5 dark:checked:border-savings-strong dark:checked:bg-savings-strong dark:indeterminate:border-savings-strong dark:indeterminate:bg-savings-strong dark:focus-visible:outline-savings-strong dark:disabled:border-white/5 dark:disabled:bg-white/10 dark:disabled:checked:bg-white/10 forced-colors:appearance-auto"
                                                />
                                                <svg viewBox="0 0 14 14" fill="none" class="pointer-events-none col-start-1 row-start-1 size-7/8 self-center justify-self-center stroke-white group-has-disabled:stroke-zinc-950/25 dark:not-group-has-disabled:stroke-zinc-950 dark:group-has-disabled:stroke-white/25">
                                                    <path d="M3 8L6 11L11 3.5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="group-not-has-checked:opacity-0" />
                                                    <path d="M3 7H11" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="group-not-has-indeterminate:opacity-0" />
                                                </svg>
                                            </span>
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            @if ($isTracked || $isDone)
                                                <p class="text-zinc-500 line-through decoration-zinc-400 dark:text-zinc-400 dark:decoration-zinc-500">{{ $idea->label() }}</p>
                                            @else
                                                <a href="{{ route('app.products.create', ['idea' => $idea->value]) }}" wire:navigate class="underline-offset-4 hover:underline">{{ $idea->label() }}</a>
                                            @endif
                                        </div>

                                        @if ($isTracked)
                                            <p class="shrink-0 text-savings-strong">{{ __('Tracked') }}</p>
                                        @elseif (! $isDone)
                                            <flux:tooltip :content="__('Not for me')">
                                                <button type="button" wire:click="skip('{{ $idea->value }}')" aria-label="{{ __(':idea is not for me', ['idea' => $idea->label()]) }}" class="relative flex h-lh shrink-0 items-center rounded-sm px-1 text-zinc-400 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:text-zinc-500 dark:hover:text-zinc-200">
                                                    <flux:icon.x-mark variant="micro" class="size-4 shrink-0" />
                                                    <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
                                                </button>
                                            </flux:tooltip>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t border-ink/5 pt-4 dark:border-white/10">
                @if ($checklist->skipped !== [])
                    <div class="flex flex-wrap items-center gap-2 text-base/7 sm:text-sm/6" data-test="tracking-ideas-skipped">
                        <p class="text-zinc-500 dark:text-zinc-400">{{ __('Not for me:') }}</p>
                        <ul role="list" class="flex flex-wrap gap-2">
                            @foreach ($checklist->skipped as $idea)
                                <li wire:key="skipped-{{ $idea->value }}" class="flex items-center gap-1 rounded-full bg-ink/5 py-0.5 pr-0.5 pl-3 text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                                    {{ $idea->label() }}
                                    <button type="button" wire:click="undo('{{ $idea->value }}')" aria-label="{{ __('Put :idea back on the list', ['idea' => $idea->label()]) }}" class="relative flex size-6 items-center justify-center rounded-full text-zinc-400 hover:bg-ink/5 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:text-zinc-500 dark:hover:bg-white/10 dark:hover:text-zinc-200">
                                        <flux:icon.arrow-uturn-left variant="micro" class="size-4 shrink-0" />
                                        <span class="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" aria-hidden="true"></span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <flux:button size="sm" variant="ghost" class="ml-auto" wire:click="hide">{{ __('Hide this list') }}</flux:button>
            </div>
        </flux:card>
    @elseif ($checklist->open !== [])
        <p class="mt-6 text-base/7 sm:text-sm/6">
            <button type="button" wire:click="show" class="text-zinc-500 underline-offset-4 hover:text-zinc-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:text-zinc-400 dark:hover:text-zinc-200" data-test="tracking-ideas-show">
                {{ __('Show ideas for what else to track') }}
            </button>
        </p>
    @endif
</div>
