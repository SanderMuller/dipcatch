@props(['name'])

{{-- The question before "Don't suggest {shop}". One dialog per list: the
     menu item fills `hideHost` and `hideLabel` in the list's Alpine scope and
     opens it, so the rows need no dialog of their own. --}}
<flux:modal :name="$name" class="max-w-md" data-test="hide-shop-confirm">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg" x-text="{{ \Illuminate\Support\Js::from(__('Stop suggesting :shop?')) }}.replace(':shop', hideLabel)"></flux:heading>
            <flux:text class="mt-2" x-text="{{ \Illuminate\Support\Js::from(__('DipCatch won’t suggest :shop on any of your products. Products you track there keep working. You can show it again in Settings, under Hidden shops.')) }}.replaceAll(':shop', hideLabel)"></flux:text>
        </div>

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="filled" data-test="hide-shop-cancel">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="primary" x-on:click="$wire.hideShop(hideHost); $flux.modal({{ \Illuminate\Support\Js::from($name) }}).close()" data-test="hide-shop-confirm-button">
                {{ __('Don’t suggest') }}
            </flux:button>
        </div>
    </div>
</flux:modal>
