@props(['host', 'name' => null, 'favicon' => true])

{{-- A shop by its name, with its favicon. One line, so no space follows the name in the text around it. --}}
@php($name ??= \App\Support\ShopNames::label((string) $host))
<span {{ $attributes->class('inline-flex max-w-full min-w-0 items-center gap-1.5') }}>@if ($favicon)<img src="{{ \App\Support\Favicon::url((string) $host) }}" alt="" loading="lazy" class="size-4 flex-none rounded" />@endif<span class="truncate">{{ $name }}</span></span>