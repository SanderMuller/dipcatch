<?php declare(strict_types=1);

use App\Livewire\Connections\ConnectionsPage;
use App\Livewire\Dashboard;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

it('discloses bundle terms beside dashboard effective prices', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'currency' => 'EUR',
        'cheapest_price' => '2.00',
        'last_notified_price' => '2.75',
        'last_notified_at' => now(),
    ]);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://jumbo.com/producten/fanta-cassis',
        'current_price' => '2.00',
        'single_item_price' => '2.85',
        'bundle_quantity' => 2,
        'bundle_total_price' => '4.00',
        'pack_quantity' => '1500',
        'pack_unit' => 'ml',
    ]);
    $product->forceFill(['cheapest_shop_id' => $shop->id])->save();

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSee('2 for €4.00')
        ->assertSeeText('Normal price: €2.85 each')
        ->assertSee('title="Regular price"', escape: false)
        // The card leads per litre, with the regular price per litre struck
        // beside the deal's.
        ->assertSeeTextInOrder(['€1.33 /l', '€1.90 /l'])
        ->assertSeeHtml('href="https://jumbo.com/producten/fanta-cassis"')
        ->assertSeeHtml('target="_blank"');
});

it('shows the mcp endpoint and an empty connection list', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(ConnectionsPage::class)
        ->assertSee('/mcp')
        ->assertSee('Nothing connected yet.');
});

it('shows the size of each active drop, what it fell from, and how long ago', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Dropped coffee', 'cheapest_price' => '7.99', 'last_notified_price' => '7.99', 'last_notified_at' => now()->subHours(3)]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'reference_price' => '9.99', 'new_price' => '7.99', 'drop_pct' => 20.0, 'currency' => 'EUR']);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSee('−20%')
        ->assertSee('Was €9.99')
        ->assertSee('3 hours ago');
});

it('resolves the card figures without a query per card', function (): void {
    $queriesFor = function (int $products): int {
        $user = User::factory()->create();

        foreach (range(1, $products) as $index) {
            $product = Product::factory()->for($user)->create(['currency' => 'EUR', 'last_notified_price' => '1.00', 'last_notified_at' => now()]);
            Shop::factory()->for($product)->create(['url' => "https://ah.nl/p/{$index}", 'current_price' => '1.69', 'pack_quantity' => '200.00', 'pack_unit' => 'g']);
            Shop::factory()->for($product)->create(['url' => "https://lidl.nl/p/{$index}", 'current_price' => '1.99', 'pack_quantity' => '370.00', 'pack_unit' => 'g']);
            $product->refresh()->recomputeCheapestShop();
        }

        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        livewire(Dashboard::class)->assertSee('€5.38 /kg');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesFor(5))->toBe($queriesFor(1));
});
