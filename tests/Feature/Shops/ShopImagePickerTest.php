<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\Schemas\ProductForm;
use App\Jobs\CheckShopPrice;
use App\Livewire\Products\CreateProductFromUrl;
use App\Livewire\Shops\AddShop;
use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\PriceAdapters\AdapterResolver;
use App\Services\AhApi\AhApiSource;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\UrlNormalizer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function shopImagePickerHtml(?string $image, string $price = '50.00'): string
{
    $payload = [
        '@type' => 'Product',
        'name' => 'Demo Item',
        'offers' => [
            '@type' => 'Shop',
            'price' => $price,
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ];

    if ($image !== null) {
        $payload['image'] = $image;
    }

    return withJsonLd(json_encode($payload, JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function shopImagePickerFake(?string $image): array
{
    return [
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(
            shopImagePickerHtml($image),
            200,
            ['Content-Type' => 'text/html'],
        ),
    ];
}

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear(ShopFetcher::throttleKey('shop.example.com'));
});

test('adding a shop stores the detected image on the shop', function (): void {
    Http::fake(shopImagePickerFake('https://shop.example.com/img.jpg'));
    $product = Product::factory()->create(['currency' => 'EUR']);
    $this->actingAs(User::factory()->create());

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('confirm');

    expect(Shop::query()->firstOrFail()->image_url)
        ->toBe('https://shop.example.com/img.jpg');
});

test('a price check refreshes the stored shop image', function (): void {
    Http::fake(shopImagePickerFake('https://shop.example.com/new.jpg'));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/1',
        'image_url' => 'https://shop.example.com/old.jpg',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    expect($shop->refresh()->image_url)->toBe('https://shop.example.com/new.jpg');
});

test('a check without a detected image keeps the last known one', function (): void {
    Http::fake(shopImagePickerFake(null));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/1',
        'image_url' => 'https://shop.example.com/old.jpg',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    expect($shop->refresh()->image_url)->toBe('https://shop.example.com/old.jpg');
});

test('an ah.nl check stores the image the mobile API returns', function (): void {
    Http::fake(ahApiProductFakes());

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://www.ah.nl/producten/product/wi526381/lay-s-naturel',
        'image_url' => null,
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    expect($shop->refresh()->image_url)->toBe('https://static.ah.nl/dam/product/test.webp');
});

test('changing a shop url drops the stale image', function (): void {
    $shop = Shop::factory()->create([
        'url' => 'https://shop.example.com/p/1',
        'image_url' => 'https://shop.example.com/old.jpg',
    ]);

    $shop->updateUrl(UrlNormalizer::normalize('https://shop.example.com/p/2'));

    expect($shop->refresh()->image_url)->toBeNull();
});

test('safeImageUrl rejects a non-http scheme', function (): void {
    $shop = Shop::factory()->make(['image_url' => 'javascript:alert(1)']);

    expect($shop->safeImageUrl())->toBeNull();
});

test('the edit form offers each detected shop image as an option', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['image_url' => null]);

    Shop::factory()->for($product)->create([
        'url' => 'https://one.example.com/p/1',
        'image_url' => 'https://one.example.com/a.jpg',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://two.example.com/p/2',
        'image_url' => 'https://two.example.com/b.jpg',
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://three.example.com/p/3',
        'image_url' => null,
    ]);

    $this->actingAs($user);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->callAction(
            TestAction::make('pickShopImage')->schemaComponent('image_url'),
            data: ['image_url' => 'https://two.example.com/b.jpg'],
        )
        ->assertSchemaStateSet(['image_url' => 'https://two.example.com/b.jpg']);
});

test('the edit form explains the empty picker when no shop has an image yet', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create(['image_url' => null]);

    Shop::factory()->for($product)->create([
        'url' => 'https://one.example.com/p/1',
        'image_url' => null,
    ]);

    $this->actingAs($user);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->assertSee('Shop images appear here after the next price check of each shop.')
        ->assertActionHidden(TestAction::make('pickShopImage')->schemaComponent('image_url'));
});

test('two shops that serve the same image collapse into one escaped option', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create();

    $shared = 'https://cdn.example.com/a.jpg?q="1"&b=2';

    Shop::factory()->for($product)->create([
        'url' => 'https://one.example.com/p/1',
        'image_url' => $shared,
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://two.example.com/p/2',
        'image_url' => $shared,
    ]);
    Shop::factory()->for($product)->create([
        'url' => 'https://three.example.com/p/3',
        'image_url' => 'javascript:alert(1)',
    ]);

    $this->actingAs($user);

    $options = ProductForm::shopImageOptions($product);

    expect($options)->toHaveCount(1)
        ->and(array_key_first($options))->toBe($shared);

    $label = (string) reset($options);

    expect($label)->toContain('one.example.com, two.example.com')
        ->and($label)->toContain('a.jpg?q=&quot;1&quot;&amp;b=2')
        ->and($label)->not->toContain('javascript:');
});

test('creating a product from a url stores the image on both the product and the shop', function (): void {
    Http::fake(shopImagePickerFake('https://shop.example.com/img.jpg'));
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('confirm')
        ->assertHasNoErrors();

    $product = Product::query()->firstOrFail();

    expect($product->image_url)->toBe('https://shop.example.com/img.jpg')
        ->and($product->shops()->firstOrFail()->image_url)->toBe('https://shop.example.com/img.jpg');
});

test('a checkjebon fallback keeps the image the ah api stored earlier', function (): void {
    Http::fake(['https://api.ah.nl/*' => Http::response('down', 500)]);

    CheckjebonPrice::query()->create([
        'supermarket' => 'ah',
        'external_id' => 'wi526381',
        'name' => "Lay's Naturel",
        'price' => '2.19',
        'size' => '225 g',
        'refreshed_at' => now(),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://www.ah.nl/producten/product/wi526381/lay-s-naturel',
        'image_url' => 'https://static.ah.nl/dam/product/test.webp',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();

    expect($shop->image_url)->toBe('https://static.ah.nl/dam/product/test.webp')
        ->and((string) $shop->current_price)->toBe('2.19');
});

test('a failed check leaves the stored image untouched', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('boom', 500),
    ]);

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.example.com/p/1',
        'image_url' => 'https://shop.example.com/old.jpg',
    ]);

    new CheckShopPrice($shop)->handle(app(ShopFetcher::class), app(AdapterResolver::class), app(CheckjebonSource::class), app(AhApiSource::class));

    $shop->refresh();

    expect($shop->image_url)->toBe('https://shop.example.com/old.jpg')
        ->and($shop->consecutive_5xx_failures)->toBe(1);
});
