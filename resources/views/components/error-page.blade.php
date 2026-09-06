@props(['code', 'heading', 'body'])

<div class="flex min-h-dvh flex-col">
    <header class="mx-auto w-full max-w-3xl px-6 pt-8 lg:px-8">
        <a href="{{ url('/') }}" class="inline-flex items-center gap-2 font-semibold">
            <x-app-logo-icon class="size-6 fill-current" />
            {{ config('app.name') }}
        </a>
    </header>

    <main class="mx-auto flex w-full max-w-3xl flex-1 flex-col justify-center px-6 pb-20 lg:px-8">
        <p class="text-sm font-semibold text-zinc-500 tabular-nums dark:text-zinc-400">{{ $code }}</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $heading }}</h1>
        <p class="mt-3 max-w-xl text-base text-zinc-600 dark:text-zinc-300">{{ $body }}</p>

        <div class="mt-8 flex flex-wrap items-center gap-3">
            {{ $slot }}
        </div>
    </main>
</div>
