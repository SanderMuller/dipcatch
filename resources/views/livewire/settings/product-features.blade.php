<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Product features') }}</flux:heading>

    <x-settings.layout width="max-w-2xl" :heading="__('Product features')" :subheading="__('Features that use AI. Both are off until you switch them on.')">
        @if ($available)
            @php
                $shopChecksDoes = \App\Enums\AiFeature::ShopChecks->does();
                $categoriesDoes = \App\Enums\AiFeature::Categories->does();
                $isProPlan = $allowsAutoCategories || $allowsShopChecks;
                $privacy = __('DipCatch sends the product name, its shops and their web addresses, the pack size, barcode and price, any offer a shop shows, and the category of the product to TypeSafe, our AI provider. Nothing about you.');
            @endphp

            {{-- Every check as its own line, under the switch that runs it. A free
                 account sees what each switch would do at full strength, and one
                 way to Pro: a greyed switch it cannot use read as broken. --}}
            <form wire:submit="save" class="space-y-8">
                @foreach ([
                    ['shop_checks', 'shop-checks', \App\Enums\AiFeature::ShopChecks->label(), $shopChecksDoes, $allowsShopChecks],
                    ['auto_categories', 'auto-categories', \App\Enums\AiFeature::Categories->label(), $categoriesDoes, $allowsAutoCategories],
                ] as [$model, $test, $title, $does, $allowed])
                    <section @unless ($allowed) data-test="{{ $test }}-locked" @endunless>
                        <div class="flex items-center justify-between gap-4 border-b border-ink/10 pb-3 dark:border-white/10">
                            <h3 class="flex items-center gap-2 text-base font-semibold text-ink dark:text-white">
                                {{ $title }}
                                <x-pro-badge data-test="{{ $test }}-pro" />
                            </h3>
                            @if ($allowed)
                                <flux:switch wire:model="{{ $model }}" :aria-label="$title" data-test="{{ $test }}" />
                                <flux:error name="{{ $model }}" />
                            @else
                                @if (\App\Billing\ProPitch::for(auth()->user())?->canBuy === true)
                                    <a href="{{ route('app.pro') }}" wire:navigate class="text-sm font-medium text-brand hover:text-violet-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:text-blue-300">{{ __('Unlock with Pro') }}</a>
                                @endif
                            @endif
                        </div>
                        <ul role="list" class="mt-3 grid gap-3">
                            @foreach ($does as $line)
                                <li class="flex items-start gap-2 text-base text-zinc-700 sm:text-sm dark:text-zinc-300">
                                    <flux:icon.check variant="micro" class="h-lh shrink-0 text-savings-strong dark:text-savings" />
                                    {{ $line }}
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach

                @if (! $isProPlan)
                    <x-pro-hint data-test="product-features-pro-hint">{{ __('Both checks come with Pro. Once you have it, switch them on here.') }}</x-pro-hint>
                @else
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                @endif

                <p class="rounded-xl bg-ink/[0.03] p-4 text-base text-pretty text-zinc-600 sm:text-sm dark:bg-white/5 dark:text-zinc-400">
                    <span class="font-medium text-ink dark:text-white">{{ __('What the AI sees.') }}</span>
                    {{ $privacy }}
                </p>
            </form>
        @else
            <flux:text data-test="product-features-unavailable">{{ __('These features are not available right now.') }}</flux:text>
        @endif

        {{-- Not an AI feature, so it shows whether or not the AI is available. --}}
        <livewire:settings.hidden-shops />
    </x-settings.layout>
</section>
