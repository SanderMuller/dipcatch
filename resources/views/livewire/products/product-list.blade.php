{{-- The sort in the URL wins, then the one this browser last chose, then the
     default. The URL drops the default, so an address without a sort does
     not tell a chosen default from none. --}}
<div
    x-data
    x-init="
        const key = 'dipcatch.products.sort';
        const remembered = localStorage.getItem(key);

        if (! new URLSearchParams(location.search).has('sort') && @js(\App\Livewire\Products\ProductList::sortOptions()).includes(remembered) && remembered !== $wire.sort) {
            $wire.$set('sort', remembered);
        }

        $wire.$watch('sort', (sort) => localStorage.setItem(key, sort));
    "
>
    <div>
        <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Products') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
            {{ __('Everything you follow. Biggest drops first, until you sort them another way.') }}
        </flux:text>
    </div>

    {{-- A message names the one filter that emptied the list; with more than
         one on, it speaks of the filter as a whole. --}}
    @php($uncategorisedOnly = $category === \App\Livewire\Products\ProductList::NO_CATEGORY)
    @php($inCategory = $uncategorisedOnly || \App\Enums\ProductCategory::leavesFor($category) !== null)
    @php($filters = count(array_filter([$search !== '', in_array($status, ['active', 'paused'], true), $inCategory, $shop !== ''])))
    @php($emptyMessage = match (true) {
        $discounted && $filters > 0 => __('No product in this filter has a discount right now.'),
        $discounted => __('No product has a discount right now.'),
        $filters > 1 => __('No product matches this filter.'),
        $search !== '' => __('No product matches that search.'),
        $uncategorisedOnly => __('Every product has a category.'),
        $inCategory => __('No product in that category.'),
        $shop !== '' => __('No product at that shop.'),
        $filters === 1 => __('No product matches this filter.'),
        default => __('Nothing tracked yet.'),
    })

    {{-- The add action sits above the categories, and the search and the
         filters above the products they narrow. --}}
    <div class="mt-6 grid gap-3 lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start lg:gap-x-8 lg:gap-y-6">
        <div>
            @if ($canAddProduct)
                <flux:button class="rounded-full!" :href="route('app.products.create')" icon="plus" variant="primary" wire:navigate>
                    {{ __('Track a product') }}
                </flux:button>
            @else
                {{-- The limit is stated, not hidden: the guard also refuses the write. --}}
                <flux:tooltip content="{{ __('You are following as many products as the free plan allows.') }}">
                    <flux:button class="rounded-full!" icon="plus" variant="primary" disabled>{{ __('Track a product') }}</flux:button>
                </flux:tooltip>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:input
                class="flex-1"
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search your products')"
                clearable
                autocomplete="off"
                data-1p-ignore
            />

            <flux:radio.group variant="segmented" wire:model.live="status" :aria-label="__('Show')">
                <flux:radio value="all">{{ __('All') }}</flux:radio>
                <flux:radio value="active">{{ __('Active') }}</flux:radio>
                <flux:radio value="paused">{{ __('Paused') }}</flux:radio>
            </flux:radio.group>

            {{-- An active drop, or a deal at the cheapest shop running now. --}}
            <flux:switch wire:model.live="discounted" :label="__('Only discounts')" data-test="product-discount-filter" />

            {{-- An optgroup label cannot be picked, so each department opens with
                 an option that stands for the whole department. A wide screen
                 lists the categories beside the products instead. --}}
            <flux:select class="max-w-56 lg:hidden" wire:model.live="category" :label:sr="__('Category')" data-test="product-category-filter">
                <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
                @foreach ($categoryGroups as $departmentValue => $categories)
                    @php($department = \App\Enums\ProductDepartment::from($departmentValue))
                    <flux:select.group :label="$department->label()">
                        <flux:select.option value="{{ $department->value }}">{{ __('All :department', ['department' => Str::lcfirst($department->label())]) }}</flux:select.option>
                        @foreach ($categories as $leaf)
                            <flux:select.option value="{{ $leaf->value }}">{{ $leaf->label() }}</flux:select.option>
                        @endforeach
                    </flux:select.group>
                @endforeach
                @if ($hasUncategorised)
                    <flux:select.option value="{{ \App\Livewire\Products\ProductList::NO_CATEGORY }}">{{ __('No category') }}</flux:select.option>
                @endif
            </flux:select>

            @if ($shopHosts !== [])
                <flux:select class="max-w-56 lg:hidden" wire:model.live="shop" :label:sr="__('Shop')" data-test="product-shop-filter">
                    <flux:select.option value="">{{ __('All shops') }}</flux:select.option>
                    @foreach ($shopHosts as $host)
                        <flux:select.option value="{{ $host }}">{{ $host }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            {{-- Named choices, beside the search and the filter. The table headers
                 used to carry the sorting, which asked a person to know that a
                 heading was clickable and to guess what a second click did. --}}
            <flux:select class="max-w-56" wire:model.live="sort" :label:sr="__('Sort by')" data-test="product-sort">
                <flux:select.option value="biggest_drop">{{ __('Biggest drop first') }}</flux:select.option>
                <flux:select.option value="created_at">{{ __('Newest first') }}</flux:select.option>
                <flux:select.option value="title">{{ __('Name A-Z') }}</flux:select.option>
                <flux:select.option value="cheapest_price">{{ __('Lowest price first') }}</flux:select.option>
            </flux:select>
        </div>

        <aside class="hidden lg:block" data-test="product-category-nav">
            <flux:heading level="2" class="px-3 pb-2 text-zinc-500">{{ __('Categories') }}</flux:heading>
            {{-- Flux sets the item and group labels in small type; these lift
                 them a size, and make the current item bold on a white chip. --}}
            <flux:navlist
                variant="outline"
                class="[&_[data-content]]:text-base [&_[data-flux-navlist-group]>button>span]:text-base lg:[&_[data-flux-navlist-item]]:h-9 lg:[&_[data-flux-navlist-group]>button]:h-9 [&_[data-flux-navlist-item][data-current]]:shadow-xs [&_[data-flux-navlist-item][data-current]_[data-content]]:font-semibold"
            >
                <flux:navlist.item icon="squares-2x2" wire:click="$set('category', '')" :current="$category === ''" :aria-current="$category === '' ? 'true' : 'false'">
                    {{ __('All categories') }}
                </flux:navlist.item>

                @foreach ($categoryGroups as $departmentValue => $categories)
                    @php($department = \App\Enums\ProductDepartment::from($departmentValue))
                    @php($inDepartment = $category === $department->value || in_array($category, array_map(fn ($leaf) => $leaf->value, $categories), true))
                    <flux:navlist.group expandable :expanded="$inDepartment" :heading="$department->label()" wire:key="department-{{ $department->value }}">
                        <flux:navlist.item wire:click="$set('category', '{{ $department->value }}')" :current="$category === $department->value" :aria-current="$category === $department->value ? 'true' : 'false'">
                            {{ __('All :department', ['department' => Str::lcfirst($department->label())]) }}
                        </flux:navlist.item>
                        @foreach ($categories as $leaf)
                            <flux:navlist.item wire:click="$set('category', '{{ $leaf->value }}')" :current="$category === $leaf->value" :aria-current="$category === $leaf->value ? 'true' : 'false'">
                                {{ $leaf->label() }}
                            </flux:navlist.item>
                        @endforeach
                    </flux:navlist.group>
                @endforeach

                @if ($hasUncategorised)
                    <flux:navlist.item icon="question-mark-circle" wire:click="$set('category', '{{ \App\Livewire\Products\ProductList::NO_CATEGORY }}')" :current="$uncategorisedOnly" :aria-current="$uncategorisedOnly ? 'true' : 'false'" data-test="product-category-none">
                        {{ __('No category') }}
                    </flux:navlist.item>
                @endif
            </flux:navlist>

            {{-- Narrowed by the category above, so it names only the shops
                 that sell something in it. --}}
            @if ($shopHosts !== [])
                <div data-test="product-shop-nav">
                    <flux:heading level="2" class="mt-6 px-3 pb-2 text-zinc-500">{{ __('Shops') }}</flux:heading>
                    <flux:navlist
                        variant="outline"
                        class="[&_[data-content]]:text-base lg:[&_[data-flux-navlist-item]]:h-9 [&_[data-flux-navlist-item][data-current]]:shadow-xs [&_[data-flux-navlist-item][data-current]_[data-content]]:font-semibold"
                    >
                        <flux:navlist.item icon="building-storefront" wire:click="$set('shop', '')" :current="$shop === ''" :aria-current="$shop === '' ? 'true' : 'false'">
                            {{ __('All shops') }}
                        </flux:navlist.item>
                        @foreach ($shopHosts as $host)
                            <flux:navlist.item wire:click="$set('shop', '{{ $host }}')" :current="$shop === $host" :aria-current="$shop === $host ? 'true' : 'false'" wire:key="shop-{{ $host }}">
                                <span class="inline-flex items-center gap-2.5">
                                    <img src="{{ \App\Support\Favicon::url($host) }}" alt="" loading="lazy" class="size-4 rounded-sm" />
                                    {{ $host }}
                                </span>
                            </flux:navlist.item>
                        @endforeach
                    </flux:navlist>
                </div>
            @endif
        </aside>

        <div class="mt-3 min-w-0 lg:mt-0">
            @if ($showAutoCategoriesPromo)
                <flux:callout icon="sparkles" color="amber" class="mb-6" data-test="auto-categories-promo">
                    <flux:callout.heading>{{ __('Let Pro sort your products') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('With Pro, switch on automatic categories in your settings and DipCatch sorts the products you add. Pro also suggests a category for the products already here.') }}</flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" variant="primary" :href="route('upgrade')">{{ $promoOffersTrial ? __('Try Pro') : __('Get Pro') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif

            {{-- Four across leaves room for the category list. --}}
            <x-product-card.grid :columns="4">
                @forelse ($products as $product)
                    <li wire:key="product-{{ $product->id }}" class="min-w-0">
                        <x-product-card :product="$product" />
                    </li>
                @empty
                    <li class="col-span-full py-10 text-center"><flux:text class="text-zinc-500">{{ $emptyMessage }}</flux:text></li>
                @endforelse
            </x-product-card.grid>

            <flux:pagination :paginator="$products" class="mt-6" />
        </div>
    </div>
</div>
