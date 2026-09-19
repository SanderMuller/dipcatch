<?php declare(strict_types=1);

use App\Livewire\Settings\NotificationPreferences;
use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

beforeEach(function (): void {});

test('page hydrates with the user current preferences', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => false,
        'notify_via_filament' => true,
        'notify_via_push' => false,
        'default_currency' => 'USD',
    ]);

    $this->actingAs($user);

    livewire(NotificationPreferences::class)
        ->assertSet('notify_via_email', false)
        ->assertSet('notify_via_filament', true)
        ->assertSet('notify_via_push', false)
        ->assertSet('default_currency', 'USD');
});

test('save persists toggles + currency', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => true,
        'notify_via_filament' => true,
        'notify_via_push' => false,
        'default_currency' => 'EUR',
    ]);
    $this->actingAs($user);

    livewire(NotificationPreferences::class)
        ->set('notify_via_email', false)
        ->set('notify_via_filament', false)
        ->set('notify_via_push', true)
        ->set('default_currency', 'GBP')
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->notify_via_email)->toBeFalse()
        ->and($user->notify_via_filament)->toBeFalse()
        ->and($user->notify_via_push)->toBeTrue()
        ->and($user->default_currency)->toBe('GBP');
});

test('test action dispatches a TestNotification to the current user', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'notify_via_email' => true,
        'notify_via_filament' => true,
        'notify_via_push' => false,
    ]);
    $this->actingAs($user);

    livewire(NotificationPreferences::class)
        ->call('sendTest')
        ->assertDispatched('toast-show');

    Notification::assertSentTo($user, TestNotification::class);
});

test('the automatic categories switch is absent when no key is configured', function (): void {
    config()->set('services.typesafe.key', '');
    $this->actingAs(User::factory()->create());

    livewire(NotificationPreferences::class)
        ->assertDontSee('Sort new products into a category automatically');
});

test('a free account sees the automatic categories switch disabled with the upgrade note', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $this->actingAs(User::factory()->create());

    $html = livewire(NotificationPreferences::class)
        ->assertSee('Sort new products into a category automatically')
        ->assertSee('Pro sorts products for you. Your choice is kept, and it starts working when you upgrade.')
        ->html();

    expect($html)->toMatch('/disabled="disabled"[^>]*data-test="auto-categories"/');
});

test('a Pro account sees the automatic categories switch enabled', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $user = User::factory()->create();
    subscribeUser($user);
    $this->actingAs($user);

    $html = livewire(NotificationPreferences::class)
        ->assertSee('Products you add from now on. Products you already track keep their category.')
        ->assertDontSee('Pro sorts products for you.')
        ->html();

    expect($html)->toMatch('/data-test="auto-categories"/')
        ->not->toMatch('/disabled="disabled"[^>]*data-test="auto-categories"/');
});

test('save persists the automatic categories choice', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $user = User::factory()->create(['auto_categories' => false]);
    $this->actingAs($user);

    livewire(NotificationPreferences::class)
        ->assertSet('auto_categories', false)
        ->set('auto_categories', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->auto_categories)->toBeTrue();
});
