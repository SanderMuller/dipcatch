@props(['route', 'locale'])

<div class="flex items-center rounded-full bg-white/80 p-0.5 text-[0.6875rem] font-semibold ring-1 ring-zinc-200 backdrop-blur-sm dark:bg-zinc-900/80 dark:ring-zinc-800" role="group" aria-label="{{ __('Language') }}">
    @foreach (['nl' => 'Nederlands', 'en' => 'English'] as $code => $label)
        {{-- href and hreflang stay adjacent on one line: the locale tests
             assert this pair to prove the toggle points at this page's own
             two representations, not another page's. --}}
        <a
            href="{{ route($route, ['lang' => $code]) }}" hreflang="{{ $code }}"
            lang="{{ $code }}"
            aria-label="{{ $label }}"
            @if ($locale === $code) aria-current="true" @endif
            @class([
                'rounded-full px-2 py-1 uppercase',
                'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $locale === $code,
                'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100' => $locale !== $code,
            ])
        >{{ $code }}</a>
    @endforeach
</div>
