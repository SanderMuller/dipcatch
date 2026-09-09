<?php declare(strict_types=1);

use App\Livewire\Billing\BillingPage;
use App\Livewire\Settings\NotificationPreferences;
use App\Models\User;
use App\Notifications\TestNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

it('offers no upgrade while the shop is shut', function (): void {
    $this->actingAs(User::factory()->create());

    // BillingGate is closed without Stripe configuration, so there is nothing
    // to sell — but the plan comparison still renders.
    livewire(BillingPage::class)
        ->assertSee('Your plan')
        ->assertDontSee('Start');
});

it('offers the trial to an account that qualifies once Stripe exists', function (): void {
    configureStripe();

    $this->actingAs(User::factory()->create());

    livewire(BillingPage::class)->assertSee('trial');
});

it('does not offer checkout to a blocked account but still reaches the portal', function (): void {
    configureStripe();

    // A lost chargeback drops the account to Free while Stripe may still be
    // billing it: hiding the portal would leave someone paying with no way out.
    $user = User::factory()->create([
        'billing_blocked_at' => now(),
        'stripe_id' => 'cus_blocked',
    ]);

    $this->actingAs($user);

    livewire(BillingPage::class)
        ->assertSee('Pro is on hold')
        ->assertSee('Manage subscription')
        ->assertDontSee('Start 14-day trial');
});

it('shows a pro account no limits', function (): void {
    $user = User::factory()->create();
    subscribeUser($user);

    $this->actingAs($user);

    livewire(BillingPage::class)
        ->assertSee('Pro')
        ->assertSee('No limit');
});

it('round-trips notification preferences including the timezone', function (): void {
    $user = User::factory()->create([
        'notify_via_email' => false,
        'notify_via_filament' => false,
        'timezone_detected_at' => null,
    ]);

    $this->actingAs($user);

    livewire(NotificationPreferences::class)
        ->set('notify_via_email', true)
        ->set('notify_via_filament', true)
        ->set('timezone', 'Europe/Berlin')
        ->call('save');

    $fresh = $user->fresh();

    expect($fresh?->notify_via_email)->toBeTrue()
        ->and($fresh?->notify_via_filament)->toBeTrue()
        ->and($fresh?->timezone)->toBe('Europe/Berlin')
        // An explicit save is the strongest signal of intent, so auto-detection
        // must never overwrite it afterwards.
        ->and($fresh?->timezone_detected_at)->not->toBeNull();
});

it('refuses an invalid timezone rather than storing it', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Amsterdam']);

    $this->actingAs($user);

    livewire(NotificationPreferences::class)
        ->set('timezone', 'Mars/Olympus_Mons')
        ->call('save');

    expect($user->fresh()?->timezone)->toBe('Europe/Amsterdam');
});

it('sends a test notification on request', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    // A saved toggle proves nothing about delivery; this does.
    livewire(NotificationPreferences::class)->call('sendTest');

    Notification::assertSentTo($user, TestNotification::class);
});

it('carries the whole push lifecycle, not just the toggle', function (): void {
    $this->actingAs(User::factory()->create());

    // Rebuilding only the preference would leave a saved channel that cannot
    // deliver: the permission prompt, service worker and subscription all live
    // in the shared partial.
    $this->get(route('app.notifications'))
        ->assertOk()
        ->assertSee('serviceWorker', escape: false)
        ->assertSee('pushManager', escape: false)
        ->assertSee('is not supported on this device', escape: false);
});
