<?php declare(strict_types=1);

use App\Enums\ShopHealth;
use App\Models\Shop;

it('counts pages several rows track and the parameters stored URLs carry, and writes nothing', function (): void {
    Shop::factory()->create(['url' => 'https://shop.test/p/beans?variant=2']);
    Shop::factory()->create(['url' => 'https://shop.test/p/beans?variant=2']);
    Shop::factory()->create(['url' => 'https://shop.test/p/rice']);
    // Reads the page its own way: fetches today and would with sharing too.
    Shop::factory()->create(['url' => 'https://shop.test/p/beans?variant=2', 'price_selector' => '.price']);
    // Never checked, so no fetch to count.
    Shop::factory()->create(['url' => 'https://shop.test/p/gone', 'health' => ShopHealth::Dead]);

    $this->artisan('dipcatch:shop-url-report')
        ->expectsTable(['measure', 'value'], [
            ['recheck interval, free / pro (hours)', '24 / 6'],
            ['active tracked rows', 4],
            ['pages fetched (shareable key)', 2],
            ['page fetches a day from rows with own selectors', 1],
            ['pages tracked by 2+ rows', 1],
            ['page fetches a day now', 4],
            ['page fetches a day with sharing', 3],
        ])
        ->expectsTable(['query parameter', 'rows'], [['variant', 3]])
        ->assertSuccessful();

    expect(Shop::query()->count())->toBe(5);
});
