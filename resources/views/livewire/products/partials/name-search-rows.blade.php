{{-- @var list<array{source: string, title: string, shopLabel: string, host: string, url: string, ean: ?string, price: ?string, packSize: ?string, photoUrl: ?string, snippet: ?string}> $rows --}}
{{-- @var int $offset The index of the first row in the wizard's listed results. --}}
<ul role="list" class="divide-y divide-zinc-200 rounded-xl ring-1 ring-zinc-200 dark:divide-white/10 dark:ring-white/10">
    @foreach ($rows as $row)
        @php($photo = \App\Support\ImageUrl::safe($row['photoUrl']))
        <li>
            <button
                type="button"
                wire:click="pickResult({{ $offset + $loop->index }})"
                wire:loading.attr="disabled"
                wire:target="pickResult"
                class="flex w-full items-center gap-4 p-3 text-left first:rounded-t-xl last:rounded-b-xl hover:bg-zinc-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand disabled:opacity-60 dark:hover:bg-white/5"
                data-test="name-result"
            >
                {{-- Every row keeps the slot, so the titles line up. Without a photo
                     it shows the shop's icon; a supermarket row asks for its page's
                     photo over it, and keeps the icon when there is none. --}}
                @if ($photo !== null)
                    <img src="{{ \App\Support\ImageUrl::thumbnail($photo) }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="size-16 flex-none rounded-lg bg-white object-contain p-1 ring-1 ring-zinc-200 sm:size-20 dark:ring-white/10" data-test="name-result-photo" />
                @else
                    <span class="relative flex size-16 flex-none items-center justify-center overflow-hidden rounded-lg bg-zinc-50 ring-1 ring-zinc-200 sm:size-20 dark:bg-white/5 dark:ring-white/10" aria-hidden="true">
                        <img src="{{ \App\Support\Favicon::url($row['host']) }}" alt="" loading="lazy" class="size-7 rounded opacity-80" />
                        @if ($row['source'] === \App\Services\ProductSearch\NameSearchSource::Catalogue->value)
                            <img src="{{ \Illuminate\Support\Facades\URL::signedRoute('app.name-search.photo', ['url' => $row['url']]) }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="absolute inset-0 size-full bg-white object-contain p-1 opacity-0 transition-opacity" x-data x-init="$el.complete && ($el.naturalWidth > 1 ? $el.classList.remove('opacity-0') : $el.remove())" x-on:load="$el.naturalWidth > 1 ? $el.classList.remove('opacity-0') : $el.remove()" x-on:error="$el.remove()" data-test="name-result-fetched-photo" />
                        @endif
                    </span>
                @endif
                <span class="min-w-0 flex-1">
                    <span class="block font-medium text-zinc-900 dark:text-white">{{ $row['title'] }}</span>
                    <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                        <x-shop-name :host="$row['host']" :name="$row['shopLabel']" />
                        @if ($row['packSize'] !== null && $row['packSize'] !== '')
                            <span>{{ $row['packSize'] }}</span>
                        @endif
                        @if ($row['price'] !== null)
                            <span class="font-medium tabular-nums text-zinc-700 dark:text-zinc-300">{{ \App\Support\MoneyFormatter::format($row['price'], 'EUR') }}</span>
                        @endif
                    </span>
                    @if ($row['snippet'] !== null)
                        <span class="mt-1 line-clamp-2 block text-sm text-zinc-500 dark:text-zinc-400">{{ $row['snippet'] }}</span>
                    @endif
                </span>
            </button>
        </li>
    @endforeach
</ul>
