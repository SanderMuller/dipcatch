<?php declare(strict_types=1);

use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\ProductCheapestHistory;
use App\Models\Shop;
use App\Support\UrlNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;

test('product hasMany offers', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->count(3)->for($product)->create();

    expect($product->shops)->toHaveCount(3)
        ->and($product->shops->first())->toBeInstanceOf(Shop::class);
});

test('offer creating event sets url_hash and host', function (): void {
    $shop = Shop::factory()->create([
        'url' => 'https://WWW.Example.COM/p/1?utm_source=foo&a=2&a=1',
    ]);

    $normalized = UrlNormalizer::normalize('https://www.example.com/p/1?a=1&a=2');

    expect($shop->url_hash)->toBe(UrlNormalizer::hash($normalized))
        ->and($shop->host)->toBe('example.com');
});

test('offer has many price checks', function (): void {
    $shop = Shop::factory()->create();
    PriceCheck::factory()->count(2)->for($shop)->create();

    expect($shop->priceChecks)->toHaveCount(2);
});

test('product hasMany cheapest history', function (): void {
    $product = Product::factory()->create();
    ProductCheapestHistory::factory()->count(2)->for($product)->create();

    expect($product->cheapestHistory)->toHaveCount(2);
});

test('unique constraint on (product_id, url_hash) prevents duplicate offers per product', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['url' => 'https://example.com/p/1']);

    expect(fn () => Shop::factory()->for($product)->create(['url' => 'https://example.com/p/1?utm_source=x']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('same normalized URL on two different products is allowed (dedupe is per-product)', function (): void {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    Shop::factory()->for($a)->create(['url' => 'https://example.com/p/1']);
    $second = Shop::factory()->for($b)->create(['url' => 'https://example.com/p/1']);

    expect($second->exists)->toBeTrue();
});
