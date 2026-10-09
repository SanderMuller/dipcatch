@props(['product', 'size' => 'size-12', 'thumbnail' => false])

{{-- The image sits on top of the placeholder tile rather than replacing it:
     a scraped URL can 404 long after it was stored, and only the browser
     knows. On an error the <img> hides itself and the tile shows through,
     so a row never collapses to a broken-image glyph.

     safeImageUrl() and not image_url: the value is scraped markup or user
     input, and a `javascript:` payload must never reach an <img src>.
     `thumbnail` asks the shop's image server for a small copy, for a tile of
     64 px at most. --}}
@php
    $src = $product->safeImageUrl();
    $src = $src !== null && $thumbnail ? \App\Support\ImageUrl::thumbnail($src) : $src;
@endphp

<div {{ $attributes->class([
        $size,
        'relative shrink-0 overflow-hidden rounded-xl bg-white outline-1 -outline-offset-1 outline-black/5 dark:bg-white/5 dark:outline-white/10',
    ]) }}
     @if($src) x-data="{ loaded: false }" @endif>
    <div class="flex size-full items-center justify-center
                bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
        <flux:icon.photo variant="micro"/>
    </div>

    @if($src)
        {{-- The white backing for transparent photos waits for the image, so a
             photo still loading shows the tile, not a blank white box. x-show,
             not a class: a Livewire morph resets classes Alpine added. --}}
        <div x-show="loaded"
             x-cloak
             class="absolute inset-0 bg-paper dark:bg-white/90"></div>

        <img src="{{ $src }}"
             alt=""
             loading="lazy"
             class="absolute inset-0 size-full object-contain"
             {{-- A cached image fired its load or error event before Alpine
                  bound the listener, and neither fires twice. --}}
             x-init="if ($el.complete) { $el.naturalWidth === 0 ? $el.remove() : (loaded = true) }"
             x-on:load="loaded = true"
             x-on:error="$el.remove()"/>
    @endif
</div>
