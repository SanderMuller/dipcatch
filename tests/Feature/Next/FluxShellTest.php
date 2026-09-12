<?php declare(strict_types=1);

use App\Models\User;

it('sends an unverified user away from the flux app', function (): void {
    $this->actingAs(User::factory()->unverified()->create());

    // Same rule the Filament panel enforced through its authMiddleware, so the
    // access contract does not change at cutover.
    $this->get(route('app.dashboard'))->assertRedirect();
});

it('sends a guest to login', function (): void {
    $this->get(route('app.dashboard'))->assertRedirect(route('login'));
});

it('lets a verified user in', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))->assertOk();
});

it('offers the admin shortcut only to an admin', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->get(route('app.dashboard'))->assertOk()->assertSee('Admin panel');

    $this->actingAs(User::factory()->create(['is_admin' => false]));
    $this->get(route('app.dashboard'))->assertOk()->assertDontSee('Admin panel');
});

it('still refuses the admin panel to a non-admin', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    // The menu entry is a shortcut, never the thing that grants access.
    $this->get('/admin')->assertForbidden();
});

it('registers every route the migration will need', function (string $name): void {
    expect(app('router')->getRoutes()->getByName($name))->not->toBeNull();
})->with([
    'app.dashboard',
    'app.products.index',
    'app.products.create',
    'app.products.create-manual',
    'app.products.show',
    'app.products.edit',
    'app.billing',
    'app.notifications',
    'app.connections',
]);

it('links the shell navigation at every section', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Products')
        ->assertSee('Plan &amp; billing', escape: false)
        ->assertSee('Notifications')
        ->assertSee('Connections');
});

it('drops the starter kit links', function (): void {
    $this->actingAs(User::factory()->create());

    $body = (string) $this->get(route('app.dashboard'))->getContent();

    expect($body)->not->toContain('livewire-starter-kit')
        ->and($body)->not->toContain('laravel.com/docs/starter-kits');
});

it('detects the timezone on any authenticated page, not only settings', function (): void {
    $this->actingAs(User::factory()->create(['timezone_detected_at' => null]));

    // It fired panel-wide under Filament. A user who never opens settings would
    // otherwise keep a wrong digest hour.
    $this->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('auto-detect', escape: false);
});

it('does not re-detect a timezone the user has already settled', function (): void {
    $this->actingAs(User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'timezone_detected_at' => now(),
    ]));

    $this->get(route('app.dashboard'))
        ->assertOk()
        ->assertDontSee('auto-detect', escape: false);
});
