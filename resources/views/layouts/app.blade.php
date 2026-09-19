<x-layouts::app.sidebar :title="$title ?? null">
    {{-- No sidebar to offset now, so this is a plain centred column rather
         than `flux:main`, which reserves space for one. --}}
    <main class="mx-auto w-full max-w-app px-4 py-6 sm:px-6 lg:px-8">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
