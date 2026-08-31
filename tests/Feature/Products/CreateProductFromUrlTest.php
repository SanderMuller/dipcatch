<?php declare(strict_types=1);

use App\Livewire\Products\CreateProductFromUrl;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function fakeCreateFlowOffer(string $url = 'https://shop.example.com/p/1', string $price = '50.00', string $currency = 'EUR'): array
{
    $host = parse_url($url, PHP_URL_HOST) ?: 'shop.example.com';
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Demo Item',
        'image' => 'https://shop.example.com/img.jpg',
        'offers' => [
            '@type' => 'Shop',
            'price' => $price,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return [
        "https://{$host}/robots.txt" => Http::response('', 404),
        $url => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
});

test('probe success prefills title, image, and tier-default thresholds', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('title', 'Demo Item')
        ->assertSet('imageUrl', 'https://shop.example.com/img.jpg')
        // 50.00 sits in the 25–100 tier: 10% / 7.00 absolute.
        ->assertSet('thresholdPct', '10.00')
        ->assertSet('thresholdAbs', '7.00')
        ->assertSet('existingTrackedProduct', null);
});

test('confirm creates product + shop + initial price check and recomputes cheapest', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->set('title', 'My Tracked Item')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertRedirect();

    $product = Product::query()->where('user_id', $user->id)->first();
    expect($product)->not->toBeNull()
        ->and($product->title)->toBe('My Tracked Item')
        ->and($product->currency)->toBe('EUR')
        ->and((string) $product->drop_threshold_pct)->toBe('10.00')
        ->and((string) $product->drop_threshold_abs)->toBe('7.00')
        ->and($product->active)->toBeTrue();

    $shop = Shop::query()->where('product_id', $product->id)->first();
    expect($shop)->not->toBeNull()
        ->and($shop->url)->toBe('https://shop.example.com/p/1')
        ->and((string) $shop->current_price)->toBe('50.00');

    expect(PriceCheck::query()->where('shop_id', $shop->id)->count())->toBe(1);

    $product->refresh();
    expect($product->cheapest_shop_id)->toBe($shop->id)
        ->and((string) $product->cheapest_price)->toBe('50.00');
});

test('empty title blocks confirm and persists nothing', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->set('title', '')
        ->call('confirm')
        ->assertHasErrors(['title']);

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('URL already tracked on another product of this user shows a warning but confirm still works', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $existingProduct = Product::factory()->create(['user_id' => $user->id, 'title' => 'Existing Tracker']);
    Shop::factory()->for($existingProduct)->create(['url' => 'https://shop.example.com/p/1']);
    $this->actingAs($user);

    $component = Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('existingTrackedProduct.title', 'Existing Tracker');

    $component->call('confirm')->assertHasNoErrors();

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(2);
});

test('URL tracked only by another user shows no warning', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $otherProduct = Product::factory()->create();
    Shop::factory()->for($otherProduct)->create(['url' => 'https://shop.example.com/p/1']);
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->assertSet('existingTrackedProduct', null);
});

test('fetch-level failure shows the error state', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response('Server error', 500),
    ]);
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'error')
        ->assertSet('errorCode', 'temporary_failure');
});

test('extraction failure flips to manual selector and selectors create the product', function (): void {
    Http::fake([
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(
            '<html><head><title>Widget</title></head><body><span id="p">19.95</span></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'manual_selector')
        ->set('priceSelector', '#p')
        ->set('manualCurrency', 'EUR')
        ->call('probeWithSelectors')
        ->assertSet('state', 'preview')
        // 19.95 sits in the <25 tier: 15% / 3.00 absolute.
        ->assertSet('thresholdPct', '15.00')
        ->assertSet('thresholdAbs', '3.00')
        ->set('title', 'Selector Item')
        ->call('confirm')
        ->assertHasNoErrors();

    $product = Product::query()->where('user_id', $user->id)->first();
    expect($product)->not->toBeNull()
        ->and($product->currency)->toBe('EUR');

    $shop = Shop::query()->where('product_id', $product->id)->first();
    expect($shop->adapter_key)->toBe('user-selector')
        ->and($shop->price_selector)->toBe('#p');
});

test('abandoning after probe persists nothing', function (): void {
    Http::fake(fakeCreateFlowOffer());
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->call('cancel')
        ->assertSet('state', 'idle');

    expect(Product::query()->count())->toBe(0)
        ->and(Shop::query()->count())->toBe(0);
});

test('create page renders the component and manual page still creates', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/app/products/create')
        ->assertOk()
        ->assertSeeLivewire(CreateProductFromUrl::class);

    $this->get('/app/products/create-manual')->assertOk();
});
