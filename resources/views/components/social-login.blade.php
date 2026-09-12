@props([
    'separator' => __('or with email'),
])

@php
    $providers = \App\Enums\SocialProvider::configured();
@endphp

@if ($providers !== [])
    <div class="grid gap-3">
        @foreach ($providers as $provider)
            <flux:button
                variant="outline"
                :href="route('social.redirect', $provider->value)"
                class="w-full"
                data-test="social-login-{{ $provider->value }}"
            >
                <span class="flex items-center justify-center gap-2.5">
                    <x-social-mark :provider="$provider" />
                    {{ __('Continue with :provider', ['provider' => $provider->label()]) }}
                </span>
            </flux:button>
        @endforeach
    </div>

    <div class="relative">
        <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
        </div>
        <div class="relative flex justify-center text-xs uppercase">
            <span class="px-2 text-zinc-500 dark:text-zinc-400 bg-white dark:bg-zinc-900">
                {{ $separator }}
            </span>
        </div>
    </div>
@endif
