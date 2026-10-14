<?php declare(strict_types=1);

use App\Models\Shop;
use App\Support\FilterSuggestions;
use App\Support\ShopNames;

test('a shop is named after its host, and a subdomain after its shop', function (string $host, ?string $name): void {
    expect(ShopNames::of($host))->toBe($name);
})->with([
    'the host itself' => ['jumbo.com', 'Jumbo'],
    'a subdomain' => ['webwinkel.poiesz-supermarkten.nl', 'Poiesz'],
    'any case' => ['WWW.Jumbo.com', 'Jumbo'],
    'a country domain stays its own shop' => ['amazon.co.uk', 'Amazon.co.uk'],
    'an unknown shop' => ['unknown-shop.test', null],
    'a suffix of a known name is not that shop' => ['notjumbo.com', null],
]);

test('a shop without a known name keeps its host', function (): void {
    expect(Shop::factory()->make(['host' => 'unknown-shop.test'])->displayName())->toBe('unknown-shop.test')
        ->and(Shop::factory()->make(['host' => 'www.jumbo.com'])->displayName())->toBe('Jumbo');
});

test('a search suggestion finds a shop on a subdomain by its name', function (): void {
    $suggestions = FilterSuggestions::for('albert', ['offers.ah.nl'], [], []);

    expect($suggestions)->toContain(['kind' => 'shop', 'key' => 'offers.ah.nl', 'label' => 'Albert Heijn']);
});
