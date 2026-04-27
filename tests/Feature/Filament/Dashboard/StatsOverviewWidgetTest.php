<?php declare(strict_types=1);

use App\Filament\App\Widgets\StatsOverviewWidget;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

test('counts only the current user s active products', function (): void {
    $me = User::factory()->create();
    $other = User::factory()->create();
    Product::factory()->count(3)->for($me)->create(['active' => true]);
    Product::factory()->count(2)->for($me)->inactive()->create();
    Product::factory()->count(4)->for($other)->create(['active' => true]);

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('Tracked products')
        ->assertSeeText('3');
});

test('Active drops counts only products with last_notified_price set, scoped to user', function (): void {
    $me = User::factory()->create();
    $other = User::factory()->create();
    Product::factory()->count(2)->for($me)->create(['last_notified_price' => '49.00']);
    Product::factory()->count(3)->for($me)->create(['last_notified_price' => null]);
    Product::factory()->count(5)->for($other)->create(['last_notified_price' => '10.00']);

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('Active drops')
        ->assertSeeText('2');
});

test('Lifetime savings sums (initial - last) when last < initial, in user default currency', function (): void {
    $me = User::factory()->create(['default_currency' => 'EUR']);
    Product::factory()->for($me)->create([
        'currency' => 'EUR',
        'initial_price' => '100.00',
        'last_price' => '80.00', // saved 20
    ]);
    Product::factory()->for($me)->create([
        'currency' => 'EUR',
        'initial_price' => '50.00',
        'last_price' => '45.00', // saved 5
    ]);
    // Increased price should not count.
    Product::factory()->for($me)->create([
        'currency' => 'EUR',
        'initial_price' => '20.00',
        'last_price' => '30.00',
    ]);

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('Lifetime savings')
        ->assertSeeText('EUR 25.00');
});

test('Lifetime savings shows per-currency breakdown when portfolio is mixed', function (): void {
    $me = User::factory()->create(['default_currency' => 'EUR']);
    Product::factory()->for($me)->create([
        'currency' => 'EUR',
        'initial_price' => '100.00',
        'last_price' => '90.00', // saved 10 EUR
    ]);
    Product::factory()->for($me)->create([
        'currency' => 'USD',
        'initial_price' => '200.00',
        'last_price' => '170.00', // saved 30 USD
    ]);

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('EUR 10.00')
        ->assertSeeText('USD 30.00')
        ->assertSeeText('FX not converted in v1');
});

test('Lifetime savings empty state when no products yet', function (): void {
    $me = User::factory()->create(['default_currency' => 'EUR']);
    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('EUR 0.00')
        ->assertSeeText('No saved cents yet');
});

test('cross-user isolation: another user s products do not leak into savings sum', function (): void {
    $me = User::factory()->create(['default_currency' => 'EUR']);
    $other = User::factory()->create();
    Product::factory()->for($other)->create([
        'currency' => 'EUR',
        'initial_price' => '1000.00',
        'last_price' => '500.00', // would be huge if leaked
    ]);

    $this->actingAs($me);

    livewire(StatsOverviewWidget::class)
        ->assertSeeText('EUR 0.00');
});
