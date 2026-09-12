<div>
    <flux:modal.trigger name="app-command" shortcut="cmd.k">
        <flux:button variant="ghost" icon="magnifying-glass" :aria-label="__('Search')" />
    </flux:modal.trigger>

    <flux:modal name="app-command" variant="bare" class="my-[12vh] max-h-screen w-full max-w-[30rem] overflow-y-hidden">
        <flux:command class="inline-flex max-h-[76vh] flex-col border-none shadow-lg">
            <flux:command.input :placeholder="__('Search pages and recent products…')" closable autocomplete="off" data-1p-ignore />
            <flux:command.items>
                <flux:command.item icon="home" :href="route('app.dashboard')" wire:navigate>
                    {{ __('Dashboard') }}
                </flux:command.item>
                <flux:command.item icon="shopping-bag" :href="route('app.products.index')" wire:navigate>
                    {{ __('Products') }}
                </flux:command.item>
                <flux:command.item icon="plus" :href="route('app.products.create')" wire:navigate>
                    {{ __('Track a product') }}
                </flux:command.item>
                <flux:command.item icon="credit-card" :href="route('app.billing')" wire:navigate>
                    {{ __('Plan & billing') }}
                </flux:command.item>
                <flux:command.item icon="bell" :href="route('app.notifications')" wire:navigate>
                    {{ __('Notifications') }}
                </flux:command.item>
                <flux:command.item icon="puzzle-piece" :href="route('app.connections')" wire:navigate>
                    {{ __('Connections') }}
                </flux:command.item>
                <flux:command.item icon="cog-6-tooth" :href="route('profile.edit')" wire:navigate>
                    {{ __('Settings') }}
                </flux:command.item>

                @foreach ($products as $product)
                    <flux:command.item
                        icon="shopping-bag"
                        :href="route('app.products.show', $product)"
                        wire:navigate
                        wire:key="command-product-{{ $product->id }}"
                    >
                        {{ Str::limit($product->title, 48) }}
                    </flux:command.item>
                @endforeach
            </flux:command.items>
        </flux:command>
    </flux:modal>
</div>
