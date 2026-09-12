<x-layouts::app.sidebar :title="$title ?? null">
    {{-- A pale wash over the amber body, not a second gradient: it lifts the
         content field just enough for the amber sidebar to read as its own
         surface, the way the marketing header sits above the page. --}}
    <flux:main class="bg-white/45 dark:bg-transparent">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
