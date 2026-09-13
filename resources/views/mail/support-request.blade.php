<x-mail::message>
# {{ $requestTypeLabel }}

**From:** {{ $user->name }} ({{ $user->email }})  
**User ID:** {{ $user->getKey() }}

@if ($shopUrl !== null)
**Shop URL:** <a href="{{ $shopUrl }}">{{ $shopUrl }}</a>
@endif

<div style="white-space: pre-wrap">{{ $message }}</div>

Reply to this email to answer {{ $user->name }}.
</x-mail::message>
