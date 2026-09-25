@props(['href'])

<a
    href="{{ $href }}"
    class="inline-flex items-center rounded-full bg-paper/80 px-4 py-2.5 text-base font-medium text-ink shadow-xs ring-1 ring-line backdrop-blur-sm hover:bg-paper sm:text-sm dark:shadow-none"
>{{ $slot }}</a>
