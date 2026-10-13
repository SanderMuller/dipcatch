<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Support\UrlNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;

test('offer creating event sets url_hash and host', function (): void {
    $shop = Shop::factory()->create([
        'url' => 'https://WWW.Example.COM/p/1?utm_source=foo&a=2&a=1',
    ]);

    $normalized = UrlNormalizer::normalize('https://www.example.com/p/1?a=1&a=2');

    expect($shop->url_hash)->toBe(UrlNormalizer::hash($normalized))
        ->and($shop->host)->toBe('example.com');
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
