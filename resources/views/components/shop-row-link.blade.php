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

    {{-- A flex row, because the favicon markup is an inline-flex box whose
         baseline is the image's bottom edge: as inline text the deadline sat
         a few pixels below the host. The space inside the span keeps the
         page's text reading "ah.nl · until 6 Sep" as one string. --}}
    <flux:text size="sm" class="flex max-w-xs flex-wrap items-center gap-x-1 text-zinc-500 dark:text-zinc-400 whitespace-normal"><a href="{{ $shop->url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center hover:underline underline-offset-4">{!! \App\Support\Favicon::html($shop->host) !!}</a>@if ($window)<span> · {{ $window }}</span>@endif</flux:text>
@endif
