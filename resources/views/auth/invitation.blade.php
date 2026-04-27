<x-layouts::auth :title="__('Accept your invitation')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Welcome to DipCatch')"
            :description="__('Set a password to activate your account.')"
        />

        <form
            method="POST"
            action="{{ route('invitation.redeem', ['token' => $invitation->token]) }}"
            class="flex flex-col gap-6"
        >
            @csrf

            <flux:input
                :label="__('Email')"
                type="email"
                :value="$invitation->email"
                disabled
                readonly
            />

            <flux:input
                name="name"
                :label="__('Your name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
            />

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
            />

            <flux:button variant="primary" type="submit" class="w-full">
                {{ __('Create account') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
