<x-mail::message>
# {{ $totalDrops === 1 ? '1 price drop today' : $totalDrops . ' price drops today' }}

Here's what changed since your last digest:

@foreach ($grouped as $group)
@php
    /** @var \App\Models\Product $product */
    $product = $group['product'];
    /** @var \Illuminate\Support\Collection<int, \App\Models\PriceDropEvent> $events */
    $events = $group['events'];
@endphp

---

## {{ $product->title }}

@if (! empty($product->image_url))
<img src="{{ $product->image_url }}" alt="{{ $product->title }}" style="max-width: 240px; height: auto; border-radius: 6px;">
@endif

| When | Shop | New price | Drop |
|------|------|-----------|------|
@foreach ($events as $event)
| {{ $event->fired_at->setTimezone($user->timezone)->format('H:i') }} | {{ $event->triggeredByShop?->host ?? '—' }} | {{ $event->currency }} {{ number_format((float) $event->new_price, 2, '.', '') }} | {{ number_format((float) $event->drop_pct, 1, '.', '') }}% / {{ $event->currency }} {{ number_format((float) $event->drop_abs, 2, '.', '') }} |
@endforeach

<x-mail::button :url="\App\Filament\App\Resources\Products\ProductResource::getUrl('view', ['record' => $product])">
View {{ $product->title }}
</x-mail::button>

@endforeach

You're receiving this because daily email digests are enabled for your account. Manage notification preferences in DipCatch.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
