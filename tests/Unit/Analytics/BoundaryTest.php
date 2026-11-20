<?php declare(strict_types=1);

/*
 * The analytics core must stay free of DipCatch code, so it can move to a
 * package without a rewrite (specs/visitor-analytics.md, section 9). A new
 * top-level namespace under `app/` belongs in this list too.
 */
arch('analytics core stays app-agnostic')
    ->expect('App\Analytics')
    ->not->toUse(array_map(
        static fn (string $namespace): string => 'App\\' . $namespace,
        ['Actions', 'Billing', 'Charts', 'Concerns', 'Console', 'Enums', 'Filament', 'Health', 'Http', 'Jobs', 'Listeners', 'Livewire', 'Mail', 'Mcp', 'Models', 'Notifications', 'Policies', 'PriceAdapters', 'Providers', 'Rules', 'Services', 'Support'],
    ));
