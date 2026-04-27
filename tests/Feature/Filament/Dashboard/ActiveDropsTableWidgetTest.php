<?php declare(strict_types=1);

use App\Filament\App\Widgets\ActiveDropsTableWidget;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

test('lists only the current user s products with last_notified_price set', function (): void {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $latched = Product::factory()->for($me)->create([
        'last_notified_price' => '49.00',
        'last_notified_at' => now(),
    ]);
    $unnotified = Product::factory()->for($me)->create(['last_notified_price' => null]);
    $strangers = Product::factory()->for($other)->create(['last_notified_price' => '10.00']);

    $this->actingAs($me);

    livewire(ActiveDropsTableWidget::class)
        ->assertCanSeeTableRecords([$latched])
        ->assertCanNotSeeTableRecords([$unnotified, $strangers]);
});

test('sorted newest-notified first', function (): void {
    $me = User::factory()->create();
    $older = Product::factory()->for($me)->create([
        'last_notified_price' => '40.00',
        'last_notified_at' => now()->subHours(5),
    ]);
    $newer = Product::factory()->for($me)->create([
        'last_notified_price' => '20.00',
        'last_notified_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($me);

    livewire(ActiveDropsTableWidget::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

test('renders the empty state when nothing is below threshold', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ActiveDropsTableWidget::class)
        ->assertSeeText('No active drops right now');
});
