@props(['route', 'locale', 'routeParams' => []])

<div class="flex items-center rounded-full bg-paper/80 p-0.5 text-[0.6875rem] font-semibold ring-1 ring-line backdrop-blur-sm" role="group" aria-label="{{ __('Language') }}">
    @foreach (['nl' => 'Nederlands', 'en' => 'English'] as $code => $label)
        {{-- href and hreflang stay adjacent on one line: the locale tests
             assert this pair to prove the toggle points at this page's own
             two representations, not another page's. --}}
        <a
            href="{{ route($route, [...$routeParams, 'lang' => $code]) }}" hreflang="{{ $code }}"
            lang="{{ $code }}"
            aria-label="{{ $label }}"
            @if ($locale === $code) aria-current="true" @endif
            @class([
                'rounded-full px-2 py-1 uppercase',
                'bg-ink text-paper' => $locale === $code,
                'text-zinc-600 hover:text-ink dark:text-zinc-400' => $locale !== $code,
            ])
        >{{ $code }}</a>
    @endforeach
</div>
