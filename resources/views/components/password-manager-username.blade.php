@props([
    'email',
])

{{-- Password managers skip type=hidden and display:none. Keep a clipped
     readonly email in the layout so they can bind the nearby password. --}}
<input
    type="email"
    name="username"
    value="{{ $email }}"
    autocomplete="username"
    readonly
    tabindex="-1"
    aria-hidden="true"
    class="sr-only"
>
