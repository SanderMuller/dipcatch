<div class="min-w-0 flex-1">
    {{-- A search field is the centre of the header, as on a shop site. It is a
         button, not an input: the palette modal owns the real input. --}}
    <flux:modal.trigger name="app-command" shortcut="cmd.k">
        <button
            type="button"
            class="hidden w-full items-center gap-3 rounded-full bg-white/70 px-4 py-2 text-start text-sm text-zinc-500 ring-1 ring-zinc-900/5 transition hover:bg-white sm:flex dark:bg-zinc-900/70 dark:text-zinc-400 dark:ring-white/10 dark:hover:bg-zinc-900"
            data-test="app-search-bar"
        >
            <flux:icon.magnifying-glass class="size-5 shrink-0" />
            <span class="truncate">{{ __('Search pages and products…') }}</span>
        </button>
    </flux:modal.trigger>

    <flux:modal.trigger name="app-command">
        <flux:button variant="ghost" icon="magnifying-glass" :aria-label="__('Search')" class="sm:hidden" />
    </flux:modal.trigger>

    <flux:modal name="app-command" variant="bare" class="my-[12vh] max-h-screen w-full max-w-[30rem] overflow-y-hidden">
        <flux:command class="inline-flex max-h-[76vh] flex-col border-none shadow-lg">
            <flux:command.input :placeholder="__('Search pages and recent products…')" closable autocomplete="off" data-1p-ignore />
            <flux:command.items>
                <flux:command.item icon="home" :href="route('app.dashboard')" wire:navigate keywords="home overview start trips week">
                    {{ __('Dashboard') }}
                </flux:command.item>
                <flux:command.item icon="shopping-bag" :href="route('app.products.index')" wire:navigate keywords="tracked list all watch">
                    {{ __('Products') }}
                </flux:command.item>
                <flux:command.item icon="list-bullet" :href="route('app.shopping-list')" wire:navigate keywords="groceries boodschappen buy print">
                    {{ __('Shopping list') }}
                </flux:command.item>
                <flux:command.item icon="chart-bar" :href="route('app.stats')" wire:navigate keywords="savings statistics chart history month alerts sent">
                    {{ __('Stats') }}
                </flux:command.item>
                <flux:command.item icon="plus" :href="route('app.products.create')" wire:navigate keywords="add new product link url watch follow">
                    {{ __('Track a product') }}
                </flux:command.item>
                <flux:command.item icon="credit-card" :href="route('app.billing')" wire:navigate keywords="pro upgrade subscription payment invoice stripe plan price">
                    {{ __('Plan & billing') }}
                </flux:command.item>
                <flux:command.item icon="bell" :href="route('app.notifications')" wire:navigate keywords="alerts email push digest price drop">
                    {{ __('Notification settings') }}
                </flux:command.item>
                <flux:command.item icon="puzzle-piece" :href="route('app.connections')" wire:navigate keywords="mcp claude chatgpt openai assistant ai agent integration connect">
                    {{ __('Connections') }}
                </flux:command.item>
                <flux:command.item icon="lifebuoy" :href="route('app.support')" wire:navigate keywords="help contact question bug problem feedback">
                    {{ __('Support') }}
                </flux:command.item>
                <flux:command.item icon="cog-6-tooth" :href="route('profile.edit')" wire:navigate keywords="profile account password email security two-factor passkey appearance dark mode">
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
