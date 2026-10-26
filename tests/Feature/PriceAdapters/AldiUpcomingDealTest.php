<?php declare(strict_types=1);

use App\Jobs\CheckShopPrice;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
});

test('a check stores next campaign\'s lower price as an upcoming deal and shows it', function (): void {
    $url = 'https://www.aldi.nl/product/granola-91244024.html';
    Http::fake([
        'https://www.aldi.nl/robots.txt' => Http::response('', 404),
        $url => Http::response(aldiPage(price: '1.15', promotionPrices: [aldiPromotionPrice('0.85', strikePrice: '1.15', promoText: '-26%')])),
    ]);
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create(['url' => $url, 'currency' => 'EUR']);

    dispatch_sync(new CheckShopPrice($shop));
    $shop->refresh();

    expect((string) $shop->current_price)->toBe('1.15')
        ->and($shop->promotionWindow()?->hasNotStarted())->toBeTrue()
        ->and(Blade::render('<x-shop-deal :shop="$shop" :show-source="false" />', ['shop' => $shop]))
        ->toContain('Upcoming deal')
        ->toContain('-26%');
});

test('a check clears the period a regular Aldi price stored as a deal', function (): void {
    $url = 'https://www.aldi.nl/product/granola-91244024.html';
    Http::fake([
        'https://www.aldi.nl/robots.txt' => Http::response('', 404),
        $url => Http::response(aldiPage(price: '1.15')),
    ]);
    $shop = Shop::factory()->for(Product::factory()->create(['currency' => 'EUR']))->create([
        'url' => $url,
        'currency' => 'EUR',
        'current_price' => '1.15',
        'promotion_ends_at' => now()->addDays(2),
    ]);

    dispatch_sync(new CheckShopPrice($shop));

    expect($shop->refresh()->promotionWindow())->toBeNull();
});
