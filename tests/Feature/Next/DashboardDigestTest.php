<?php declare(strict_types=1);

use App\Livewire\Dashboard;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Support\DashboardDigest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

/**
 * A product at two shops, with the lower price at `$cheapHost`.
 */
function digestProduct(User $user, string $title, string $cheapHost, string $dearHost): Product
{
    $product = Product::factory()->for($user)->create(['title' => $title, 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => "https://{$cheapHost}/p/" . Str::slug($title), 'current_price' => '1.00', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => "https://{$dearHost}/p/" . Str::slug($title), 'current_price' => '2.00', 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

/**
 * @return Collection<int, Product>
 */
function digestProducts(User $user): Collection
{
    return Product::query()->where('user_id', $user->id)->where('active', true)->with(['cheapestShop', 'shops'])->get();
}

it('groups each product under the shop where it is the best buy, biggest trip first', function (): void {
    $user = User::factory()->create();
    digestProduct($user, 'Coffee', 'ah.nl', 'jumbo.com');
    digestProduct($user, 'Tea', 'ah.nl', 'jumbo.com');
    digestProduct($user, 'Milk', 'jumbo.com', 'ah.nl');

    $trips = DashboardDigest::of(digestProducts($user))->trips;

    expect(array_column($trips, 'host'))->toBe(['ah.nl', 'jumbo.com'])
        ->and(array_column($trips, 'count'))->toBe([2, 1])
        ->and(array_map(fn (Product $product): string => $product->title, $trips[0]['products']))->toEqualCanonicalizing(['Coffee', 'Tea']);
});

it('leaves out a product whose cheapest shop no longer sells it', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['title' => 'Sold out', 'currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/sold-out', 'current_price' => '1.00', 'currency' => 'EUR']);
    $product->refresh()->recomputeCheapestShop();
    $product->shops()->update(['current_in_stock' => false]);

    expect(DashboardDigest::of(digestProducts($user))->trips)->toBe([]);
});

it('lists a deal that ends within a week, and not one that ends later or cannot be bought', function (): void {
    $user = User::factory()->create();
    $soon = digestProduct($user, 'Soon', 'ah.nl', 'jumbo.com');
    $later = digestProduct($user, 'Later', 'ah.nl', 'jumbo.com');
    $soon->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(2)])->save();
    $later->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(20)])->save();
    $soldOut = digestProduct($user, 'Sold out', 'ah.nl', 'jumbo.com');
    $soldOut->shops->firstWhere('host', 'ah.nl')?->forceFill(['promotion_ends_at' => now()->addDays(2), 'current_in_stock' => false])->save();

    $ending = DashboardDigest::of(digestProducts($user))->endingSoon;

    expect(array_map(fn (array $row): string => $row['product']->title, $ending))->toBe(['Soon']);
});

it('names the shops that fail to read and the products at one shop only', function (): void {
    $user = User::factory()->create();
    $broken = digestProduct($user, 'Broken', 'ah.nl', 'jumbo.com');
    $broken->shops->firstWhere('host', 'jumbo.com')?->forceFill(['consecutive_failures' => 3])->save();
    $single = Product::factory()->for($user)->create(['title' => 'Lonely', 'currency' => 'EUR']);
    Shop::factory()->for($single)->create(['url' => 'https://ah.nl/p/lonely', 'current_price' => '1.00', 'currency' => 'EUR']);

    $digest = DashboardDigest::of(digestProducts($user));

    expect(array_map(fn (array $row): string => $row['shop']->host, $digest->failing))->toBe(['jumbo.com'])
        ->and(array_map(fn (Product $product): string => $product->title, $digest->singleShop))->toBe(['Lonely']);
});

it('shows where to shop this week on the dashboard, and only this account\'s products', function (): void {
    $user = User::factory()->create();
    digestProduct($user, 'My coffee', 'ah.nl', 'jumbo.com');
    digestProduct(User::factory()->create(), 'Someone elses tea', 'ah.nl', 'jumbo.com');

    $this->actingAs($user);

    livewire(Dashboard::class)
        ->assertSeeHtml('data-test="shopping-trips"')
        ->assertSeeInOrder(['ah.nl', 'My coffee'])
        ->assertDontSee('Someone elses tea');
});
