<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;

/**
 * The dry run is read before anything user-facing lands. Its job is to show
 * what unit ranking makes of the real data — including the rows it would let
 * win on a size nobody stated.
 *
 * @param  array<string, array<string, mixed>>  $shops  host => attributes
 */
function dryRunProduct(string $title, array $shops): Product
{
    $product = Product::factory()->create(['title' => $title, 'currency' => 'EUR']);

    foreach ($shops as $host => $attributes) {
        Shop::factory()->for($product)->create(['url' => 'https://' . $host . '/p/' . bin2hex(random_bytes(4))])
            ->forceFill(['currency' => 'EUR', ...$attributes])->save();
    }

    $product->refresh()->recomputeCheapestShop();

    return $product->refresh();
}

it('reports a product whose two answers name different shops', function (): void {
    // The Lay's case: ah is the smaller outlay, dirk the better value.
    dryRunProduct('Lays Naturel', [
        'ah.nl' => ['current_price' => '2.19', 'pack_quantity' => '200.00', 'pack_unit' => 'g'],
        'dirk.nl' => ['current_price' => '2.45', 'pack_quantity' => '300.00', 'pack_unit' => 'g'],
    ]);

    $this->artisan('dipcatch:unit-ranking-dry-run --changes-only')
        ->expectsOutputToContain('Lays Naturel')
        ->expectsOutputToContain('two different shops')
        ->assertSuccessful();
});

it('leaves a product alone when one shop wins both answers', function (): void {
    dryRunProduct('Twix Minis', [
        'ah.nl' => ['current_price' => '4.19', 'pack_quantity' => '333.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '4.99', 'pack_quantity' => '333.00', 'pack_unit' => 'g'],
    ]);

    $this->artisan('dipcatch:unit-ranking-dry-run --changes-only')
        ->doesntExpectOutputToContain('Twix Minis')
        ->assertSuccessful();
});

it('names an excluded shop and the reason, and lists an inherited size', function (): void {
    dryRunProduct('Barebells Hazelnut', [
        'ah.nl' => ['current_price' => '22.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'jumbo.com' => ['current_price' => '23.00', 'pack_quantity' => '660.00', 'pack_unit' => 'g'],
        'fitnesscandy.nl' => ['current_price' => '18.00', 'pack_quantity' => '12.00', 'pack_unit' => 'piece'],
        'barebells.nl' => ['current_price' => '21.00', 'pack_quantity' => null, 'pack_unit' => null],
    ]);

    $this->artisan('dipcatch:unit-ranking-dry-run')
        ->expectsOutputToContain('Sold by the piece')
        ->expectsOutputToContain('Sizes nobody stated')
        ->expectsOutputToContain('barebells.nl inherited 660 g')
        ->assertSuccessful();
});
