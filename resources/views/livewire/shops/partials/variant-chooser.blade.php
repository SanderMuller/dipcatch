<flux:callout icon="information-circle">
    <flux:callout.heading>Multiple variants on this page.</flux:callout.heading>
    <flux:callout.text>Pick the one to track:</flux:callout.text>

    <form wire:submit.prevent="selectVariant" class="mt-4 space-y-3">
        <flux:radio.group variant="cards" wire:model="chosenVariantKey" class="space-y-2">
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
</flux:callout>
