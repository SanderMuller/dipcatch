<div class="mt-6 border-t border-line pt-4" data-test="price-changes">
    <flux:button variant="subtle" size="sm" inset="left" :icon:trailing="$open ? 'chevron-up' : 'chevron-down'" wire:click="$toggle('open')" aria-controls="price-changes-list" :aria-expanded="$open ? 'true' : 'false'">
        {{ $open ? __('Hide price changes') : __('Show price changes') }}
    </flux:button>

    @if ($open)
        <div id="price-changes-list" class="mt-3">
            @if (! $allowed)
                <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400" data-test="price-changes-pro">
                    {{ __('With Pro you see every change of the lowest price, and what DipCatch did about it.') }}
                    @if ($billingUrl !== null)
                        <a href="{{ $billingUrl }}" class="font-medium text-ink underline underline-offset-4 hover:text-brand">{{ __('See Pro') }}</a>
                    @endif
                </p>
            @elseif ($rows === [])
                <p class="text-base text-zinc-500 sm:text-sm dark:text-zinc-400">{{ __('No price changes in this period.') }}</p>
            @else
                <ol role="list" class="divide-y divide-line">
                    @foreach ($rows as $row)
                        <li class="py-3" wire:key="price-change-{{ $row['at']->timestamp }}-{{ $loop->index }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <p class="text-base font-medium tabular-nums sm:text-sm">
                                    @if ($row['from'] !== null)
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ \App\Support\MoneyFormatter::format($row['from'], $product->currency) }}</span>
                                        <span aria-hidden="true" class="text-zinc-400">&rarr;</span>
                                        <span class="sr-only">{{ __('to') }}</span>
                                    @endif
                                    {{ $row['to'] === null ? __('No price') : \App\Support\MoneyFormatter::format($row['to'], $product->currency) }}
                                    @if ($row['changePct'] !== null && $row['changePct'] !== 0)
                                        <span @class(['ml-1 text-sm', 'text-savings-strong dark:text-savings' => $row['changePct'] < 0, 'text-zinc-500 dark:text-zinc-400' => $row['changePct'] > 0])>
                                            {{ $row['changePct'] > 0 ? '+' : '' }}{{ $row['changePct'] }}%
                                        </span>
                                    @endif
                                </p>
                                <time datetime="{{ $row['at']->toIso8601String() }}" class="text-sm text-zinc-500 tabular-nums dark:text-zinc-400">{{ $row['at']->setTimezone($timezone)->isoFormat('D MMM, HH:mm') }}</time>
                            </div>
                            @if ($row['shop'] !== null)
                                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $row['shop'] }}</p>
                            @endif
                            @if ($row['action'] !== null)
                                <p @class([
                                    'mt-1 flex items-start gap-1.5 text-sm',
                                    'text-ink dark:text-zinc-200' => $row['action'] === \App\Charts\PriceChangeAction::WrongPriceCaught,
                                    'text-zinc-600 dark:text-zinc-300' => $row['action'] !== \App\Charts\PriceChangeAction::WrongPriceCaught,
                                ]) data-test="price-change-action">
                                    <flux:icon :icon="match ($row['action']) {
                                        \App\Charts\PriceChangeAction::WrongPriceCaught => 'shield-check',
                                        \App\Charts\PriceChangeAction::Alert, \App\Charts\PriceChangeAction::ConfirmedAlert, \App\Charts\PriceChangeAction::ReachedAlertPrice => 'bell',
                                        \App\Charts\PriceChangeAction::Confirmed => 'check-circle',
                                        default => 'clock',
                                    }" variant="micro" class="mt-0.5 size-4 shrink-0" />
                                    <span>{{ $row['action']->label() }}</span>
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ol>
                @if ($hasMore)
                    <flux:button variant="subtle" size="sm" inset="left" class="mt-2" wire:click="showMore">{{ __('Show more') }}</flux:button>
                @endif
            @endif
        </div>
    @endif
</div>
