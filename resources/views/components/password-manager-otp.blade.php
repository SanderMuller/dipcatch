<flux:otp
    name="code"
    id="code"
    :label="__('Authentication code')"
    autocomplete="one-time-code"
    {{ $attributes }}
>
    <flux:otp.input autocomplete="one-time-code" />
    @for ($i = 1; $i < 6; $i++)
        <flux:otp.input autocomplete="off" data-1p-ignore />
    @endfor
</flux:otp>
