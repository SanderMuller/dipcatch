<x-filament-widgets::widget>
    <x-filament::section
        heading="Next steps"
        description="DipCatch pays off once a product has a second shop to compare against."
        collapsible
    >
        <ol class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($steps as $step)
                <li class="flex items-start gap-4 py-3 first:pt-0 last:pb-0">
                    @if ($step['done'])
                        <x-filament::icon icon="heroicon-s-check-circle" class="mt-0.5 size-5 shrink-0 text-success-600 dark:text-success-400" />
                    @else
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 dark:border-gray-600" aria-hidden="true"></span>
                    @endif

                    <div class="min-w-0 flex-1">
                        <p @class(['text-sm font-medium', 'text-gray-500 line-through dark:text-gray-400' => $step['done'], 'text-gray-950 dark:text-white' => ! $step['done']])>
                            {{ $step['title'] }}
                        </p>
                        <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{{ $step['description'] }}</p>
                    </div>

                    @if (! $step['done'] && $step['url'] !== null)
                        <x-filament::button tag="a" :href="$step['url']" size="sm" :color="$loop->index === 1 ? 'primary' : 'gray'" class="shrink-0">
                            {{ $step['cta'] }}
                        </x-filament::button>
                    @endif
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-widgets::widget>
