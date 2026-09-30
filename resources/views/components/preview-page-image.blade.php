@props(['images' => [], 'href' => null, 'host' => '', 'name'])

{{-- Callers pass URLs already checked by ImageUrl::safe(); this component
     checks none. no-referrer: some shop CDNs refuse a foreign referer. --}}
@php($first = $images[0] ?? null)

@php($zoom = "{ zoomed: false, x: 50, y: 50, move(event) { const box = \$el.getBoundingClientRect(); this.x = (event.clientX - box.left) / box.width * 100; this.y = (event.clientY - box.top) / box.height * 100 } }")

@if ($first === null)
    <div {{ $attributes->class(['size-36 sm:size-40 flex shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700 outline-1 -outline-offset-1 outline-black/5 dark:bg-amber-950/60 dark:text-amber-300 dark:outline-white/10']) }}>
        <flux:icon.photo />
    </div>
@else
    <flux:modal.trigger :name="$name">
        <button
            type="button"
            x-data="{{ $zoom }}"
            x-on:mouseenter="zoomed = window.matchMedia('(hover: hover)').matches"
            x-on:mouseleave="zoomed = false"
            x-on:mousemove="move($event)"
            aria-label="{{ trans_choice('Show the photo full size|Show the :count photos full size', count($images), ['count' => count($images)]) }}"
            {{ $attributes->class(['size-36 sm:size-40 relative block shrink-0 cursor-zoom-in overflow-hidden rounded-xl bg-white outline-1 -outline-offset-1 outline-black/5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand dark:bg-white/90 dark:outline-white/10']) }}
            data-test="preview-photo"
        >
            <div class="absolute inset-0 flex items-center justify-center bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                <flux:icon.photo />
            </div>
            <img
                src="{{ $first }}"
                alt=""
                referrerpolicy="no-referrer"
                class="relative size-full bg-white object-contain p-1.5 transition-transform duration-75 dark:bg-white/90"
                x-bind:style="zoomed ? `transform: scale(2.2); transform-origin: ${x}% ${y}%` : ''"
                x-on:error="$el.remove()"
                data-test="preview-image"
            />
            @if (count($images) > 1)
                <span class="absolute right-1.5 bottom-1.5 rounded-full bg-zinc-900/75 px-1.5 py-0.5 text-xs font-medium text-white tabular-nums" data-test="preview-photo-count">
                    {{ trans_choice(':count photo|:count photos', count($images), ['count' => count($images)]) }}
                </span>
            @endif
        </button>
    </flux:modal.trigger>

    <flux:modal :name="$name" class="w-full md:max-w-3xl" data-test="preview-photo-dialog">
        <div x-data="{ index: 0, images: @js($images) }" class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Photos on :shop', ['shop' => $host]) }}</flux:heading>

            <div
                x-data="{{ $zoom }}"
                x-on:mouseenter="zoomed = window.matchMedia('(hover: hover)').matches"
                x-on:mouseleave="zoomed = false"
                x-on:mousemove="move($event)"
                class="relative aspect-square max-h-[65vh] w-full cursor-zoom-in overflow-hidden rounded-xl bg-white"
            >
                <img
                    x-bind:src="images[index]"
                    src="{{ $first }}"
                    alt="{{ __('Photo :number of :count on :shop', ['number' => 1, 'count' => count($images), 'shop' => $host]) }}"
                    x-bind:alt="{{ \Illuminate\Support\Js::from(__('Photo :number of :count on :shop', ['count' => count($images), 'shop' => $host])) }}.replace(':number', index + 1)"
                    referrerpolicy="no-referrer"
                    class="size-full object-contain p-3"
                    x-bind:style="zoomed ? `transform: scale(2.5); transform-origin: ${x}% ${y}%` : ''"
                    data-test="preview-photo-large"
                />
            </div>

            @if (count($images) > 1)
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Photos') }}">
                    @foreach ($images as $i => $image)
                        <button
                            type="button"
                            x-on:click="index = {{ $i }}"
                            x-bind:aria-pressed="index === {{ $i }} ? 'true' : 'false'"
                            x-bind:class="index === {{ $i }} ? 'outline-2 outline-brand' : 'outline-1 outline-black/10'"
                            aria-label="{{ __('Photo :number', ['number' => $i + 1]) }}"
                            class="size-16 overflow-hidden rounded-lg bg-white -outline-offset-1 focus-visible:outline-offset-2 focus-visible:ring-2 focus-visible:ring-brand"
                        >
                            <img src="{{ $image }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="size-full object-contain p-1" x-on:error="$el.closest('button').remove()" />
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="flex flex-wrap gap-x-5 gap-y-2">
                {{-- The zoom above needs a hovering pointer; this is the way for keyboard and touch. --}}
                <a x-bind:href="images[index]" href="{{ $first }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-sm font-medium text-zinc-700 underline decoration-zinc-400 underline-offset-4 hover:text-brand dark:text-zinc-300">
                    {{ __('Open this photo in a new tab') }}
                    <flux:icon.arrow-top-right-on-square variant="micro" class="size-4" />
                </a>
                <x-open-page-link :href="$href" :host="$host" />
            </div>
        </div>
    </flux:modal>
@endif
