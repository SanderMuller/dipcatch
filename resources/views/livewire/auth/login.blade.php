<x-layouts::auth :title="__('Sign in to DipCatch')" robots="noindex">
    <div class="flex flex-col gap-6">
        @php
            // Named providers only when they are actually on the page. With a
            // provider unconfigured the buttons disappear but this line would
            // still promise it.
            $providerNames = array_map(
                fn (\App\Enums\SocialProvider $provider): string => $provider->label(),
                \App\Enums\SocialProvider::configured(),
            );
        @endphp

        <x-auth-header
            :title="__('Log in to your account')"
            :description="$providerNames === []
                ? __('Enter your email and password below to log in')
                : __('Continue with :providers, or use your email and password', ['providers' => implode(__(' or '), $providerNames)])"
        />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-social-login />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                id="email"
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="username"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    id="password"
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        {{--
            Below the form on purpose. Almost nobody arrives here with a
            passkey, so it stays out of the way of the two routes that carry
            the traffic, and the people who did set one up still find it.
        --}}
        <x-passkey-verify variant="link" />

        @if (Route::has('register'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('Don\'t have an account?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
