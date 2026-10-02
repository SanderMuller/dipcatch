@props(['shop'])

{{-- Favicon plus host, linking out to the shop. --}}
<a
    href="{{ \App\Support\AffiliateLink::for($shop->url) }}"
    target="_blank"
    rel="{{ \App\Support\AffiliateLink::rel($shop->url) }}"
    {{ $attributes->merge(['class' => 'inline-flex max-w-full min-w-0 items-center hover:underline underline-offset-4']) }}
>
    {!! \App\Support\Favicon::html($shop->host) !!}
</a>
