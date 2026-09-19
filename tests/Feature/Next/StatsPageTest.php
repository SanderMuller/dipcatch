<?php declare(strict_types=1);

use App\Livewire\Stats\StatsPage;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;
use App\Notifications\PriceDropNotification;
use App\Notifications\TestNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

it('plots the savings chart once a drop has fired', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id]);
    PriceDropEvent::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'currency' => 'EUR',
        'drop_abs' => '2.00',
        'fired_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($user);

    livewire(StatsPage::class)
        ->assertSee('Savings by month')
        ->assertSeeHtml('<ui-chart')
        ->assertDontSee('<canvas', false);
});

it('explains the empty chart on an account that has never had a drop', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(StatsPage::class)
        ->assertSee('Nothing to plot yet')
        ->assertDontSeeHtml('<ui-chart');
});

it('is reachable for a verified user and closed to a guest', function (): void {
    $this->get(route('app.stats'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('app.stats'))
        ->assertOk()
        ->assertSee('Savings by month');
});

it('lists alerts that have already been read', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Coffee beans 1 kg']);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PriceDropNotification::class,
        'data' => [
            'title' => 'Coffee beans 1 kg',
            'product_id' => $product->id,
            'currency' => 'EUR',
            'drop_percent' => 12.3,
            'drop_absolute' => '1.50',
        ],
        // Read, so the bell has already dropped it.
        'read_at' => now(),
    ]);

    $this->actingAs($user);

    livewire(StatsPage::class)
        ->assertSee('Recent alerts')
        ->assertSee('Coffee beans 1 kg')
        ->assertSee('12.3%')
        ->assertSee('€1.50')
        ->assertSeeHtml('data-flux-timeline');
});

it('states the bundle terms of an alert whose bundle beats the single price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Fanta 1.5 l']);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PriceDropNotification::class,
        'data' => [
            'title' => 'Fanta 1.5 l',
            'product_id' => $product->id,
            'currency' => 'EUR',
            'drop_percent' => 12.3,
            'single_item_price' => '2.85',
            'bundle_quantity' => 2,
            'bundle_total_price' => '4.00',
        ],
    ]);

    $this->actingAs($user);

    livewire(StatsPage::class)->assertSee('2 for €4.00');
});

it('leaves out a bundle that costs more per item than the single price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Fanta 1.5 l']);

    // The bell refuses this row through BundleOffer::stored(); this page read
    // the two columns raw and advertised a bundle dearer than the shelf price.
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PriceDropNotification::class,
        'data' => [
            'title' => 'Fanta 1.5 l',
            'product_id' => $product->id,
            'currency' => 'EUR',
            'drop_percent' => 12.3,
            'single_item_price' => '2.50',
            'bundle_quantity' => 2,
            'bundle_total_price' => '6.00',
        ],
    ]);

    $this->actingAs($user);

    livewire(StatsPage::class)
        ->assertSee('Fanta 1.5 l')
        ->assertDontSee('2 for €6.00');
});

it('leaves out a bundle on a row that carries no single item price', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Fanta 1.5 l']);

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => PriceDropNotification::class,
        'data' => [
            'title' => 'Fanta 1.5 l',
            'product_id' => $product->id,
            'currency' => 'EUR',
            'drop_percent' => 12.3,
            'bundle_quantity' => 2,
            'bundle_total_price' => '4.00',
        ],
    ]);

    $this->actingAs($user);

    livewire(StatsPage::class)
        ->assertSee('Fanta 1.5 l')
        ->assertDontSee('2 for €4.00');
});

it('keeps notifications that are not price alerts out of the history', function (): void {
    // A billing incident and a test notification share the notifications table
    // and carry no product: listed here they would be an empty row.
    $user = User::factory()->create();

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TestNotification::class,
        'data' => ['message' => 'This is a test'],
    ]);

    $this->actingAs($user);

    livewire(StatsPage::class)->assertDontSee('Recent alerts');
});

it('shows no alert history to an account with no alerts', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(StatsPage::class)->assertDontSee('Recent alerts');
});
