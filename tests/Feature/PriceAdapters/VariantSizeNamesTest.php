<?php declare(strict_types=1);

use App\Actions\Shops\ProbeShopUrl;
use App\Jobs\CheckShopPrice;
use App\Livewire\Shops\AddShop;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\PriceAdapters\VariantCandidate;
use App\PriceAdapters\VariantSizeNames;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function medpetsHillsUrl(): string
{
    return 'https://www.medpets.nl/hills-science-plan-feline-young-adult-sterilised-duck';
}

function agradiCavalorUrl(): string
{
    return 'https://www.agradi.nl/products/cavalor-muscle-motion';
}

function variantFixture(string $name): string
{
    return File::get(base_path("tests/Fixtures/variant-names/{$name}.html"));
}

function fakeVariantNamePages(): void
{
    Http::fake([
        'https://www.medpets.nl/robots.txt' => Http::response('', 404),
        'https://www.agradi.nl/robots.txt' => Http::response('', 404),
        'https://www.medpets.nl/hills-science-plan-feline-young-adult-sterilised-duck*' => Http::response(variantFixture('medpets_variants'), 200, ['Content-Type' => 'text/html']),
        'https://www.agradi.nl/products/cavalor-muscle-motion*' => Http::response(variantFixture('agradi_shopify_variants'), 200, ['Content-Type' => 'text/html']),
    ]);
}

/** A product whose one shop states `$grams` of the product. */
function productTracking(int $grams): Product
{
    $product = Product::factory()->create(['currency' => 'EUR', 'title' => 'Kattenvoer']);
    Shop::factory()->for($product)->create(['pack_quantity' => $grams, 'pack_unit' => 'g']);

    return $product;
}

function runVariantCheck(Shop $shop): void
{
    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );
}

test('the variant chooser names each variant with the size the page states elsewhere', function (string $url, array $titles): void {
    fakeVariantNamePages();
    $product = Product::factory()->create(['currency' => 'EUR', 'title' => 'Iets zonder maat']);
    $this->actingAs($product->user()->sole());

    $component = Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', $url)
        ->call('probe')
        ->assertSet('state', 'variant_chooser');

    foreach ($titles as $title) {
        $component->assertSee($title);
    }
})->with([
    'medpets dataLayer' => [medpetsHillsUrl(), ['Eend - 1,5 kg', 'Eend - 3 kg', 'Eend - 7 kg', 'Eend - 10 kg']],
    'shopify meta' => [agradiCavalorUrl(), ['Cavalor Muscle Motion - 1kg', 'Cavalor Muscle Motion - 5kg']],
]);

test('the variant in the size a product tracks is picked and read with that size', function (string $url, int $grams, string $key, string $price): void {
    fakeVariantNamePages();
    $product = productTracking($grams);

    $outcome = app(ProbeShopUrl::class)($product, $url, $product->user()->sole());

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->pickedVariantKey)->toBe($key)
        ->and($outcome->snapshot?->price)->toBe($price)
        ->and($outcome->snapshot?->packSize)->toBe("{$grams} g");
})->with([
    'medpets 10 kg' => [medpetsHillsUrl(), 10000, medpetsHillsUrl() . '?sku=MP28084', '76.05'],
    'agradi 5 kg' => [agradiCavalorUrl(), 5000, '44768357', '186.47'],
]);

test('a medpets link that names its variant gets the size without a key', function (): void {
    fakeVariantNamePages();

    $outcome = app(ProbeShopUrl::class)(null, medpetsHillsUrl() . '?sku=MP29294', Product::factory()->create()->user()->sole());

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->snapshot?->packSize)->toBe('7000 g');
});

test('a scheduled check of a shop saved for one variant stores its size', function (string $url, ?string $variantKey, string $grams): void {
    fakeVariantNamePages();
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => $url,
        'variant_key' => $variantKey,
        'pack_quantity' => null,
        'pack_unit' => null,
    ]);

    runVariantCheck($shop);

    expect($shop->fresh()->pack_quantity)->toBe($grams)
        ->and($shop->fresh()->pack_unit)->toBe('g');
})->with([
    'medpets by URL' => [medpetsHillsUrl() . '?sku=MP28084', null, '10000.00'],
    'agradi by key' => [agradiCavalorUrl(), '44768356', '1000.00'],
]);

test('variants keep their titles when the page names them nowhere else', function (): void {
    $variants = [
        new VariantCandidate('a', 'Cola — a', '1.00', 'EUR'),
        new VariantCandidate('b', 'Cola 1,5 l', '2.00', 'EUR'),
    ];

    expect(VariantSizeNames::fill($variants, '<html><body>no data</body></html>'))->toBe($variants);
});

test('a variant whose own title states a size keeps it', function (): void {
    $html = variantFixture('agradi_shopify_variants');
    $variant = new VariantCandidate('44768356', 'Cavalor Muscle Motion 2 kg', '48.14', 'EUR');

    expect(VariantSizeNames::fill([$variant], $html)[0]->title)->toBe('Cavalor Muscle Motion 2 kg');
});

test('two variants named in the tracked size stay a question', function (): void {
    $html = str_replace('Cavalor Muscle Motion - 5kg', 'Cavalor Muscle Motion - 1kg', variantFixture('agradi_shopify_variants'));
    Http::fake([
        'https://www.agradi.nl/robots.txt' => Http::response('', 404),
        'https://www.agradi.nl/*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ]);
    $product = productTracking(1000);

    $outcome = app(ProbeShopUrl::class)($product, agradiCavalorUrl(), $product->user()->sole());

    $titles = array_map(static fn (VariantCandidate $variant): string => $variant->title, $outcome->variants);
    expect($outcome->state)->toBe('ambiguous')
        ->and(array_count_values($titles)['Cavalor Muscle Motion - 1kg'] ?? 0)->toBe(2);
});
