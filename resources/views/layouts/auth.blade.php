<x-layouts::auth.simple
    :title="$title ?? null"
    :description="$description ?? null"
    :canonical="$canonical ?? null"
    :robots="$robots ?? null"
>
    {{ $slot }}
</x-layouts::auth.simple>
