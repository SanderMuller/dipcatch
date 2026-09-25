@props(['code', 'heading', 'body'])

<div class="flex min-h-dvh flex-col">
    <header class="mx-auto w-full max-w-app px-6 py-4 lg:px-8">
        <a href="{{ url('/') }}" class="inline-flex items-center gap-2 font-semibold">
            <span class="flex aspect-square size-8 items-center justify-center rounded-xl bg-white p-0.5">
                <x-app-logo-icon class="size-7" />
            </span>
            {{ config('app.name') }}
        </a>
    </header>

    <main class="mx-auto flex w-full max-w-app flex-1 flex-col justify-center px-6 pb-20 lg:px-8">
        <p class="text-sm font-semibold text-brand tabular-nums">{{ $code }}</p>
        <h1 class="mt-2 max-w-[35ch] text-4xl font-semibold tracking-tight text-balance sm:text-5xl">{{ $heading }}</h1>
        <p class="mt-4 max-w-[48ch] text-lg text-pretty text-zinc-600 dark:text-zinc-400">{{ $body }}</p>

        <div class="mt-8 flex flex-wrap items-center gap-3">
            {{ $slot }}
        </div>
    </main>
</div>
