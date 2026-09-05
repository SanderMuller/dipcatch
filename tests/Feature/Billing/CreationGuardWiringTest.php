<?php declare(strict_types=1);

use App\Filament\App\Resources\Products\Pages\CreateProductManual;
use App\Livewire\Products\CreateProductFromUrl;
use App\Livewire\Shops\AddShop;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * The limits are only worth anything if every path that creates a product or
 * a shop actually calls the guard. These tests drive the real entry points
 * and assert that nothing reaches the database — they fail if a guard call
 * is deleted, which a test of `PlanLimits` on its own would not.
 */
/**
 * @return array<string, Closure|PromiseInterface>
 */
function guardWiringOffer(): array
{
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Demo Item',
        'offers' => [
            '@type' => 'Offer',
            'price' => '50.00',
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return [
        'https://shop.example.com/robots.txt' => Http::response('', 404),
        'https://shop.example.com/p/1' => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

beforeEach(function (): void {
    Cache::flush();
    RateLimiter::clear('dipcatch:fetcher:host:shop.example.com');
});

it('refuses the product over the limit on the URL-first flow', function (): void {
    Http::fake(guardWiringOffer());

    $user = User::factory()->create();
    Product::factory()->count(20)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test(CreateProductFromUrl::class)
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->call('confirm')
        ->assertNotified('Plan limit reached');

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(20)
        ->and(Shop::query()->count())->toBe(0);
});

it('refuses the product over the limit on the manual flow', function (): void {
    $user = User::factory()->create();
    Product::factory()->count(20)->create(['user_id' => $user->id]);
    $this->actingAs($user);
    Filament::setCurrentPanel('app');

    Livewire::test(CreateProductManual::class)
        ->fillForm([
            'title' => 'One too many',
            'currency' => 'EUR',
            'drop_threshold_pct' => '10',
            'drop_threshold_abs' => '0.50',
        ])
        ->call('create');

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(20);
});

it('refuses the fifth shop on a product', function (): void {
    Http::fake(guardWiringOffer());

    $user = User::factory()->create();
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    Shop::factory()->count(4)->create(['product_id' => $product->id]);
    $this->actingAs($user);

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->assertSet('state', 'preview')
        ->call('confirm')
        ->assertNotified('Plan limit reached');

    expect(Shop::query()->where('product_id', $product->id)->count())->toBe(4);
});

it('lets a pro account past every one of those walls', function (): void {
    Http::fake(guardWiringOffer());

    $user = User::factory()->create();
    subscribeUser($user);
    $product = Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR']);
    Shop::factory()->count(4)->create(['product_id' => $product->id]);
    $this->actingAs($user);

    Livewire::test(AddShop::class, ['product' => $product])
        ->set('url', 'https://shop.example.com/p/1')
        ->call('probe')
        ->call('confirm');

    expect(Shop::query()->where('product_id', $product->id)->count())->toBe(5);
});
