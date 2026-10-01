<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Product features') }}</flux:heading>

    <x-settings.layout :heading="__('Product features')" :subheading="__('Features that use AI. Both are off until you switch them on.')">
        @if ($available)
            <flux:text>{{ __('DipCatch then sends the product name, its shops and their web addresses, the pack size, barcode and price, any offer a shop shows, and the category of the product to TypeSafe, our AI provider. Nothing about you.') }}</flux:text>

            <form wire:submit="save" class="my-6 w-full space-y-6">
                {{-- Composed by hand: `flux:switch` takes its label as a
                     string, so it has no room for the badge. The badge
                     shows on Pro too, so a subscriber knows what the plan
                     pays for. --}}
                <flux:field variant="inline">
                    <flux:label>
                        {{ __('Sort new products into a category automatically') }}
                        <flux:badge size="sm" color="zinc" class="ms-2" data-test="auto-categories-pro">{{ __('Pro') }}</flux:badge>
                    </flux:label>
                    <flux:description>
                        {{ $allowsAutoCategories
                            ? __('Products you add, and the ones already here without a category. A category you chose yourself is never changed.')
                            : __('Pro sorts products for you. Your choice is kept, and it starts working when you upgrade.') }}
                    </flux:description>
                    <flux:switch
                        wire:model="auto_categories"
                        :disabled="! $allowsAutoCategories"
                        data-test="auto-categories"
                    />
                    <flux:error name="auto_categories" />
                </flux:field>

                <flux:field variant="inline">
                    <flux:label>
                        {{ __('Check new shops, and suggest alerts') }}
                        <flux:badge size="sm" color="zinc" class="ms-2">{{ __('Pro') }}</flux:badge>
                    </flux:label>
                    <flux:description>
                        {{ $allowsShopChecks
                            ? __('When you add a shop, AI compares it with the shops you already track and warns you about a different product or pack size. It also finds more shops that sell your products, and suggests an alert for a new product from how products like it go on sale.')
                            : __('Pro checks new shops for you. Your choice is kept, and it starts working when you upgrade.') }}
                    </flux:description>
                    <flux:switch
                        wire:model="shop_checks"
                        :disabled="! $allowsShopChecks"
                        data-test="shop-checks"
                    />
                    <flux:error name="shop_checks" />
                </flux:field>

                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </form>
        @else
            <flux:text data-test="product-features-unavailable">{{ __('These features are not available right now.') }}</flux:text>
        @endif

        {{-- Not an AI feature, so it shows whether or not the AI is available. --}}
        <livewire:settings.hidden-shops />
    </x-settings.layout>
</section>
