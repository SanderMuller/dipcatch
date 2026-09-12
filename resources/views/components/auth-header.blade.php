@props([
    'title',
    'description',
    // Each auth screen is its own document, so its title is the page's h1.
    // Flux renders a plain <div> when no level is given, which left the
    // indexable registration page with no heading at all.
    'level' => 1,
])

<div class="flex w-full flex-col text-center">
    <flux:heading size="xl" :level="$level">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
