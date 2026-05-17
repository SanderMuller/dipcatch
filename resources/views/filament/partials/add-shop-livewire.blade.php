@php
    /** @var \App\Models\Product $record */
    $record = $this->record;
@endphp

<div class="mt-3">
    @livewire('shops.add-shop', ['product' => $record], key('add-shop-' . $record->id))
</div>
