<?php declare(strict_types=1);

use App\Livewire\Settings\NotificationPreferences;
use App\Livewire\Settings\RegionalPreferences;
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
    ]);

    $this->actingAs($user);

    livewire(NotificationPreferences::class)
        ->assertSet('notify_via_email', false)
        ->assertSet('notify_via_filament', true)
        ->assertSet('notify_via_push', false);
});

test('save persists the channel toggles', function (): void {
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
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->notify_via_email)->toBeFalse()
        ->and($user->notify_via_filament)->toBeFalse()
        ->and($user->notify_via_push)->toBeTrue();
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

test('the regional settings live on the profile page and save there', function (): void {
    $user = User::factory()->create(['default_currency' => 'EUR', 'timezone' => 'Europe/Amsterdam']);
    $this->actingAs($user);

    $this->get(route('profile.edit'))->assertOk()->assertSeeLivewire(RegionalPreferences::class);

    livewire(RegionalPreferences::class)
        ->assertSet('default_currency', 'EUR')
        ->set('default_currency', 'GBP')
        ->set('timezone', 'Europe/London')
        ->call('save')
        ->assertHasNoErrors();

    $user->refresh();
    expect($user->default_currency)->toBe('GBP')
        ->and($user->timezone)->toBe('Europe/London')
        ->and($user->timezone_detected_at)->not->toBeNull();
});

test('the old notifications address sends people to the settings tab', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/app/notifications')->assertRedirect('/settings/notifications');
    $this->get(route('notifications.edit'))->assertOk()->assertSee('Notifications');
});

test('the product features tab shows only where the AI features exist', function (): void {
    $this->actingAs(User::factory()->create());

    config()->set('services.typesafe.key', '');
    $this->get(route('profile.edit'))->assertOk()->assertDontSeeHtml('data-test="settings-nav-product-features"');

    config()->set('services.typesafe.key', 'test-key');
    $this->get(route('profile.edit'))->assertOk()->assertSeeHtml('data-test="settings-nav-product-features"');
});

test('the default currency must be a real currency code', function (): void {
    $user = User::factory()->create(['default_currency' => 'EUR']);
    $this->actingAs($user);

    livewire(RegionalPreferences::class)->set('default_currency', 'EURO')->call('save')->assertHasErrors('default_currency');
    livewire(RegionalPreferences::class)->set('default_currency', 'gbp')->call('save')->assertHasNoErrors();

    expect($user->refresh()->default_currency)->toBe('GBP');
});
