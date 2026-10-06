<?php declare(strict_types=1);

use App\Enums\PriceDisplay;
use App\Livewire\Connections\ConnectionsPage;
use App\Livewire\Dashboard;
use App\Models\PriceDropEvent;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Collection;
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

it('shows recently added products, paused ones included, and nobody elses', function (): void {
    $user = User::factory()->create();
    // Paused: the strip answers "what am I watching", which a paused product
    // is still part of — the active filter belongs to the count above it.
    Product::factory()->create(['user_id' => $user->id, 'title' => 'My paused product', 'active' => false]);
    Product::factory()->create(['title' => 'Someone elses product']);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSee('Recently added')
        ->assertSee('My paused product')
        ->assertDontSee('Someone elses product');
});

it('hides the recently added list until something is tracked', function (): void {
    $this->actingAs(User::factory()->create());

    livewire(Dashboard::class)->assertDontSee('Recently added');
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
    // Per unit on purpose: this test is about the per-unit headline.
    $product->forceFill(['price_display' => PriceDisplay::Unit])->save();
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
    // In a drop, so it shows as a card: "Recently added" lists no prices.
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'reference_price' => '2.75', 'new_price' => '2.00', 'drop_pct' => 27.3, 'currency' => 'EUR']);

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
            PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'reference_price' => '2.50', 'new_price' => '1.69', 'comparison_unit' => 'g', 'reference_unit_price' => '9.0000', 'drop_pct' => 40.2, 'currency' => 'EUR']);
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

it('counts a drop only while the card shows one', function (): void {
    $user = User::factory()->create();
    // Still down: 11.95 against 14.10.
    $down = Product::factory()->for($user)->create(['title' => 'Still down', 'cheapest_price' => '11.95', 'last_notified_price' => '11.95', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $down->id, 'reference_price' => '14.10', 'new_price' => '11.95', 'drop_pct' => 15.2, 'currency' => 'EUR']);
    // Latched, but back at the reference.
    $back = Product::factory()->for($user)->create(['title' => 'Back at reference', 'cheapest_price' => '14.10', 'last_notified_price' => '11.95', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $back->id, 'reference_price' => '14.10', 'new_price' => '11.95', 'drop_pct' => 15.2, 'currency' => 'EUR']);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertViewHas('activeDropCount', 1)
        ->assertViewHas('activeDrops', fn ($drops): bool => $drops->pluck('title')->all() === ['Still down']);
});

it('shows the products at their alert price above the drops, and only there', function (): void {
    $user = User::factory()->create();
    $atAlert = Product::factory()->for($user)->create(['title' => 'Under my alert', 'cheapest_price' => '7.99', 'target_price' => '8.00', 'last_notified_price' => '7.99', 'last_notified_at' => now()]);
    // Also in a drop: it shows under its alert, not twice.
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $atAlert->id, 'reference_price' => '9.99', 'new_price' => '7.99', 'drop_pct' => 20.0, 'currency' => 'EUR']);
    Product::factory()->for($user)->create(['title' => 'Above my alert', 'cheapest_price' => '8.50', 'target_price' => '8.00']);
    Product::factory()->for($user)->create(['title' => 'Paused under alert', 'cheapest_price' => '7.00', 'target_price' => '8.00', 'active' => false]);
    Product::factory()->create(['title' => 'Someone elses alert', 'cheapest_price' => '1.00', 'target_price' => '2.00']);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertViewHas('atAlert', fn ($products): bool => $products->pluck('title')->all() === ['Under my alert'])
        ->assertViewHas('dropCards', fn (Collection $products): bool => $products->isEmpty())
        ->assertSeeHtml('data-test="at-alert"')
        ->assertDontSee('Biggest drops');
});

it('leaves the alert section out when nothing is at its alert price', function (): void {
    $user = User::factory()->create();
    Product::factory()->for($user)->create(['cheapest_price' => '8.50', 'target_price' => '8.00']);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertDontSeeHtml('data-test="at-alert"')
        ->assertSee('Biggest drops');
});

it('still shows the other drops beside the products at their alert price', function (): void {
    $user = User::factory()->create();
    $atAlert = Product::factory()->for($user)->create(['title' => 'Under my alert', 'cheapest_price' => '7.99', 'target_price' => '8.00', 'last_notified_price' => '7.99', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $atAlert->id, 'reference_price' => '9.99', 'new_price' => '7.99', 'drop_pct' => 20.0, 'currency' => 'EUR']);
    $dropped = Product::factory()->for($user)->create(['title' => 'Only dropped', 'cheapest_price' => '4.00', 'last_notified_price' => '4.00', 'last_notified_at' => now()]);
    PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $dropped->id, 'reference_price' => '5.00', 'new_price' => '4.00', 'drop_pct' => 20.0, 'currency' => 'EUR']);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertViewHas('dropCards', fn ($products): bool => $products->pluck('title')->all() === ['Only dropped'])
        ->assertSeeInOrder(['At your alert price', 'Under my alert', 'Biggest drops', 'Only dropped']);
});

it('says how many shops a recently added product has, and that it is paused', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Paused pasta', 'active' => false]);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/pasta']);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/pasta']);

    $this->actingAs($user);

    livewire(Dashboard::class)->assertSeeTextInOrder(['Recently added', 'Paused pasta', '2 shops', 'Paused']);
});

it('fills the drop cards from beyond the products at their alert price', function (): void {
    $user = User::factory()->create();
    $drop = function (string $title, string $price, ?string $target) use ($user): void {
        $product = Product::factory()->for($user)->create(['title' => $title, 'cheapest_price' => $price, 'target_price' => $target, 'last_notified_price' => $price, 'last_notified_at' => now()]);
        PriceDropEvent::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'reference_price' => '10.00', 'new_price' => $price, 'drop_pct' => 50.0, 'currency' => 'EUR']);
    };

    // The six biggest drops are all at their alert price.
    foreach (range(1, 6) as $n) {
        $drop("At alert {$n}", '2.00', '3.00');
    }

    foreach (range(1, 5) as $n) {
        $drop("Plain drop {$n}", '8.00', null);
    }

    $this->actingAs($user);

    livewire(Dashboard::class)->assertViewHas('dropCards', fn ($products): bool => $products->count() === 5);
});
