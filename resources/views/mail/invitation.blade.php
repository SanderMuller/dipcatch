<x-mail::message>
# You're invited to DipCatch

{{ $inviterName }} has invited you to join DipCatch. It tracks product prices and alerts you when they drop.

<x-mail::button :url="$redeemUrl">
Accept invitation
</x-mail::button>

This invitation expires {{ $expiresAt->toDayDateTimeString() }} (UTC).

If you weren't expecting this email, you can ignore it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
