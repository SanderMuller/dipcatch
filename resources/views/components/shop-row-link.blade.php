@props([
    'shop' => null,
    'deadline' => true,
])

{{--
    The shop behind a price in a table row: its logo, its host, and a link out
    to the page that sells it. Renders nothing without a shop, so a row with no
    price stays empty.

    `deadline` adds the promotion window ("until 6 Sep"). The caller turns it
    off when a bundle label above this line already states it.
--}}
@if ($shop)
    @php($window = $deadline ? \App\Support\PromotionLabel::short($shop) : null)

    {{-- The link and the deadline are written on one line on purpose: a line
         break between them lands in the page's text as a newline, and the row
         then reads "ah.nl" and "· until 6 Sep" as two separate strings. --}}
    <flux:text size="sm" class="max-w-xs text-zinc-500 whitespace-normal"><a href="{{ $shop->url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center hover:underline underline-offset-4">{!! \App\Support\Favicon::html($shop->host) !!}</a>@if ($window) · {{ $window }}@endif</flux:text>
@endif
