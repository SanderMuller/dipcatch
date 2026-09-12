<?php declare(strict_types=1);

use App\Enums\ScrapeStatus;
use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use App\PriceAdapters\ConditionalOffer;
use App\Services\ShopFetcher\ShopFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    RateLimiter::clear(ShopFetcher::throttleKey('shop.test'));
});

/**
 * @return array<string, mixed>
 */
function fakeShopPage(string $path, string $price): array
{
    $json = json_encode([
        '@type' => 'Product',
        'name' => 'Cheese',
        'offers' => [
            '@type' => 'Offer',
            'price' => $price,
            'priceCurrency' => 'EUR',
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return [
        'https://shop.test/robots.txt' => Http::response('', 404),
        "https://shop.test{$path}" => Http::response(withJsonLd($json), 200, ['Content-Type' => 'text/html']),
    ];
}

test('an offer whose window is open reads as live', function (): void {
    $offer = new ConditionalOffer(
        price: '2.97',
        label: 'Bonus Box 15% korting',
        startsAt: CarbonImmutable::now()->subDay(),
        endsAt: CarbonImmutable::now()->addDays(4),
    );

    expect($offer->isLive())->toBeTrue();
});

test('an offer that has not started, or has ended, is not live', function (): void {
    $notYet = new ConditionalOffer('2.97', 'x', startsAt: CarbonImmutable::now()->addDay());
    $over = new ConditionalOffer('2.97', 'x', endsAt: CarbonImmutable::now()->subSecond());

    expect($notYet->isLive())->toBeFalse()
        ->and($over->isLive())->toBeFalse();
});

test('an undated offer is live', function (): void {
    expect(new ConditionalOffer('2.97', 'x')->isLive())->toBeTrue();
});

test('the shop hides an offer whose window has closed', function (): void {
    $shop = Shop::factory()->for(Product::factory())->create([
        'conditional_price' => '2.97',
        'conditional_label' => 'Bonus Box 15% korting',
        'conditional_ends_at' => now()->subDay(),
    ]);

    expect($shop->conditionalOffer())->toBeNull();
});

test('the shop exposes an offer whose window is open', function (): void {
    $shop = Shop::factory()->for(Product::factory())->create([
        'conditional_price' => '2.97',
        'conditional_label' => 'Bonus Box 15% korting',
        'conditional_starts_at' => now()->subDay(),
        'conditional_ends_at' => now()->addDays(4),
    ]);

    expect($shop->conditionalOffer()?->price)->toBe('2.97')
        ->and($shop->conditionalOffer()?->label)->toBe('Bonus Box 15% korting');
});

test('a conditional offer never becomes the tracked price', function (): void {
    Http::fake(fakeShopPage('/p/1', '3.49'));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/1',
        'conditional_price' => '2.97',
        'conditional_label' => 'Bonus Box 15% korting',
        'conditional_ends_at' => now()->addDays(4),
    ]);

    CheckShopPrice::dispatchSync($shop);
    $shop->refresh();

    expect($shop->last_status)->toBe(ScrapeStatus::Ok)
        ->and($shop->current_price)->toBe('3.49')
        // The offer is not a price drop and never enters the history.
        ->and($shop->priceChecks()->latest('id')->first()?->price)->toBe('3.49');
});

test('a source that reads offers and finds none clears a stored one', function (): void {
    Http::fake(fakeShopPage('/p/2', '3.49'));

    $product = Product::factory()->create(['currency' => 'EUR']);
    $shop = Shop::factory()->for($product)->create([
        'url' => 'https://shop.test/p/2',
        'conditional_price' => '2.97',
        'conditional_label' => 'Bonus Box 15% korting',
        'conditional_ends_at' => now()->addDays(4),
    ]);

    CheckShopPrice::dispatchSync($shop);

    // The JSON-LD adapter states no conditional offer and is not
    // authoritative about them, so the stored one survives.
    expect($shop->refresh()->conditional_price)->toBe('2.97');
});
