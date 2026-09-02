@php
    $primaryBtn = 'inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-60';
    $ghostBtn = 'inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10';
@endphp

<div class="space-y-3 rounded-lg border border-blue-300 bg-blue-50 p-4 dark:border-blue-700 dark:bg-blue-950/30">
    <div class="text-sm">
        <strong class="font-semibold text-blue-900 dark:text-blue-100">Multiple variants on this page.</strong>
        <span class="text-blue-800 dark:text-blue-200">
            Pick the one to track:
        </span>
    </div>

    <form wire:submit.prevent="selectVariant" class="space-y-3">
        <div class="space-y-2">
            @foreach ($variants as $variant)
                <label class="flex items-center gap-3 rounded-md border border-blue-200 bg-white px-3 py-2 cursor-pointer hover:bg-blue-50 dark:border-blue-700 dark:bg-blue-950/40 dark:hover:bg-blue-900/40">
                    <input
                        type="radio"
                        wire:model="chosenVariantKey"
                        value="{{ $variant['key'] }}"
                        class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300"
                    />
                    <div class="flex-1">
                        <div class="font-medium text-sm">{{ $variant['title'] }}</div>
                        <div class="text-xs text-zinc-500 truncate">{{ $variant['key'] }}</div>
                    </div>
                    <div class="text-sm font-semibold whitespace-nowrap tabular-nums">
                        {{ \App\Support\MoneyFormatter::format($variant['price'], $variant['currency']) }}
                    </div>
                </label>
            @endforeach
        </div>

        <div class="flex gap-2">
            <button type="submit" class="{{ $primaryBtn }}" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="selectVariant">Use this variant</span>
                <span wire:loading wire:target="selectVariant">Fetching…</span>
            </button>
            <button type="button" wire:click="cancel" class="{{ $ghostBtn }}">Cancel</button>
        </div>
    </form>
</div>
