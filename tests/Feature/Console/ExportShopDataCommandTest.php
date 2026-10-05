<?php declare(strict_types=1);

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

test('the hosts dataset prints one CSV row per host without owner data', function (): void {
    $owner = User::factory()->create(['email' => 'jane.doe@example.test']);
    Shop::factory()->create([
        'product_id' => Product::factory()->for($owner),
        'url' => 'https://winkel.example.de/p/kaffee',
    ]);

    $this->artisan('dipcatch:export-shop-data', ['dataset' => 'hosts'])
        ->expectsOutputToContain('host,tld,support,offers')
        ->expectsOutputToContain('winkel.example.de,de,generic,1,1,1,0,1,0,0,')
        ->doesntExpectOutputToContain('jane.doe@example.test')
        ->assertSuccessful();
});

test('the urls dataset prints each page without its query string', function (): void {
    Shop::factory()->create(['url' => 'https://winkel.example.de/p/kaffee?ref=jane123']);

    $this->artisan('dipcatch:export-shop-data', ['dataset' => 'urls'])
        ->expectsOutputToContain('winkel.example.de,https://winkel.example.de/p/kaffee,1,tracked,ok,ok')
        ->doesntExpectOutputToContain('jane123')
        ->assertSuccessful();
});

test('an unknown dataset is refused', function (): void {
    $this->artisan('dipcatch:export-shop-data', ['dataset' => 'users'])
        ->expectsOutputToContain('Unknown dataset')
        ->assertExitCode(2);
});
