<section class="mt-12 space-y-6">
    <div>
        <flux:heading>{{ __('Sign out everywhere') }}</flux:heading>
        <flux:subheading>{{ __('Sign out of every other browser and disconnect every connected app, such as Claude or ChatGPT. This browser stays signed in.') }}</flux:subheading>
    </div>

    <flux:button wire:click="$set('showConfirm', true)" data-test="sign-out-everywhere">{{ __('Sign out everywhere') }}</flux:button>

    <flux:modal name="confirm-sign-out-everywhere" wire:model="showConfirm" focusable class="max-w-lg">
        <form method="POST" wire:submit="signOutEverywhere" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Sign out everywhere?') }}</flux:heading>

                <flux:subheading>
                    {{ __('Every other browser is signed out, and every connected app must be connected again. Your passkeys, two-factor authentication and linked accounts stay, so check them on this page and remove any you do not recognise. Enter your password to confirm.') }}
                </flux:subheading>
            </div>

            <flux:input
                id="sign-out-everywhere-password"
                wire:model="password"
                :label="__('Password')"
                type="password"
                autocomplete="current-password"
                viewable
            />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="primary" type="submit">{{ __('Sign out everywhere') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
