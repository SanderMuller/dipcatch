@props(['host', 'name'])

{{--
    The host of a shop shown by its name, as a tooltip on the link or button
    in the slot: a tooltip needs a trigger that takes focus. Never inside a
    <p>, which the tooltip's own <div> would close.
--}}
@if ($name === $host)
    {{ $slot }}
@else
    <flux:tooltip :content="$host" class="inline-flex max-w-full min-w-0">
        {{ $slot }}
    </flux:tooltip>
@endif
