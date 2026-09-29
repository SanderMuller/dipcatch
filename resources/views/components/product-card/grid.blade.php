@props(['columns' => 5])

<ul role="list" {{ $attributes->class([
    'grid grid-cols-1 gap-4 sm:grid-cols-2',
    'md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5' => $columns === 5,
    'md:grid-cols-3 xl:grid-cols-4' => $columns === 4,
]) }}>
    {{ $slot }}
</ul>
