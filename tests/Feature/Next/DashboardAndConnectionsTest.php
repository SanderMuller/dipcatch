<?php declare(strict_types=1);

use App\Livewire\Connections\ConnectionsPage;
use App\Livewire\Dashboard;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

it('counts only the signed-in users active products', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(2)->create(['user_id' => $user->id, 'active' => true]);
    Product::factory()->create(['user_id' => $user->id, 'active' => false]);
    Product::factory()->create(['active' => true]);

    $this->actingAs($user);

    livewire(Dashboard::class)->assertSee('Tracked products')->assertSee('2');
});

it('invites a new account to track its first product', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(Dashboard::class)->assertSee('Track your first product');
});

it('drops the invitation once something is tracked', function (): void {
    $user = User::factory()->create();
    Product::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    livewire(Dashboard::class)->assertDontSee('Track your first product');
});

it('shows recently tracked products, paused ones included, and nobody elses', function (): void {
    $user = User::factory()->create();
    // Paused: the strip answers "what am I watching", which a paused product
    // is still part of — the active filter belongs to the count above it.
    Product::factory()->create(['user_id' => $user->id, 'title' => 'My paused product', 'active' => false]);
    Product::factory()->create(['title' => 'Someone elses product']);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSee('Recently tracked')
        ->assertSee('My paused product')
        ->assertDontSee('Someone elses product');
});

it('hides the recently tracked strip until something is tracked', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(Dashboard::class)->assertDontSee('Recently tracked');
});

it('lists active drops and nobody elses', function (): void {
    $user = User::factory()->create();
    Product::factory()->create([
        'user_id' => $user->id,
        'title' => 'My dropped product',
        'last_notified_price' => '9.99',
        'last_notified_at' => now(),
    ]);
    Product::factory()->create([
        'title' => 'Someone elses drop',
        'last_notified_price' => '5.00',
        'last_notified_at' => now(),
    ]);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSee('My dropped product')
        ->assertDontSee('Someone elses drop');
});

it('sums lifetime savings in the currency most alerts used', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);

    foreach (['2.50', '1.25'] as $amount) {
        PriceDropEvent::factory()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'currency' => 'EUR',
            'drop_abs' => $amount,
        ]);
    }

    $this->actingAs($user);

    livewire(Dashboard::class)->assertSee('€3.75');
});

it('shows zero savings rather than nothing for a new account', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(Dashboard::class)->assertSee('€0.00');
});

it('shows the mcp endpoint and an empty connection list', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ConnectionsPage::class)
        ->assertSee('/mcp')
        ->assertSee('Nothing connected yet.');
});
