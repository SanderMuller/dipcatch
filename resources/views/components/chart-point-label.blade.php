@props([
    // The `data-series` of the points to label; the right-most one gets it.
    'series',
    // `above` the point, or `below-left` of it: under the line's end, where
    // only the fill is, so it hides none of the line.
    'placement' => 'above',
])

{{--
    A label on a Flux chart point, without a hover. Flux plots the point, so
    the label finds it once it is drawn, and again whenever the chart redraws
    or changes size. Kept inside the chart's box.
--}}
<div
    x-data="{
        left: null,
        top: 0,
        place() {
            const chart = $el.parentElement;

            if (! chart) {
                return;
            }

            const point = [...chart.querySelectorAll('circle[data-point][data-series={{ $series }}]')]
                .sort((a, b) => a.getAttribute('cx') - b.getAttribute('cx'))
                .pop();
            const box = chart.getBoundingClientRect();
            const dot = point?.getBoundingClientRect();

            if (! dot || dot.width === 0) {
                this.left = null;

                return;
            }

            const centreX = dot.left + dot.width / 2 - box.left;
            const centreY = dot.top + dot.height / 2 - box.top;
            const belowLeft = @js($placement) === 'below-left';
            const left = belowLeft ? centreX - $el.offsetWidth - 8 : centreX - $el.offsetWidth / 2;
            const top = belowLeft ? centreY + 10 : dot.top - box.top - $el.offsetHeight - 8;

            this.left = Math.min(Math.max(left, 4), box.width - $el.offsetWidth - 4);
            this.top = Math.min(Math.max(0, top), box.height - $el.offsetHeight);
        },
    }"
    x-init="
        $nextTick(() => requestAnimationFrame(() => place()));
        new ResizeObserver(() => place()).observe($el.parentElement);
        new MutationObserver(() => place()).observe($el.parentElement, { childList: true, subtree: true, attributeFilter: ['cx', 'cy'] });
    "
    x-bind:class="left === null && 'invisible'"
    x-bind:style="{ left: `${left ?? 0}px`, top: `${top}px` }"
    {{ $attributes->class('pointer-events-none absolute z-10 rounded-lg bg-paper px-2.5 py-1.5 shadow-lg shadow-ink/10 ring-1 ring-line dark:shadow-none') }}
>
    {{ $slot }}
</div>
