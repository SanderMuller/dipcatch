@php
    /** @var \App\Models\Product $product */
@endphp

<div>
    @livewire('shops.add-shop', ['product' => $product], key('add-shop-modal-' . $product->id))
</div>
