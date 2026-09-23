@props(['line', 'bundle' => true, 'marker' => true])

{{-- `€21.99 for 800 pieces`, the estimate marker, and the deal terms unless
     a deal box beside it states them. --}}
<span {{ $attributes->class('tabular-nums') }} data-test="pack-line">
    {{ \App\Support\PackLine::format($line->price, $line->shop->currency, $line->size) }}
    @if ($marker && $line->estimated)
        <flux:tooltip content="{{ __('No pack size on this page. Taken from the other shops on this product, which all agree.') }}">
            <flux:badge size="sm" color="zinc">{{ __('estimated') }}</flux:badge>
        </flux:tooltip>
    @endif
    @if ($bundle && $line->bundle !== null)
        <span>· {{ $line->bundle }}</span>
    @endif
</span>
