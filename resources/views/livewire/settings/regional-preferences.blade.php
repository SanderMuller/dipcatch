<section class="mt-10 space-y-6">
    <div>
        <flux:heading>{{ __('Regional') }}</flux:heading>
        <flux:subheading>{{ __('The timezone your daily email follows, the country DipCatch finds shops in, and the currency new products start in') }}</flux:subheading>
    </div>

    <form wire:submit="save" class="w-full space-y-6">
        <flux:select wire:model="timezone" variant="listbox" searchable :label="__('Timezone')" :placeholder="__('Search time zones…')">
            @foreach ($timezones as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model="country" variant="listbox" searchable :label="__('Country')" :description="__('Suggested shops are ones that sell to shoppers in this country.')" :placeholder="__('Search countries…')" data-test="country-select">
            @foreach ($countries as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="default_currency" :label="__('Default currency')" maxlength="3" />

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
