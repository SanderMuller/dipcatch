<section class="mt-10 space-y-6" data-test="hidden-shops">
    <div>
        <flux:heading>{{ __('Hidden shops') }}</flux:heading>
        <flux:subheading>{{ __('DipCatch won’t suggest these shops. Products you already track there keep working.') }}</flux:subheading>
    </div>

    @if ($shops->isEmpty())
        <flux:text class="text-zinc-500 dark:text-zinc-400" data-test="hidden-shops-empty">
            {{ __('No hidden shops. Choose Don’t suggest on a suggested shop to hide it.') }}
        </flux:text>
    @else
        <ul role="list" class="divide-y divide-ink/5 dark:divide-white/5">
            @foreach ($shops as $shop)
                <li class="flex items-center gap-3 py-2.5" wire:key="hidden-shop-{{ $shop->id }}" data-test="hidden-shop">
                    <img src="{{ \App\Support\Favicon::url($shop->host) }}" alt="" loading="lazy" class="size-5 shrink-0 rounded" />
                    <flux:text variant="strong" class="min-w-0 flex-1 truncate font-medium">{{ $shop->label }}</flux:text>
                    <flux:button size="sm" wire:click="showAgain({{ \Illuminate\Support\Js::from($shop->host) }})" data-test="hidden-shop-show">
                        {{ __('Show again') }}<span class="sr-only"> {{ $shop->label }}</span>
                    </flux:button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
