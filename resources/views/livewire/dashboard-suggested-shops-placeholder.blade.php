<section class="min-w-0" data-test="suggested-shops">
    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">{{ __('Add suggested shops to your products') }}</flux:heading>
    <flux:text size="sm" class="mt-0.5 text-zinc-500 dark:text-zinc-400">{{ __('Looking for other shops that sell your products…') }}</flux:text>

    <flux:card class="mt-4 space-y-4 p-4!" aria-hidden="true">
        @foreach (range(1, 4) as $line)
            <div class="space-y-2">
                <flux:skeleton animate="pulse" class="h-4 w-2/3" />
                <flux:skeleton animate="pulse" class="h-3 w-1/2" />
            </div>
        @endforeach
    </flux:card>
</section>
