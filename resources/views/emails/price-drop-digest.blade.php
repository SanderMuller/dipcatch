<x-mail::message>
# {{ $heading }}

{{ $totalReached === 0 ? 'Prices dropped on what you follow:' : 'What changed on what you follow:' }}

@foreach ($grouped as $group)
@php
    /** @var \App\Models\Product $product */
    $product = $group['product'];
    /** @var \Illuminate\Support\Collection<int, \App\Models\PriceDropEvent> $events */
    $events = $group['events'];
    /** @var \Illuminate\Support\Collection<int, \App\Models\TargetPriceEvent> $reached */
    $reached = $group['reached'];
@endphp

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%; margin: 24px 0 8px; border: 1px solid #e4e4e7; border-radius: 8px; border-collapse: separate; overflow: hidden;">
<tr>
<td colspan="2" style="padding: 18px 20px; border-bottom: 1px solid #e4e4e7; color: #18181b; font-size: 17px; font-weight: 700; line-height: 1.3;">
{{ $product->title }}
</td>
</tr>
<tr>
@php
$image = $product->safeImageUrl();
@endphp
@if ($image !== null)
<td width="120" valign="top" style="width: 120px; padding: 20px 0 20px 20px; vertical-align: top;">
<img src="{{ $image }}" alt="{{ $product->title }}" width="100" style="display: block; width: 100px; max-width: 100%; height: auto; max-height: 140px; object-fit: contain;">
</td>
@endif
<td valign="top" style="padding: 20px; vertical-align: top;">
@foreach ($reached as $hit)
@php
    $perUnit = $hit->isPerUnit();
    $unitLabel = \App\Support\UnitWord::labelFor($hit->comparison_unit);
    $yourPrice = $perUnit
        ? \App\Support\MoneyFormatter::unitPrice((string) $hit->target, $hit->currency) . ' ' . $unitLabel
        : \App\Support\MoneyFormatter::format((string) $hit->target, $hit->currency);
@endphp
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%; margin: 0 0 {{ $loop->last && $events->isEmpty() ? '0' : '16px' }}; border-collapse: collapse;">
<tr>
<td style="padding: 0 0 8px; color: #71717a; font-size: 13px; line-height: 1.4;">
{{ $hit->shop?->host ?? 'Shop unknown' }} · {{ $hit->fired_at->setTimezone($user->timezone)->format('H:i') }}
</td>
</tr>
<tr>
<td style="padding: 0 0 12px; color: #18181b; line-height: 1.2;">
@if ($perUnit)
<span style="font-size: 24px; font-weight: 700;">{{ \App\Support\MoneyFormatter::unitPrice((string) $hit->unit_price, $hit->currency) }} {{ $unitLabel }}</span>
@else
<span style="font-size: 24px; font-weight: 700;">{{ \App\Support\MoneyFormatter::format($hit->price === null ? null : (string) $hit->price, $hit->currency) }}</span>
@endif
<span style="display: inline-block; margin-left: 8px; padding: 4px 8px; border-radius: 999px; background: #dbeafe; color: #1e40af; font-size: 12px; font-weight: 700; white-space: nowrap;">Reached your price</span>
<div style="margin-top: 4px; color: #71717a; font-size: 13px;">@if ($perUnit && $hit->price !== null){{ \App\Support\PackLine::format((string) $hit->price, $hit->currency, $hit->packSize()) }} · @endif your price {{ $yourPrice }}</div>
</td>
</tr>
@if ($hit->deal !== null)
<tr>
<td style="padding: 12px 14px; border: 1px solid #fde68a; border-radius: 6px; background: #fffbeb; color: #18181b; font-size: 14px; line-height: 1.5;">
<span style="display: inline-block; margin-right: 7px; padding: 2px 7px; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em;">Deal</span>
<strong>{{ $hit->deal }}</strong>
</td>
</tr>
@endif
</table>
@endforeach
@foreach ($events as $event)
@php
    $bundle = $event->priceCheck?->bundleOffer();
    $singleItemPrice = $event->priceCheck?->singleItemPrice();
@endphp
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%; margin: 0 0 {{ $loop->last ? '0' : '16px' }}; border-collapse: collapse;">
<tr>
<td style="padding: 0 0 8px; color: #71717a; font-size: 13px; line-height: 1.4;">
{{ $event->triggeredByShop?->host ?? 'Shop unknown' }} · {{ $event->fired_at->setTimezone($user->timezone)->format('H:i') }}
</td>
</tr>
<tr>
<td style="padding: 0 0 12px; color: #18181b; line-height: 1.2;">
@php
    // Per unit first when the drop was measured per unit: that is the figure
    // that fell. The pack price follows as what the shop charges. Two
    // different pack sizes have no honest money difference, so the money is
    // shown only when there is one.
    $unitWord = \App\Support\UnitWord::forCode($event->comparison_unit);
    $perUnit = $event->comparison_unit !== null && $event->new_unit_price !== null;
    $unitLabel = \App\Support\UnitWord::labelFor($event->comparison_unit);
    $changeLabel = '↓ ' . number_format((float) $event->drop_pct, 1, '.', '') . '%'
        . ($unitWord === null ? '' : ' ' . $unitWord);

    if ($event->drop_abs !== null) {
        $changeLabel .= ' · ' . \App\Support\MoneyFormatter::format((string) $event->drop_abs, $event->currency);
    }
@endphp
@if ($perUnit)
<span style="font-size: 24px; font-weight: 700;">{{ \App\Support\MoneyFormatter::unitPrice((string) $event->new_unit_price, $event->currency) }} {{ $unitLabel }}</span>
@else
<span style="font-size: 24px; font-weight: 700;">{{ \App\Support\MoneyFormatter::format($event->new_price, $event->currency) }}</span>
@if ($bundle !== null && $singleItemPrice !== null)
<del title="Regular price" style="margin-left: 8px; color: #a1a1aa; font-size: 16px;">{{ \App\Support\MoneyFormatter::format($singleItemPrice, $event->currency) }}</del>
@endif
@endif
<span style="display: inline-block; margin-left: 8px; padding: 4px 8px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 12px; font-weight: 700; white-space: nowrap;">{{ $changeLabel }}</span>
@if ($perUnit)
<div style="margin-top: 4px; color: #71717a; font-size: 13px;">{{ \App\Support\PackLine::format((string) $event->new_price, $event->currency, $event->packSize()) }}@if ($bundle !== null && $singleItemPrice !== null) <del title="Regular price" style="color: #a1a1aa;">{{ \App\Support\MoneyFormatter::format($singleItemPrice, $event->currency) }}</del>@endif @if ($event->reference_unit_price !== null)· {{ __('was') }} {{ \App\Support\MoneyFormatter::unitPrice((string) $event->reference_unit_price, $event->currency) }} {{ $unitLabel }}@endif</div>
@endif
</td>
</tr>
@if ($bundle !== null)
<tr>
<td style="padding: 12px 14px; border: 1px solid #fde68a; border-radius: 6px; background: #fffbeb; color: #18181b; font-size: 14px; line-height: 1.5;">
<span style="display: inline-block; margin-right: 7px; padding: 2px 7px; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em;">Deal</span>
<strong>{{ \App\Support\BundlePriceLabel::condition($bundle, $event->currency) }}</strong>
@if ($singleItemPrice !== null)
<span style="color: #71717a;"> · Normal price: <del title="Regular price">{{ \App\Support\MoneyFormatter::format($singleItemPrice, $event->currency) }}</del> each</span>
@endif
</td>
</tr>
@endif
</table>
@endforeach
</td>
</tr>
</table>

@php
    // Shops DipCatch cannot read, named at the moment the reader is about to
    // buy. Hosts only: a link holds no price.
    $alsoCheck = \App\Support\AlsoWorthChecking::line(\App\Support\AlsoWorthChecking::of($product));
@endphp
@if ($alsoCheck !== null)
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%; margin: 8px 0 0; border-collapse: collapse;">
<tr>
<td style="padding: 0; color: #71717a; font-size: 13px; line-height: 1.5;">{{ $alsoCheck }}</td>
</tr>
</table>
@endif

<x-mail::button :url="route('app.products.show', $product)">
View {{ $product->title }}
</x-mail::button>

@endforeach

You get this email once a day because you asked us to. You can change that in your DipCatch notification settings.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
