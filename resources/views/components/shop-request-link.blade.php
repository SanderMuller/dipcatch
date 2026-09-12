@props([
    'host' => null,
    'url' => null,
])

@php
    $href = \App\Support\ShopRequestMail::href(
        is_string($host) ? $host : null,
        is_string($url) ? $url : null,
    );
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'underline underline-offset-4']) }}>{{ $slot->isEmpty() ? __('Request a shop') : $slot }}</a>
@endif
