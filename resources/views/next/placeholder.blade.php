<x-layouts::app :title="__('DipCatch')">
    {{-- Replaced phase by phase; the route exists from Phase 1 so the shell's
         navigation and the other phases' links resolve while they are built. --}}
    <flux:heading size="xl">{{ __('Coming in this migration') }}</flux:heading>
    <flux:text class="mt-2">{{ __('This page has not been rebuilt yet.') }}</flux:text>
</x-layouts::app>
