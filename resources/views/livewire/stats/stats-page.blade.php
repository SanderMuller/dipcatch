<div>
    <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Stats') }}</flux:heading>
    <flux:text class="mt-1 max-w-[60ch] text-pretty text-zinc-600 dark:text-zinc-400">
        {{ __('The history behind your alerts. The dashboard shows what is happening now.') }}
    </flux:text>

    <div class="mt-8">
        <flux:heading size="lg" level="2">{{ __('Savings by month') }}</flux:heading>
        <flux:text class="mt-1 max-w-[60ch] text-pretty text-zinc-600 dark:text-zinc-400">
            {{ __('Added up from every alert we sent in the last twelve months. One bar per currency, and we never convert between them.') }}
        </flux:text>

        @if ($savings)
            <flux:card class="mt-6">
                <flux:chart :value="$savings['rows']" class="aspect-[3/1]">
                    <flux:chart.svg>
                        @if (count($savings['series']) > 1)
                            <flux:chart.group>
                                @foreach ($savings['series'] as $series)
                                    <flux:chart.bar :field="$series['field']" :class="$series['color']" />
                                @endforeach
                            </flux:chart.group>
                        @else
                            @foreach ($savings['series'] as $series)
                                <flux:chart.bar :field="$series['field']" :class="$series['color']" />
                            @endforeach
        @endif
                    <flux:chart.axis axis="x" field="date" :format="['month' => 'short', 'year' => '2-digit']">
                        <flux:chart.axis.tick />
                        <flux:chart.axis.line />
                    </flux:chart.axis>
                    <flux:chart.axis axis="y" tick-start="0">
                        <flux:chart.axis.grid />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                    <flux:chart.cursor type="area" />
                </flux:chart.svg>
                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="date" :format="['month' => 'long', 'year' => 'numeric']" />
                    @foreach ($savings['series'] as $series)
                        <flux:chart.tooltip.value :field="$series['field']" :label="$series['label']" :format="['style' => 'currency', 'currency' => $series['currency']]" />
                    @endforeach
                </flux:chart.tooltip>
                @if (count($savings['series']) > 1)
                    <div class="flex flex-wrap justify-center gap-4 pt-4">
                        @foreach ($savings['series'] as $series)
                            <flux:chart.legend :label="$series['label']">
                                <flux:chart.legend.indicator :class="$series['legend']" />
                            </flux:chart.legend>
                        @endforeach
                    </div>
                @endif
                </flux:chart>
            </flux:card>
        @else
            <flux:callout class="mt-6" icon="chart-bar">
                <flux:callout.heading>{{ __('Nothing to plot yet') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('The chart fills in as soon as we alert you on your first price drop.') }}
                </flux:callout.text>
            </flux:callout>
        @endif
    </div>

    @if ($recentAlerts->isNotEmpty())
        <div class="mt-8">
            <flux:heading size="lg" level="2">{{ __('Recent alerts') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                {{ __('What DipCatch has told you, newest first. The bell empties itself. This list does not.') }}
            </flux:text>

            <flux:timeline class="mt-4">
                @foreach ($recentAlerts as $alert)
                    <flux:timeline.item wire:key="alert-{{ $loop->index }}">
                        <flux:timeline.indicator color="green">
                            <flux:icon.arrow-trending-down variant="micro" />
                        </flux:timeline.indicator>
                        <flux:timeline.content>
                            <flux:heading>
                                @if ($alert['url'])
                                    <a href="{{ $alert['url'] }}" wire:navigate>{{ Str::limit($alert['title'], 60) }}</a>
                                @else
                                    {{ Str::limit($alert['title'], 60) }}
                                @endif
                                @if ($alert['sentAt'])
                                    <flux:text inline>· {{ $alert['sentAt'] }}</flux:text>
                                @endif
                            </flux:heading>
                            <flux:text class="tabular-nums">
                                {{ $alert['percent'] ?? '—' }}
                                @if ($alert['amount'])
                                    · {{ $alert['amount'] }}
                                @endif
                                @if ($alert['bundle'])
                                    · {{ $alert['bundle'] }}
                                @endif
                            </flux:text>
                        </flux:timeline.content>
                    </flux:timeline.item>
                @endforeach
            </flux:timeline>
        </div>
    @endif
</div>
