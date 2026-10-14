@props(['shop'])

{{-- Favicon plus name, linking out to the shop, with its host as the tooltip. --}}
<x-host-tooltip :host="$shop->host" :name="$shop->displayName()">
<a
    href="{{ \App\Support\AffiliateLink::for($shop->url) }}"
    target="_blank"
    rel="{{ \App\Support\AffiliateLink::rel($shop->url) }}"
    {{ $attributes->merge(['class' => 'inline-flex max-w-full min-w-0 items-center hover:underline underline-offset-4']) }}
>
    <x-shop-name :host="$shop->host" :name="$shop->displayName()" />
</a>
</x-host-tooltip>
