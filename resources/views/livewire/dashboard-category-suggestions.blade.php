{{-- Hidden when empty, so the dashboard column keeps an even gap. --}}
<div @class(["hidden" => $products->isEmpty()])>
    @if ($products->isNotEmpty())
        <section class="min-w-0" data-test="category-suggestions">
            <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Give your products a category') }}</flux:heading>
            <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">
                {{ __('With a category, you can filter your product list by it.') }}
            </flux:text>

            <ul role="list" class="mt-4 grid gap-3">
                @foreach ($products as $product)
                    @php($suggested = $product->suggested_category)
                    <li class="rounded-2xl border border-ink/10 bg-paper p-3 dark:border-white/10" wire:key="uncategorised-{{ $product->id }}" data-test="uncategorised-product">
                        <div class="flex min-w-0 items-center gap-3">
                            <x-product-thumb :product="$product" thumbnail size="size-12" />
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('app.products.show', $product) }}" wire:navigate class="line-clamp-2 font-medium underline-offset-4 hover:underline">{{ $product->title }}</a>
                            </div>
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" variant="ghost" square icon="ellipsis-horizontal" :aria-label="__('Options for :product', ['product' => $product->title])" data-test="uncategorised-more" class="before:absolute before:top-1/2 before:left-1/2 before:size-[max(100%,3rem)] before:-translate-1/2 pointer-fine:before:hidden" />
                                <flux:menu>
                                    <flux:menu.item icon="eye-slash" wire:click="leaveUncategorised('{{ $product->id }}')" data-test="uncategorised-leave">
                                        {{ __('Leave without a category') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-ink/5 pt-2.5 dark:border-white/5">
                            @if ($suggested !== null)
                                <p class="flex min-w-0 flex-1 basis-full items-start gap-2 text-sm/6 text-zinc-600 sm:basis-auto dark:text-zinc-300">
                                    <flux:icon.light-bulb variant="micro" class="h-lh shrink-0 text-chart-line dark:text-chart" />
                                    <span>{{ __('Suggested:') }} <span class="font-medium text-zinc-900 dark:text-white">{{ $suggested->label() }}</span></span>
                                </p>
                                <flux:button size="sm" variant="filled" icon="check" wire:click="useSuggestion('{{ $product->id }}')" :aria-label="__('Use it: :category for :product', ['category' => $suggested->label(), 'product' => $product->title])" data-test="uncategorised-use-suggestion">
                                    {{ __('Use it') }}
                                </flux:button>
                            @endif

                            {{-- A menu, not a select: a choice files the product at once, and a
                                 closed select changes its value on a typed letter. --}}
                            <flux:dropdown position="bottom" align="start">
                                <flux:button size="sm" icon:trailing="chevron-down" data-test="uncategorised-choose">
                                    {{ $suggested === null ? __('Choose a category') : __('Something else') }}<span class="sr-only"> {{ __('for :product', ['product' => $product->title]) }}</span>
                                </flux:button>
                                <flux:menu>
                                    @foreach ($categoryGroups as $departmentValue => $categories)
                                        <flux:menu.submenu :heading="\App\Enums\ProductDepartment::from($departmentValue)->label()">
                                            @foreach ($categories as $leaf)
                                                <flux:menu.item wire:click="choose('{{ $product->id }}', '{{ $leaf->value }}')">{{ $leaf->label() }}</flux:menu.item>
                                            @endforeach
                                        </flux:menu.submenu>
                                    @endforeach
                                </flux:menu>
                            </flux:dropdown>

                            @if ($suggested === null && $canSuggest)
                                <flux:button size="sm" icon="sparkles" wire:click="suggestCategory('{{ $product->id }}')" wire:loading.attr="disabled" wire:target="suggestCategory('{{ $product->id }}')" data-test="uncategorised-suggest">
                                    {{ __('Suggest a category') }}
                                </flux:button>
                            @endif
                        </div>
                        @if (isset($suggestionMessages[$product->id]))
                            <flux:text size="sm" class="mt-2 text-zinc-500 dark:text-zinc-400" data-test="uncategorised-suggest-message">{{ $suggestionMessages[$product->id] }}</flux:text>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($hasMore)
                <flux:link :href="route('app.products.index', ['category' => $noCategoryFilter])" wire:navigate class="mt-3 inline-block text-sm" data-test="uncategorised-see-all">
                    {{ __('See all products without a category') }}
                </flux:link>
            @endif
        </section>
    @endif
</div>
