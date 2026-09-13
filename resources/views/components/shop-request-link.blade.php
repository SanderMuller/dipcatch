@props([
    'host' => null,
    'url' => null,
])

@php
    $productUrl = is_string($url) ? $url : null;
    $href = auth()->check()
        ? route('app.support', array_filter([
            'type' => \App\Enums\SupportRequestType::ShopRequest->value,
            'shop_url' => $productUrl,
        ]))
        : \App\Support\ShopRequestMail::href(
            is_string($host) ? $host : null,
            $productUrl,
        );
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'underline underline-offset-4']) }}>{{ $slot->isEmpty() ? __('Request a shop') : $slot }}</a>
@endif
