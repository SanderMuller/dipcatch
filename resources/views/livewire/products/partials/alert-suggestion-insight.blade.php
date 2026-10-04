{{--
    Why the suggestion is the price it is, in one line with a light bulb, for
    the suggested-alert card and the suggested row on the edit form. Needs
    `$suggestion`; `$compact` sets the row's smaller type.
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
    <span @class([
        'flex max-w-[65ch] gap-2 text-pretty text-zinc-900 dark:text-white',
        'mt-1 text-sm/5' => $compact ?? false,
        'mt-3 text-base/7 sm:text-sm/6' => ! ($compact ?? false),
    ]) data-test="alert-suggestion-reason">
        <flux:icon.light-bulb :variant="($compact ?? false) ? 'micro' : 'mini'" @class(['shrink-0 text-chart-line dark:text-chart', 'mt-0.5' => $compact ?? false, 'mt-1 sm:mt-0.5' => ! ($compact ?? false)]) />
        <span>
            @if ($suggestion->promotionNowHost !== null)
                {{ __(':shop has this :depth% off now.', ['shop' => $suggestion->promotionNowHost, 'depth' => $suggestion->promotionNowDepth]) }}
            @endif
            {{ $insight }}
        </span>
    </span>
@endif
