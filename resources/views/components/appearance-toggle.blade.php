{{-- Light and dark only. There is no "system" option: Flux clears
     `flux.appearance` for it, which the light-by-default script in
     partials.head then reads as no choice at all. --}}
<flux:radio.group
    x-data
    x-model="$flux.appearance"
    variant="segmented"
    size="sm"
    :aria-label="__('Appearance')"
    {{ $attributes }}
>
    <flux:radio value="light" icon="sun" :aria-label="__('Light')" />
    <flux:radio value="dark" icon="moon" :aria-label="__('Dark')" />
</flux:radio.group>
