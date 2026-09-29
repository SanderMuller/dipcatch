<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Support\UrlNormalizer;

it('reports without writing unless asked, then rewrites a URL to the current normalizer', function (): void {
    $shop = Shop::factory()->create(['url' => 'https://shop.test/p/1']);
    // A row stored before srsltid was a known tracking parameter.
    $shop->forceFill(['url' => 'https://shop.test/p/1?srsltid=abc', 'url_hash' => 'stale'])->save();

    $this->artisan('dipcatch:renormalize-shop-urls')->expectsOutputToContain('Would rewrite 1 shop URL(s).')->assertSuccessful();
    expect($shop->refresh()->url)->toBe('https://shop.test/p/1?srsltid=abc');

    $this->artisan('dipcatch:renormalize-shop-urls --apply')->expectsOutputToContain('Rewrote 1 shop URL(s).')->assertSuccessful();
    expect($shop->refresh()->url)->toBe('https://shop.test/p/1')
        ->and($shop->url_hash)->toBe(UrlNormalizer::hash('https://shop.test/p/1'));
});

it('leaves two rows of one product that would collapse into one address alone', function (): void {
    $product = Product::factory()->create();
    Shop::factory()->for($product)->create(['url' => 'https://shop.test/p/1']);
    $duplicate = Shop::factory()->for($product)->create(['url' => 'https://shop.test/p/2']);
    $duplicate->forceFill(['url' => 'https://shop.test/p/1?srsltid=abc', 'url_hash' => 'stale'])->save();

    $this->artisan('dipcatch:renormalize-shop-urls --apply')->expectsOutputToContain('would collide')->assertSuccessful();

    expect($duplicate->refresh()->url)->toBe('https://shop.test/p/1?srsltid=abc');
});
