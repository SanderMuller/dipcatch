<?php declare(strict_types=1);

use App\Livewire\Notifications\Bell;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

/**
 * Writes a notification row in this app's shape — no `format` key, which is
 * exactly why Filament's own bell never displayed one.
 *
 * @param  array<string, mixed>  $data
 */
function storeNotification(User $user, array $data = [], ?string $readAt = null): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => PriceDropNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => json_encode([
            'title' => 'Coffee beans 1 kg',
            'currency' => 'EUR',
            'new_price' => '12.49',
            'host' => 'jumbo.com',
            'view_url' => '/app/products/abc',
            ...$data,
        ], JSON_THROW_ON_ERROR),
        'read_at' => $readAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('shows a notification that Filaments own bell would have hidden', function (): void {
    $user = User::factory()->create();
    storeNotification($user);

    $this->actingAs($user);

    // The row carries no `data->format`, which is the filter Filament's bell
    // applies. Rendering it is the whole point of this component.
    livewire(Bell::class)
        ->assertSee('Coffee beans 1 kg')
        ->assertSee('jumbo.com');
});

it('discloses bundle terms in notification rows', function (): void {
    $user = User::factory()->create();
    storeNotification($user, [
        'new_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ]);

    $this->actingAs($user);

    livewire(Bell::class)
        ->assertSee('2 for €4.00')
        ->assertSee('or €2.85 each');
});

it('counts only unread notifications', function (): void {
    $user = User::factory()->create();
    storeNotification($user);
    storeNotification($user);
    storeNotification($user, readAt: now()->toDateTimeString());

    $this->actingAs($user);

    livewire(Bell::class)->assertSee('2');
});

it('clears the badge when everything is marked read', function (): void {
    $user = User::factory()->create();
    storeNotification($user);

    $this->actingAs($user);

    livewire(Bell::class)
        ->call('markAllAsRead')
        ->assertDontSee('Mark all read');

    expect($user->fresh()?->unreadNotifications()->count())->toBe(0);
});

it('marks a single notification read', function (): void {
    $user = User::factory()->create();
    $id = storeNotification($user);

    $this->actingAs($user);

    livewire(Bell::class)->call('markAsRead', $id);

    expect($user->fresh()?->unreadNotifications()->count())->toBe(0);
});

it('never shows another users notifications', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    storeNotification($theirs, ['title' => 'Somebody elses product']);

    $this->actingAs($mine);

    livewire(Bell::class)->assertDontSee('Somebody elses product');
});

it('renders an empty state', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(Bell::class)->assertSee('Nothing yet.');
});

it('renders a notification type that carries no link or price', function (): void {
    $user = User::factory()->create();

    // A billing incident stores kind/title/body and no view_url at all.
    storeNotification($user, [
        'title' => 'Chargeback lost',
        'body' => 'Pro was withdrawn.',
        'view_url' => null,
        'new_price' => null,
        'host' => null,
    ]);

    $this->actingAs($user);

    livewire(Bell::class)
        ->assertSee('Chargeback lost')
        ->assertSee('Pro was withdrawn.');
});

it('keeps showing rows written before in-app alerts were switched off', function (): void {
    $user = User::factory()->create(['notify_via_filament' => true]);
    storeNotification($user);

    $user->forceFill(['notify_via_filament' => false])->save();

    $this->actingAs($user);

    // Turning the preference off stops new rows arriving; it does not delete
    // the history a user already has.
    livewire(Bell::class)->assertSee('Coffee beans 1 kg');
});
