<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

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

it('flags a category listing without flagging ordinary product pages', function (): void {
    // `bodyandfit.com/en/products/protein-bars` is a category listing carrying
    // a price and a size from list-page JSON-LD; it was tracked on three
    // Barebells flavours at once. An AH product URL keeps its identifier in the
    // second-to-last segment, so reading the last one alone called seven real
    // product pages suspect and buried the one that mattered.
    $product = dryRunProduct('URL shapes', []);

    foreach ([
        'https://www.ah.nl/producten/product/wi156794/fanta-cassis',
        'https://www.dirk.nl/boodschappen/x/x/x/68302',
        'https://www.bodyandfit.com/en/products/protein-bars',
    ] as $url) {
        Shop::factory()->for($product)->create(['url' => $url])
            ->forceFill(['currency' => 'EUR', 'current_price' => '10.00', 'current_in_stock' => true])->save();
    }

    $this->artisan('dipcatch:unit-ranking-dry-run')
        ->expectsOutputToContain('bodyandfit.com/en/products/protein-bars')
        ->doesntExpectOutputToContain('wi156794/fanta-cassis')
        ->doesntExpectOutputToContain('x/x/x/68302')
        ->assertSuccessful();
});

it('separates a page nobody keyed from one where each product has its variant', function (): void {
    // These two shapes used to print as two tiers listing the same rows, because
    // a `url_hash` is unique per product — so any group sharing one is already
    // several products, and the second tier was a subset of the first. Neither
    // line said which case it was, which is the only thing a reader needs.
    $user = User::factory()->create();
    $url = 'https://www.realsupps.nl/products/barebells-repen-12-x-55g';

    foreach (['Salty Peanut', 'Creamy Crisp'] as $flavour) {
        $product = Product::factory()->for($user)->create(['title' => 'Barebells ' . $flavour, 'currency' => 'EUR']);
        Shop::factory()->for($product)->create(['url' => $url])
            ->forceFill(['currency' => 'EUR', 'current_price' => '27.95', 'variant_key' => null])->save();
    }

    $keyed = 'https://shop.test/products/protein-bar';

    foreach (['vanilla', 'chocolate'] as $key) {
        $product = Product::factory()->for($user)->create(['title' => 'Bar ' . $key, 'currency' => 'EUR']);
        Shop::factory()->for($product)->create(['url' => $keyed])
            ->forceFill(['currency' => 'EUR', 'current_price' => '19.95', 'variant_key' => $key])->save();
    }

    $this->artisan('dipcatch:unit-ranking-dry-run')
        ->expectsOutputToContain('none keyed')
        ->expectsOutputToContain('realsupps.nl')
        ->expectsOutputToContain('one variant each')
        ->assertSuccessful();
});
