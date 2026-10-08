<?php declare(strict_types=1);

use App\Enums\PriceDisplay;
use App\Livewire\Dashboard;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\DashboardDigest;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

/**
 * A product at two shops, with the lower price at `$cheapHost`, and a deal
 * running there when `$onOffer`.
 */
function digestProduct(User $user, string $title, string $cheapHost, string $dearHost, bool $onOffer = false): Product
{
    $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => "https://{$cheapHost}/p/" . Str::slug($title), 'current_price' => '1.00', 'currency' => 'EUR', 'promotion_ends_at' => $onOffer ? now()->addDays(2) : null]);
    Shop::factory()->for($product)->create(['url' => "https://{$dearHost}/p/" . Str::slug($title), 'current_price' => '2.00', 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

it('groups each product under the shop where it is the best buy, biggest trip first', function (): void {
    $user = User::factory()->create();
    digestProduct($user, 'Coffee', 'ah.nl', 'jumbo.com', onOffer: true);
    digestProduct($user, 'Tea', 'ah.nl', 'jumbo.com');
    digestProduct($user, 'Rice', 'ah.nl', 'jumbo.com');
    digestProduct($user, 'Milk', 'jumbo.com', 'ah.nl', onOffer: true);
    digestProduct($user, 'Juice', 'jumbo.com', 'ah.nl');

    $trips = DashboardDigest::forUser($user)->trips;

    expect(array_column($trips, 'host'))->toBe(['ah.nl', 'jumbo.com'])
        ->and(array_column($trips, 'count'))->toBe([3, 2])
        ->and(array_map(fn (Product $product): string => $product->title, $trips[0]['products']))->toEqualCanonicalizing(['Coffee', 'Tea', 'Rice']);
});

it('leaves out a shop with one best buy, or with nothing on offer', function (): void {
    $user = User::factory()->create();
    digestProduct($user, 'Coffee', 'lidl.nl', 'jumbo.com', onOffer: true);
    digestProduct($user, 'Milk', 'dirk.nl', 'jumbo.com');
    digestProduct($user, 'Juice', 'dirk.nl', 'jumbo.com');

    expect(DashboardDigest::forUser($user)->trips)->toBeEmpty();
});

it('leaves out a product whose cheapest shop no longer sells it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Sold out', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/sold-out', 'current_price' => '1.00', 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();
    $product->shops()->update(['current_in_stock' => false]);

    expect(DashboardDigest::forUser($user)->trips)->toBeEmpty();
});

it('lists a deal that ends within a week, and not one that ends later or cannot be bought', function (): void {
    $user = User::factory()->create();
    $soon = digestProduct($user, 'Soon', 'ah.nl', 'jumbo.com');
    $later = digestProduct($user, 'Later', 'ah.nl', 'jumbo.com');
    $soon->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(2)])->save();
    $later->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(20)])->save();
    $soldOut = digestProduct($user, 'Sold out', 'ah.nl', 'jumbo.com');
    $soldOut->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(2), 'current_in_stock' => false])->save();

    $ending = DashboardDigest::forUser($user)->endingSoon;

    expect(array_map(fn (array $row): string => $row['product']->title, $ending))->toBe(['Soon']);
});

it('leaves out a deal at a shop that another shop beats, and keeps one level with the best buy', function (): void {
    $user = User::factory()->create();
    $beaten = digestProduct($user, 'Beaten', 'ah.nl', 'jumbo.com');
    $beaten->shops->firstWhere('host', 'jumbo.com')?->forceFill(['promotion_ends_at' => now()->addDays(2)])->save();
    $level = digestProduct($user, 'Level', 'ah.nl', 'jumbo.com');
    Shop::factory()->for($level)->create(['url' => 'https://dirk.nl/p/level', 'current_price' => '1.00', 'currency' => 'EUR', 'promotion_ends_at' => now()->addDays(2)]);

    $ending = DashboardDigest::forUser($user)->endingSoon;

    expect(array_map(fn (array $row): string => $row['product']->title . ' at ' . $row['shop']->host, $ending))->toBe(['Level at dirk.nl']);
});

it('compares per unit as the headline does: unrounded, and only a size that may win', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Bars', 'currency' => 'EUR']);
    $pack = ['currency' => 'EUR', 'pack_quantity' => '800.00', 'pack_unit' => 'piece'];
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/bars', 'current_price' => '21.98', ...$pack]);
    // 0.0275 a piece at four decimals, as the cheaper one, but dearer unrounded.
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/bars', 'current_price' => '21.99', 'promotion_ends_at' => now()->addDays(2), ...$pack]);
    // The same price, on a size borrowed from the other shops.
    Shop::factory()->for($product)->create(['url' => 'https://dirk.nl/p/bars', 'current_price' => '21.98', 'currency' => 'EUR', 'promotion_ends_at' => now()->addDays(2)]);
    $product->refresh()->recomputeCheapestShop();

    expect(DashboardDigest::forUser($user)->endingSoon)->toBeEmpty();
});

it('keeps the deal at the cheapest shop per unit, also for an owner who shows pack prices', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Coffee', 'currency' => 'EUR', 'price_display' => PriceDisplay::Pack]);
    // €2.00 a kilo against €3.96: the bigger bag is the best buy, though the small one costs less.
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/coffee', 'current_price' => '2.00', 'currency' => 'EUR', 'pack_quantity' => '1000.00', 'pack_unit' => 'g', 'promotion_ends_at' => now()->addDays(2)]);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/coffee', 'current_price' => '0.99', 'currency' => 'EUR', 'pack_quantity' => '250.00', 'pack_unit' => 'g', 'promotion_ends_at' => now()->addDays(3)]);
    $product->refresh()->recomputeCheapestShop();

    expect(array_map(fn (array $row): string => $row['shop']->host, DashboardDigest::forUser($user)->endingSoon))->toBe(['ah.nl']);
});

it('keeps a deal level per unit at another pack size', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Nuts', 'currency' => 'EUR']);
    // Both €7.00 a kilo.
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/nuts', 'current_price' => '1.40', 'currency' => 'EUR', 'pack_quantity' => '200.00', 'pack_unit' => 'g', 'promotion_ends_at' => now()->addDays(2)]);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/nuts', 'current_price' => '2.10', 'currency' => 'EUR', 'pack_quantity' => '300.00', 'pack_unit' => 'g', 'promotion_ends_at' => now()->addDays(3)]);
    $product->refresh()->recomputeCheapestShop();

    expect(array_map(fn (array $row): string => $row['shop']->host, DashboardDigest::forUser($user)->endingSoon))->toBe(['ah.nl', 'jumbo.com']);
});

it('still finds a buyable deal behind many earlier ones that cannot be bought', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 13) as $day) {
        $soldOut = digestProduct($user, "Sold out {$day}", 'ah.nl', 'jumbo.com');
        $soldOut->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addHours($day), 'current_in_stock' => false])->save();
    }

    $buyable = digestProduct($user, 'Buyable', 'ah.nl', 'jumbo.com');
    $buyable->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(3)])->save();

    expect(array_map(fn (array $row): string => $row['product']->title, DashboardDigest::forUser($user)->endingSoon))->toBe(['Buyable']);
});

it('names this account\'s shops that fail to read and products at one shop only', function (): void {
    $user = User::factory()->create();
    $broken = digestProduct($user, 'Broken', 'ah.nl', 'jumbo.com');
    $broken->shops->firstWhere('host', 'jumbo.com')?->forceFill(['consecutive_failures' => 3])->save();
    $single = Product::factory()->for($user)->create(['title' => 'Lonely', 'currency' => 'EUR']);
    Shop::factory()->for($single)->create(['url' => 'https://ah.nl/p/lonely', 'current_price' => '1.00', 'currency' => 'EUR']);
    $other = digestProduct(User::factory()->create(), 'Not mine', 'ah.nl', 'lidl.nl');
    $other->shops->firstWhere('host', 'lidl.nl')?->forceFill(['consecutive_failures' => 5])->save();

    $digest = DashboardDigest::forUser($user);

    expect(array_map(fn (array $row): string => $row['shop']->host, $digest->failing))->toBe(['jumbo.com'])
        ->and(array_map(fn (Product $product): string => $product->title, $digest->singleShop))->toBe(['Lonely']);
});

it('shows where to shop this week on the dashboard, and only this account\'s products', function (): void {
    $user = User::factory()->create();
    digestProduct($user, 'My coffee', 'ah.nl', 'jumbo.com', onOffer: true);
    digestProduct($user, 'My tea', 'ah.nl', 'jumbo.com');
    digestProduct(User::factory()->create(), 'Someone elses tea', 'ah.nl', 'jumbo.com', onOffer: true);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSeeHtml('data-test="shopping-trips"')
        ->assertSeeInOrder(['ah.nl', 'My coffee'])
        ->assertDontSee('Someone elses tea');
});

it('puts shops with a store before online-only ones, whatever their size', function (): void {
    $user = User::factory()->create();
    digestProduct($user, 'Coffee', 'bol.com', 'jumbo.com', onOffer: true);
    digestProduct($user, 'Tea', 'bol.com', 'jumbo.com');
    digestProduct($user, 'Rice', 'bol.com', 'jumbo.com');
    digestProduct($user, 'Milk', 'ah.nl', 'jumbo.com', onOffer: true);
    digestProduct($user, 'Juice', 'ah.nl', 'jumbo.com');

    $trips = DashboardDigest::forUser($user)->trips;

    expect(array_column($trips, 'hasStore', 'host'))->toBe(['bol.com' => false, 'ah.nl' => true]);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSeeInOrder(['Shops with a store', 'ah.nl', 'Online only', 'bol.com']);
});

it('links a trip\'s best buys and its offers to the product list rows they count', function (): void {
    $user = User::factory()->create();
    digestProduct($user, 'Coffee', 'ah.nl', 'jumbo.com', onOffer: true);
    digestProduct($user, 'Tea', 'ah.nl', 'jumbo.com');
    digestProduct($user, 'Milk', 'jumbo.com', 'ah.nl');
    digestProduct($user, 'Juice', 'jumbo.com', 'ah.nl');

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSeeHtml('href="' . e(route('app.products.index', ['shop' => 'ah.nl', 'bestBuy' => 'true'])) . '"')
        ->assertSeeHtml('href="' . e(route('app.products.index', ['shop' => 'ah.nl', 'bestBuy' => 'true', 'discounted' => 'true'])) . '"')
        // Best buys alone, nothing on offer: no trip to recommend.
        ->assertDontSeeHtml('href="' . e(route('app.products.index', ['shop' => 'jumbo.com', 'bestBuy' => 'true'])) . '"');
});

it('finds the same best buys for a shop as the trip counts', function (): void {
    $user = User::factory()->create();
    $coffee = digestProduct($user, 'Coffee', 'ah.nl', 'jumbo.com');
    $tea = digestProduct($user, 'Tea', 'ah.nl', 'jumbo.com');
    digestProduct($user, 'Milk', 'jumbo.com', 'ah.nl');
    digestProduct(User::factory()->create(), 'Not mine', 'ah.nl', 'jumbo.com');

    $coffee->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(2)])->save();
    // A deal at the shop that is not the best buy does not put a product on offer in this trip.
    $tea->shops->firstWhere('host', 'jumbo.com')?->forceFill(['promotion_ends_at' => now()->addDays(2)])->save();

    $trip = collect(DashboardDigest::forUser($user)->trips)->firstWhere('host', 'ah.nl');

    expect(DashboardDigest::bestBuyIds($user, 'ah.nl'))->toEqualCanonicalizing([$coffee->id, $tea->id])
        ->and($trip['count'] ?? null)->toBe(2)
        ->and(DashboardDigest::bestBuyIds($user, 'ah.nl', onOfferOnly: true))->toBe([$coffee->id]);
});

it('keeps the five biggest trips', function (): void {
    $user = User::factory()->create();
    digestProduct($user, 'Extra at ah.nl', 'ah.nl', 'bol.com');

    foreach (['ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'spar.nl', 'plus.nl'] as $host) {
        digestProduct($user, "Deal at {$host}", $host, 'bol.com', onOffer: true);
        digestProduct($user, "Plain at {$host}", $host, 'bol.com');
    }

    expect(array_column(DashboardDigest::forUser($user)->trips, 'host'))->toBe(['ah.nl', 'dirk.nl', 'jumbo.com', 'lidl.nl', 'plus.nl']);
});

it('lists what is worth a look, with a way to add a shop to a product at one shop', function (): void {
    $user = User::factory()->create();
    $ending = digestProduct($user, 'Ending deal', 'ah.nl', 'jumbo.com');
    $ending->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(2)])->save();
    $broken = digestProduct($user, 'Broken page', 'ah.nl', 'jumbo.com');
    $broken->shops->firstWhere('host', 'jumbo.com')?->forceFill(['consecutive_failures' => 3])->save();
    $single = Product::factory()->for($user)->create(['title' => 'Lonely', 'currency' => 'EUR']);
    Shop::factory()->for($single)->create(['url' => 'https://ah.nl/p/lonely', 'current_price' => '1.00', 'currency' => 'EUR']);

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSeeHtml('data-test="worth-a-look"')
        ->assertSeeText('Deal at ah.nl ends')
        ->assertSeeText('DipCatch cannot read jumbo.com right now.')
        ->assertSeeText('Tracked at one shop only.')
        ->assertSeeHtml(e(route('app.products.show', [$single, 'add-shop' => 1])));
});
