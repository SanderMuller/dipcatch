<flux:callout icon="information-circle">
    <flux:callout.heading>Multiple variants on this page.</flux:callout.heading>
    <flux:callout.text>Pick the one to track:</flux:callout.text>

    <form wire:submit.prevent="selectVariant" class="mt-4 space-y-3">
        <flux:radio.group variant="cards" wire:model="chosenVariantKey" class="space-y-2">
            @foreach ($variants as $variant)
                <flux:radio
                    :value="$variant['key']"
                    :label="$variant['title']"
                    :description="\App\Support\MoneyFormatter::format($variant['price'], $variant['currency']).' · '.$variant['key']"
                />
            @endforeach
        </flux:radio.group>

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="selectVariant">Use this variant</span>
                <span wire:loading wire:target="selectVariant">Fetching…</span>
            </flux:button>
            <flux:button type="button" wire:click="cancel">Cancel</flux:button>
        </div>
    </form>
</flux:callout>
