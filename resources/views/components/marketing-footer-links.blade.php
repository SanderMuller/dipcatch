@props(['langQuery' => [], 'contactEmail' => null, 'home' => false])

@php
    $useCases = \App\Support\UseCases::all();
    $shops = \App\Support\ShopPages::all();
@endphp

{{-- Replaces three drifted per-page footers, so a new landing page is linked
     everywhere at once. --}}
<div class="border-t border-zinc-200 pt-6 dark:border-zinc-800">
    @if ($useCases !== [])
        <nav class="flex flex-wrap items-baseline gap-x-5 gap-y-2 text-sm text-zinc-500 dark:text-zinc-400" aria-label="{{ __('Price alerts by category') }}">
            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Price alerts for') }}</span>
            @foreach ($useCases as $useCase)
                <a href="{{ route('use-case', [...$langQuery, 'slug' => $useCase->slug]) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ $useCase->heading }}</a>
            @endforeach
        </nav>
    @endif

    @if ($shops !== [])
        <nav @class(['flex flex-wrap items-baseline gap-x-5 gap-y-2 text-sm text-zinc-500 dark:text-zinc-400', 'mt-4' => $useCases !== []]) aria-label="{{ __('Price alerts by shop') }}">
            <a href="{{ route('shops', $langQuery) }}" class="font-medium text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100">{{ __('Supported shops') }}</a>
            @foreach ($shops as $shop)
                <a href="{{ route('shop', [...$langQuery, 'slug' => $shop->slug]) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ $shop->name }}</a>
            @endforeach
        </nav>
    @endif

    <div @class(['flex flex-col items-center justify-between gap-3 text-sm text-zinc-500 sm:flex-row dark:text-zinc-400', 'mt-6' => $useCases !== [] || $shops !== []])>
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
        <nav class="flex items-center gap-5" aria-label="{{ __('Footer') }}">
            @if ($home)
                <a href="{{ route('home', $langQuery) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Back to the homepage') }}</a>
            @endif
            <a href="{{ route('pricing', $langQuery) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Pricing') }}</a>
            <a href="{{ route('privacy', $langQuery) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Privacy') }}</a>
            <a href="{{ route('terms', $langQuery) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Terms') }}</a>
            <a href="{{ route('support', $langQuery) }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Support') }}</a>
            @if (filled($contactEmail))
                <a href="mailto:{{ $contactEmail }}" class="hover:text-zinc-900 dark:hover:text-zinc-100">{{ __('Contact') }}</a>
            @endif
        </nav>
    </div>
</div>
