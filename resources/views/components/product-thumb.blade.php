@props(['product', 'size' => 'size-12'])

{{-- The image sits on top of the placeholder tile rather than replacing it:
     a scraped URL can 404 long after it was stored, and only the browser
     knows. On an error the <img> hides itself and the tile shows through,
     so a row never collapses to a broken-image glyph.

     safeImageUrl() and not image_url: the value is scraped markup or user
     input, and a `javascript:` payload must never reach an <img src>. --}}
@php($src = $product->safeImageUrl())

<div {{ $attributes->class([$size, 'relative shrink-0 overflow-hidden rounded-xl bg-white outline-1 -outline-offset-1 outline-black/5 dark:bg-white/5 dark:outline-white/10']) }}>
    <div class="flex size-full items-center justify-center bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
        <flux:icon.photo variant="micro" />
    </div>

    @if ($src)
        <img
            src="{{ $src }}"
            alt=""
            loading="lazy"
            class="absolute inset-0 size-full bg-white object-contain dark:bg-white/90"
            x-data
            {{-- A cached image that already failed fired its error event before
                 Alpine bound the listener, and an error never fires twice. --}}
            x-init="if ($el.complete && $el.naturalWidth === 0) { $el.remove(); }"
            x-on:error="$el.remove()"
        />
    @endif
</div>
