@props(['shop'])

{{-- The host links to the product page itself. Left as plain text, mail
     apps turned "amazon.nl" into a link to the shop's homepage. --}}
@if ($shop === null)
Shop unknown
@else
<a href="{{ $shop->url }}" style="color: #2563eb; text-decoration: underline;">{{ $shop->host }}</a>
@endif
