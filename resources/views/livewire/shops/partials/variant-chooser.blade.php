{{-- No box of its own: on the product page the add-shop panel is the box. --}}
<div data-test="variant-chooser">
    <p class="flex items-center gap-2 text-base/7 font-medium text-zinc-900 sm:text-sm/6 dark:text-white">
        <flux:icon.information-circle variant="mini" class="shrink-0 text-zinc-400" />
        {{ __('Multiple variants on this page.') }}
    </p>
    <p id="variant-chooser-label" class="mt-1 text-base/7 text-zinc-500 sm:text-sm/6 dark:text-zinc-400">{{ __('Pick the one to track:') }}</p>

    <form wire:submit.prevent="selectVariant" class="mt-4 space-y-3">
        <flux:radio.group variant="cards" wire:model="chosenVariantKey" aria-labelledby="variant-chooser-label" class="flex-col! wrap-anywhere">
            @foreach ($variants as $variant)
                {{-- Per unit first when the variant's name states a size, so a
                     page selling a small and a big pack can be compared here. --}}
                @php($variantSize = \App\Support\PackSize::resolve(null, false, $variant['title']))
                @php($variantUnitPrice = is_string($variant['price']) ? $variantSize?->unitPriceFor($variant['price']) : null)
                <flux:radio
                    :value="$variant['key']"
                    :label="$variant['title']"
                    :description="($variantUnitPrice === null ? '' : \App\Support\MoneyFormatter::unitPrice($variantUnitPrice, $variant['currency']).' '.$variantSize->label().' · ').\App\Support\PackLine::format(is_string($variant['price']) ? $variant['price'] : null, $variant['currency'], $variantSize).' · '.$variant['key']"
                />
            @endforeach
        </flux:radio.group>

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">
                <span wire:loading.remove wire:target="selectVariant">Use this variant</span>
                <span wire:loading wire:target="selectVariant">Looking it up…</span>
            </flux:button>
            <flux:button type="button" wire:click="cancel">Cancel</flux:button>
        </div>
    </form>
</div>
