<x-mail::message>
# Price drop on {{ $product->title }}

@if (! empty($product->image_url))
<img src="{{ $product->image_url }}" alt="{{ $product->title }}" style="max-width: 320px; height: auto; border-radius: 6px;">
@endif

**{{ $product->title }}** is now **{{ $product->currency }} {{ $newPrice }}**.

|                            |                                                                                          |
|----------------------------|------------------------------------------------------------------------------------------|
| Reference ({{ $referenceKind }}) | {{ $product->currency }} {{ $referencePrice }}                                          |
| Drop                       | {{ $dropPercent }}% &nbsp;/&nbsp; {{ $product->currency }} {{ $dropAbsolute }}           |

<x-mail::button :url="$viewUrl">
View product
</x-mail::button>

You're receiving this because price-drop email alerts are enabled for your account. Manage notification preferences in DipCatch.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
