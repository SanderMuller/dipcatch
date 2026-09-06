@props(['href'])

<a
    href="{{ $href }}"
    class="inline-flex items-center rounded-full bg-white/80 px-4 py-2 text-sm font-medium text-zinc-700 ring-1 ring-zinc-200 backdrop-blur-sm hover:text-zinc-900 dark:bg-zinc-900/60 dark:text-zinc-200 dark:ring-zinc-800 dark:hover:text-zinc-50"
>{{ $slot }}</a>
