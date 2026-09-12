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
