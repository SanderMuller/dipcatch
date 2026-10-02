@props(['href', 'host'])

@if ($href !== null)
    <a href="{{ \App\Support\AffiliateLink::for($href) }}" target="_blank" rel="{{ \App\Support\AffiliateLink::rel($href) }}" {{ $attributes->class('inline-flex items-center gap-1 text-sm font-medium text-zinc-700 underline decoration-zinc-400 underline-offset-4 hover:text-brand dark:text-zinc-300') }}>
        {{ __('Open the page on :shop', ['shop' => $host]) }}
        <flux:icon.arrow-top-right-on-square variant="micro" class="size-4" />
    </a>
@endif
