<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Livewire\Shops\AddShop;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\Gtin;
use App\Support\UrlNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.example.com'));
});

function gtinJsonLdPage(?string $gtin, string $price = '50.00'): string
{
    $product = [
        '@type' => 'Product',
        'name' => 'Demo Item',
        'offers' => [
            '@type' => 'Shop',
            'price' => $price,
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ];

    if ($gtin !== null) {
        $product['gtin13'] = $gtin;
    }

    return withJsonLd(json_encode($product, JSON_THROW_ON_ERROR));
}

function gtinFake(string $html): array
{
    return [
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response($html, 200, ['Content-Type' => 'text/html']),
    ];
}

function gtinShop(?string $stored = null): Shop
{
    $product = Product::factory()->create(['currency' => 'EUR']);

    return Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/1',
        'gtin' => $stored,
    ]);
}

function runGtinCheck(Shop $shop): void
{
    new CheckShopPrice($shop)->handle(
        app(ShopFetcher::class),
        app(AdapterResolver::class),
        app(CheckjebonSource::class),
        app(AhApiSource::class),
    );
}

test('a valid GTIN normalizes, a malformed one does not', function (mixed $input, ?string $expected): void {
    expect(Gtin::normalize($input))->toBe($expected);
})->with([
    ['8712243044506', '8712243044506'],   // real EAN-13 (Beemster)
    ['  8712243044506 ', '8712243044506'],
    ['8712243044507', null],              // wrong check digit
    ['871224304450', null],               // wrong length
    ['not-a-number', null],
    [null, null],
    ['00012345678905', '00012345678905'], // GTIN-14
]);

test('a JSON-LD page stores its GTIN through a probe', function (): void {
    Http::fake(gtinFake(gtinJsonLdPage('8712243044506')));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs($product->user()->sole());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('confirm');

    expect(Shop::query()->firstOrFail()->gtin)->toBe('8712243044506');
});

test('a recheck stores the GTIN the page publishes', function (): void {
    Http::fake(gtinFake(gtinJsonLdPage('8712243044506')));

    $shop = gtinShop();
    runGtinCheck($shop);

    expect($shop->refresh()->gtin)->toBe('8712243044506');
});

test('a page that no longer publishes a GTIN clears the stored value', function (): void {
    Http::fake(gtinFake(gtinJsonLdPage(null)));

    $shop = gtinShop('8712243044506');
    runGtinCheck($shop);

    expect($shop->refresh()->gtin)->toBeNull();
});

test('a malformed GTIN is stored as null', function (): void {
    Http::fake(gtinFake(gtinJsonLdPage('8712243044507')));

    $shop = gtinShop();
    runGtinCheck($shop);

    expect($shop->refresh()->gtin)->toBeNull();
});

test('a source with no GTIN concept leaves the stored value untouched', function (): void {
    Http::fake(ahApiProductFakes());

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://www.ah.nl/producten/product/wi526381/lay-s-naturel',
        'gtin' => '8712243044506',
    ]);

    runGtinCheck($shop);

    expect($shop->refresh()->gtin)->toBe('8712243044506');
});

test('microdata reads the GTIN from the scope that holds the price, not a neighbour', function (): void {
    $html = <<<'HTML'
        <html><body>
            <div itemscope itemtype="https://schema.org/Product">
                <span itemprop="name">Other product</span>
                <meta itemprop="gtin13" content="0012345678905">
            </div>
            <div itemscope itemtype="https://schema.org/Product">
                <span itemprop="name">Tracked product</span>
                <meta itemprop="gtin13" content="8712243044506">
                <span itemprop="price" content="12.50">12,50</span>
                <meta itemprop="priceCurrency" content="EUR">
                <link itemprop="availability" href="https://schema.org/InStock">
            </div>
        </body></html>
        HTML;

    Http::fake(gtinFake($html));

    $shop = gtinShop();
    runGtinCheck($shop);

    // The neighbour scope must not donate its title either.
    expect($shop->refresh()->gtin)->toBe('8712243044506')
        ->and((string) $shop->current_price)->toBe('12.50')
        ->and(PriceCheck::query()->latest('id')->value('price'))->toBe('12.50');
});

test('changing a shop url drops the stored GTIN', function (): void {
    $shop = gtinShop('8712243044506');

    $shop->updateUrl(UrlNormalizer::normalize('https://shop.example.com/p/2'));

    expect($shop->refresh()->gtin)->toBeNull();
});

test('a product warns only when two shops report different GTINs', function (?string $a, ?string $b, bool $warns): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://one.example.com/p/1', 'gtin' => $a]);
    Shop::factory()->for($product)->create(['url' => 'https://two.example.com/p/2', 'gtin' => $b]);

    expect($product->refresh()->mismatchedGtinHosts() !== [])->toBe($warns);
})->with([
    'two different' => ['8712243044506', '8712243987955', true],
    'identical' => ['8712243044506', '8712243044506', false],
    'only one reports' => ['8712243044506', null, false],
    'none report' => [null, null, false],
]);

test('the shops table shows the mismatch warning', function (): void {
    $product = Product::factory()->create(['currency' => 'EUR']);
    Shop::factory()->for($product)->create(['url' => 'https://one.example.com/p/1', 'gtin' => '8712243044506']);
    Shop::factory()->for($product)->create(['url' => 'https://two.example.com/p/2', 'gtin' => '8712243987955']);

    $this->actingAs($product->user()->sole());

    mountShopsRelationManager($product->refresh())
        ->assertSee('report different article numbers')
        ->assertSee('one.example.com, two.example.com');
});

test('a GTIN with letters mixed in is rejected, separators are not', function (mixed $input, ?string $expected): void {
    expect(Gtin::normalize($input))->toBe($expected);
})->with([
    'letters in the middle' => ['8712ABC243044506', null],
    'letters appended' => ['8712243044506X', null],
    'dashes' => ['871-224-304-4506', '8712243044506'],
    'spaces' => ['8712 2430 44506', '8712243044506'],
]);

test('a nested product scope cannot donate its GTIN to the tracked offer', function (): void {
    $html = <<<'HTML'
        <html><body>
            <div itemscope itemtype="https://schema.org/Product">
                <span itemprop="name">Tracked product</span>
                <div itemscope itemtype="https://schema.org/Product">
                    <span itemprop="name">Recommended product</span>
                    <meta itemprop="gtin13" content="0012345678905">
                </div>
                <span itemprop="price" content="12.50">12,50</span>
                <meta itemprop="priceCurrency" content="EUR">
            </div>
        </body></html>
        HTML;

    Http::fake(gtinFake($html));

    $shop = gtinShop();
    runGtinCheck($shop);

    expect($shop->refresh()->gtin)->toBeNull();
});
