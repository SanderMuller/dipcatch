@props(['images'])

{{-- The photos the shops reported, to pick from instead of pasting a URL.
     The Livewire component around it has `imageUrl` and `useShopImage()`. --}}
@if ($images !== [])
    <div>
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Or take one a shop reported:') }}</flux:text>
        <ul role="list" class="mt-2 flex flex-wrap gap-3">
            @foreach ($images as $url => $host)
                <li>
                    {{-- Selected while the field holds this image, also right
                         after a paste or a click, before anything is saved. --}}
                    <button
                        type="button"
                        {{-- Through Alpine: @js escapes quotes, which a URL
                             written straight into wire:click would not survive. --}}
                        x-on:click="$wire.useShopImage(@js($url))"
                        aria-label="{{ __('Use the photo from :host', ['host' => $host]) }}"
                        x-data="{ get selected() { return $wire.imageUrl === @js($url) } }"
                        x-bind:aria-pressed="selected ? 'true' : 'false'"
                        x-bind:data-selected="selected"
                        class="relative flex w-24 cursor-pointer flex-col items-center gap-1 rounded-xl bg-white/80 p-2 ring-1 ring-zinc-200 hover:bg-white data-selected:bg-white data-selected:ring-2 data-selected:ring-zinc-900 dark:bg-zinc-900/60 dark:ring-zinc-800 dark:hover:bg-zinc-900 dark:data-selected:bg-zinc-900 dark:data-selected:ring-white"
                    >
                        <span x-show="selected" x-cloak class="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-zinc-900 text-white dark:bg-white dark:text-zinc-900" aria-hidden="true">
                            <flux:icon.check variant="micro" class="size-3.5" />
                        </span>
                        <img src="{{ $url }}" alt="" loading="lazy" class="size-16 rounded-lg bg-white object-contain" />
                        <span class="w-full truncate text-center text-xs text-zinc-500 dark:text-zinc-400">{{ $host }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
@else
    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
        {{ __('Shop images appear here after the next price check of each shop.') }}
    </flux:text>
@endif
