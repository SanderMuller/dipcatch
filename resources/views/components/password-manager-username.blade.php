@props([
    'email',
])

<flux:input
    id="username"
    name="username"
    :label="__('Email')"
    type="email"
    :value="$email"
    autocomplete="username"
    readonly
/>
