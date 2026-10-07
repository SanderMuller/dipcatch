<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Product features') }}</flux:heading>

    <x-settings.layout :heading="__('Product features')" :subheading="__('Features that use AI. Both are off until you switch them on.')">
        @if ($available)
            <flux:text>{{ __('DipCatch then sends the product name, its shops and their web addresses, the pack size, barcode and price, any offer a shop shows, and the category of the product to TypeSafe, our AI provider. Nothing about you.') }}</flux:text>

            @php
                $features = [
                    ['auto_categories', 'auto-categories', $allowsAutoCategories, __('Sort new products into a category automatically'), __('Products you add, and the ones already here without a category. A category you chose yourself is never changed.')],
                    ['shop_checks', 'shop-checks', $allowsShopChecks, __('Check new shops, and suggest alerts'), __('When you add a shop, AI compares it with the shops you already track and warns you about a different product or pack size. It also finds more shops that sell your products, and suggests an alert for a new product from how products like it go on sale.')],
                ];
                $anyAllowed = $allowsAutoCategories || $allowsShopChecks;
            @endphp

            <form wire:submit="save" class="my-6 w-full space-y-6">
                {{-- Composed by hand: `flux:switch` takes its label as a
                     string, so it has no room for the badge. The badge
                     shows on Pro too, so a subscriber knows what the plan
                     pays for. A free account sees what each check does,
                     at full strength, and one way to Pro below: a greyed
                     switch it cannot use read as broken. --}}
                @foreach ($features as [$model, $test, $allowed, $label, $description])
                    @if ($allowed)
                        <flux:field variant="inline">
                            <flux:label>
                                {{ $label }}
                                <x-pro-badge class="ms-2" data-test="{{ $test }}-pro" />
                            </flux:label>
                            <flux:description>{{ $description }}</flux:description>
                            <flux:switch wire:model="{{ $model }}" data-test="{{ $test }}" />
                            <flux:error name="{{ $model }}" />
                        </flux:field>
                    @else
                        <div data-test="{{ $test }}-locked">
                            <p class="flex flex-wrap items-center gap-2 text-base font-medium text-ink sm:text-sm dark:text-white">
                                {{ $label }}
                                <x-pro-badge data-test="{{ $test }}-pro" />
                            </p>
                            <p class="mt-1 max-w-[60ch] text-base text-pretty text-zinc-600 sm:text-sm dark:text-zinc-400">{{ $description }}</p>
                        </div>
                    @endif
                @endforeach

                @if (! $allowsAutoCategories || ! $allowsShopChecks)
                    <x-pro-hint data-test="product-features-pro-hint">{{ __('These checks are part of Pro. Once you have it, switch each one on here.') }}</x-pro-hint>
                @endif

                @if ($anyAllowed)
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                @endif
            </form>
        @else
            <flux:text data-test="product-features-unavailable">{{ __('These features are not available right now.') }}</flux:text>
        @endif

        {{-- Not an AI feature, so it shows whether or not the AI is available. --}}
        <livewire:settings.hidden-shops />
    </x-settings.layout>
</section>
