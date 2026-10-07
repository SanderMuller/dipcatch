{{-- The Pro mark: the same brand-to-violet gradient as every Pro button, so a Pro feature reads as one family. --}}
<span {{ $attributes->class('inline-flex shrink-0 items-center gap-1 rounded-full bg-linear-to-r from-brand to-violet-500 py-0.5 pr-2 pl-1.5 text-xs font-semibold text-white') }}>
    <flux:icon.sparkles variant="micro" class="size-3 shrink-0" />
    {{ __('Pro') }}
</span>
