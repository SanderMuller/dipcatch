{{--
    Why the suggestion is the price it is, for the suggested row. Needs
    `$suggestion`.
--}}
@php
    $insight = match (true) {
        $suggestion->cappedByLaw => __('Alcohol can go at most :depth% off in the Netherlands.', ['depth' => $suggestion->depth]),
        $suggestion->halfwayToOffer => __('We suggest a price halfway between that and its usual offers.'),
        $suggestion->depthSource === \App\Enums\DepthSource::Jev => __('Products like this often go :depth% off.', ['depth' => $suggestion->depth]),
        $suggestion->depthSource === \App\Enums\DepthSource::Category && $suggestion->category !== null => __(':category often goes about :depth% off.', ['category' => $suggestion->category->label(), 'depth' => $suggestion->depth]),
        default => null,
    };
@endphp
@if ($insight !== null || $suggestion->promotionNowHost !== null)
    <span class="mt-1 flex max-w-[65ch] gap-2 text-sm/5 text-pretty text-zinc-900 dark:text-white" data-test="alert-suggestion-reason">
        <flux:icon.light-bulb variant="micro" class="mt-0.5 shrink-0 text-chart-line dark:text-chart" />
        <span>
            @if ($suggestion->promotionNowHost !== null)
                {{ __(':shop has this :depth% off now.', ['shop' => \App\Support\ShopNames::label($suggestion->promotionNowHost), 'depth' => $suggestion->promotionNowDepth]) }}
            @endif
            {{ $insight }}
        </span>
    </span>
@endif
