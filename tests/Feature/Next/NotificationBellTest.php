<?php declare(strict_types=1);

use App\Livewire\Notifications\Bell;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\TargetPriceNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

/**
 * Writes a notification row in this app's shape — no `format` key, which is
 * exactly why Filament's own bell never displayed one.
 *
 * @param  array<string, mixed>  $data
 */
function storeNotification(User $user, array $data = [], ?string $readAt = null, string $type = PriceDropNotification::class): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => $type,
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
        ->assertSee('or €2.85 each')
        ->assertSee('title="Regular price"', escape: false);
});

it('leaves out a bundle line whose row carries no single item price', function (): void {
    $user = User::factory()->create();
    storeNotification($user, [
        'new_price' => '2.00',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
    ]);

    $this->actingAs($user);

    // Without the single-item price there is nothing to compare against, so
    // the row cannot state that the bundle is the cheaper way to buy.
    livewire(Bell::class)
        ->assertSee('Coffee beans 1 kg')
        ->assertDontSee('2 for €4.00');
});

it('leaves out a bundle line that costs more per item than the shelf price', function (): void {
    $user = User::factory()->create();
    storeNotification($user, [
        'new_price' => '3.00',
        'single_item_price' => '2.50',
        'bundle_quantity' => 2,
        'bundle_total_price' => '6.00',
    ]);

    $this->actingAs($user);

    livewire(Bell::class)
        ->assertSee('Coffee beans 1 kg')
        ->assertDontSee('2 for €6.00');
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

it('leads a per-unit drop with its unit price and names the pack', function (): void {
    $user = User::factory()->create();
    storeNotification($user, [
        'host' => 'dirk.nl',
        'new_price' => '2.75',
        'new_unit_price' => '12.1145',
        'comparison_unit' => 'g',
        'pack_quantity' => '227',
        'pack_unit' => 'g',
        'drop_percent' => 3.7,
        'reference_unit_price' => '12.5826',
    ]);

    $this->actingAs($user);

    livewire(Bell::class)->assertSeeInOrder(['€12.11 /kg', 'dirk.nl', '€2.75 for 227 g', '↓ 3.7% per kilo · was €12.58 /kg']);
});

it('renders a drop written before the pack size was stored from the unit figures it has', function (): void {
    $user = User::factory()->create();
    storeNotification($user, ['new_price' => '2.75', 'new_unit_price' => '12.1145', 'comparison_unit' => 'g']);

    $this->actingAs($user);

    livewire(Bell::class)
        ->assertSeeInOrder(['€12.11 /kg', '€2.75'])
        ->assertDontSee('€2.75 for');
});

it('renders a row with no unit figures as the pack price it stored', function (): void {
    $user = User::factory()->create();
    storeNotification($user);

    $this->actingAs($user);

    livewire(Bell::class)
        ->assertSeeInOrder(['€12.49', 'jumbo.com'])
        ->assertDontSeeHtml('data-test="bell-unit-figure"');
});

it('renders a unit-target row from the label it stored before the unit code', function (): void {
    $user = User::factory()->create();
    storeNotification($user, ['unit_price' => '0.4500', 'unit_price_label' => '/stuk', 'new_price' => '1.80']);

    $this->actingAs($user);

    livewire(Bell::class)->assertSeeInOrder(['€0.4500 /stuk', 'jumbo.com', '€1.80']);
});

it('leads a target-price row with the pack price and names the better buy', function (): void {
    $user = User::factory()->create();
    storeNotification($user, [
        'host' => 'ah.nl',
        'new_price' => '1.69',
        'target_price' => '1.75',
        'unit_price' => '8.4500',
        'unit' => 'g',
        'pack_quantity' => '200',
        'pack_unit' => 'g',
        'better_value_host' => 'lidl.nl',
        'better_value_unit_price' => '5.3784',
    ], type: TargetPriceNotification::class);

    $this->actingAs($user);

    livewire(Bell::class)
        ->assertDontSeeHtml('data-test="bell-unit-figure"')
        ->assertSeeInOrder(['€1.69 for 200 g', 'ah.nl', '€8.45 /kg', 'Your target: €1.75', 'Better value: €5.38 /kg at lidl.nl']);
});

it('names the unit in the reader\'s language from the stored code', function (): void {
    $user = User::factory()->create();
    storeNotification($user, ['new_price' => '21.99', 'new_unit_price' => '0.0275', 'comparison_unit' => 'piece']);

    app()->setLocale('nl');
    $this->actingAs($user);

    livewire(Bell::class)->assertSee('€0.0275 /stuk');
});
