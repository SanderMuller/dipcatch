<x-mail::message>
# {{ $totalDrops === 1 ? '1 price drop today' : $totalDrops . ' price drops today' }}

Your tracked prices changed:

@foreach ($grouped as $group)
@php
    /** @var \App\Models\Product $product */
    $product = $group['product'];
    /** @var \Illuminate\Support\Collection<int, \App\Models\PriceDropEvent> $events */
    $events = $group['events'];
@endphp

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%; margin: 24px 0 8px; border: 1px solid #e4e4e7; border-radius: 8px; border-collapse: separate; overflow: hidden;">
<tr>
<td colspan="2" style="padding: 18px 20px; border-bottom: 1px solid #e4e4e7; color: #18181b; font-size: 17px; font-weight: 700; line-height: 1.3;">
{{ $product->title }}
</td>
</tr>
<tr>
@if (! empty($product->image_url))
<td width="120" valign="top" style="width: 120px; padding: 20px 0 20px 20px; vertical-align: top;">
<img src="{{ $product->image_url }}" alt="{{ $product->title }}" width="100" style="display: block; width: 100px; max-width: 100%; height: auto; max-height: 140px; object-fit: contain;">
</td>
@endif
<td valign="top" style="padding: 20px; vertical-align: top;">
@foreach ($events as $event)
@php
    $bundle = $event->priceCheck?->bundleOffer();
    $singleItemPrice = $event->priceCheck?->singleItemPrice();
@endphp
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%; margin: 0 0 {{ $loop->last ? '0' : '16px' }}; border-collapse: collapse;">
<tr>
<td style="padding: 0 0 8px; color: #71717a; font-size: 13px; line-height: 1.4;">
{{ $event->triggeredByShop?->host ?? 'Unknown shop' }} · {{ $event->fired_at->setTimezone($user->timezone)->format('H:i') }}
</td>
</tr>
<tr>
<td style="padding: 0 0 12px; color: #18181b; line-height: 1.2;">
<span style="font-size: 24px; font-weight: 700;">{{ \App\Support\MoneyFormatter::format($event->new_price, $event->currency) }}</span>
<span style="display: inline-block; margin-left: 8px; padding: 4px 8px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 12px; font-weight: 700; white-space: nowrap;">↓ {{ number_format((float) $event->drop_pct, 1, '.', '') }}% · {{ \App\Support\MoneyFormatter::format($event->drop_abs, $event->currency) }}</span>
</td>
</tr>
@if ($bundle !== null)
<tr>
<td style="padding: 12px 14px; border: 1px solid #fde68a; border-radius: 6px; background: #fffbeb; color: #18181b; font-size: 14px; line-height: 1.5;">
<span style="display: inline-block; margin-right: 7px; padding: 2px 7px; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em;">Deal</span>
<strong>{{ \App\Support\BundlePriceLabel::condition($bundle, $event->currency) }}</strong>
@if ($singleItemPrice !== null)
<span style="color: #71717a;"> · Normal price: {{ \App\Support\MoneyFormatter::format($singleItemPrice, $event->currency) }} each</span>
@endif
</td>
</tr>
@endif
</table>
@endforeach
</td>
</tr>
</table>

<x-mail::button :url="route('app.products.show', $product)">
View {{ $product->title }}
</x-mail::button>

@endforeach

You get this email because daily digests are on. You can change this in DipCatch notification settings.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
