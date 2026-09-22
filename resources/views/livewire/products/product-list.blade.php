<div>
    <div>
        <flux:heading size="xl" level="1" class="tracking-tight">{{ __('Products') }}</flux:heading>
        <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
            {{ __('Everything you follow. Newest first, until you sort them another way.') }}
        </flux:text>
    </div>

    {{-- The add action sits with the search and the filters, not opposite the
         heading: it belongs to the same row of controls a person works in. --}}
    <div class="mt-6 flex flex-wrap gap-3">
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

        {{-- An optgroup label cannot be picked, so each department opens with
             an option that stands for the whole department. --}}
        <flux:select class="max-w-56" wire:model.live="category" :label:sr="__('Category')" data-test="product-category-filter">
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
        </flux:select>

        {{-- Named choices, beside the search and the filter. The table headers
             used to carry the sorting, which asked a person to know that a
             heading was clickable and to guess what a second click did. --}}
        <flux:select class="max-w-56" wire:model.live="sort" :label:sr="__('Sort by')" data-test="product-sort">
            <flux:select.option value="created_at">{{ __('Newest first') }}</flux:select.option>
            <flux:select.option value="title">{{ __('Name A-Z') }}</flux:select.option>
            <flux:select.option value="cheapest_price">{{ __('Lowest price first') }}</flux:select.option>
            <flux:select.option value="biggest_drop">{{ __('Biggest drop first') }}</flux:select.option>
        </flux:select>
    </div>

    @php($emptyMessage = $search !== '' ? __('No product matches that search.') : (\App\Enums\ProductCategory::leavesFor($category) !== null ? __('No product in that category.') : __('Nothing tracked yet.')))

    <x-product-card.grid class="mt-6">
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
