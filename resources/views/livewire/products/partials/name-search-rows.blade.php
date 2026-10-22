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
                class="flex w-full items-start gap-3 p-3 text-left first:rounded-t-xl last:rounded-b-xl hover:bg-zinc-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand disabled:opacity-60 dark:hover:bg-white/5"
                data-test="name-result"
            >
                @if ($photo !== null)
                    <img src="{{ $photo }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="size-12 flex-none rounded-lg bg-white object-contain ring-1 ring-zinc-200 dark:ring-white/10" />
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
