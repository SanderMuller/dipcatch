@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => __('Sign in with a passkey'),
    'loadingLabel' => __('Authenticating...'),
    'separator' => __('Or continue with email'),
    // `button` leads the page with the passkey. `link` demotes it to a line
    // of text below the form, for a page where passkeys are the rare route
    // in and a prominent button would only push the common one down.
    'variant' => 'button',
])

@if (\Laravel\Fortify\Features::canManagePasskeys())
@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async verify() {
            this.loading = true;
            this.error = null;
            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route($optionsRoute) }}',
                        submit: '{{ route($submitRoute) }}',
                    },
                });
                Livewire.navigate(response.redirect || @js(config('fortify.home')));
            } catch (e) {
                if (e?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
    }"
>
    <template x-if="supported">
        @if ($variant === 'link')
            {{--
                Not a grid: a grid child stretches, which gave the link a
                full-width hit area on a phone. `inline-block` keeps the
                target on the words themselves.
            --}}
            <div class="space-y-2 text-sm text-center">
                <button
                    type="button"
                    class="inline-block font-medium underline cursor-pointer text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100 underline-offset-4 disabled:opacity-50"
                    x-on:click="verify()"
                    x-bind:disabled="loading"
                    data-test="passkey-login-link"
                >
                    <span x-show="!loading">{{ $label }}</span>
                    <span x-show="loading" x-cloak>{{ $loadingLabel }}</span>
                </button>
                <p x-show="error" x-text="error" x-cloak
                   class="text-red-600 dark:text-red-400"></p>
            </div>
        @else
            <div>
                <div class="grid gap-2">
                    <flux:button
                        variant="outline"
                        icon="finger-print"
                        class="w-full"
                        x-on:click="verify()"
                        x-bind:disabled="loading"
                    >
                        <span x-show="!loading">{{ $label }}</span>
                        <span x-show="loading" x-cloak>{{ $loadingLabel }}</span>
                    </flux:button>
                    <p x-show="error" x-text="error" x-cloak
                       class="text-sm text-center text-red-600 dark:text-red-400"></p>
                </div>

                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
                    </div>
                    <div class="relative flex justify-center text-xs uppercase">
                        <span class="px-2 text-zinc-500 dark:text-zinc-400 bg-white dark:bg-zinc-900">
                            {{ $separator }}
                        </span>
                    </div>
                </div>
            </div>
        @endif
    </template>
</div>
@endif
