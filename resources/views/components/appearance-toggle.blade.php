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
    <flux:radio value="system" icon="computer-desktop" :aria-label="__('System')" />
</flux:radio.group>
